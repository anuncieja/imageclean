<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MBI_DB {
	private string $queue_table;
	private string $log_table;
	private float $last_stale_release = 0;
	private int $last_stale_age = 0;

	public function __construct() {
		global $wpdb;
		$this->queue_table = $wpdb->prefix . 'mbi_queue';
		$this->log_table   = $wpdb->prefix . 'mbi_logs';
	}

	public function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$this->queue_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_type varchar(32) NOT NULL,
			unique_key char(32) NOT NULL,
			payload longtext NOT NULL,
			queue_class varchar(16) NOT NULL DEFAULT 'normal',
			batch_id varchar(64) NOT NULL DEFAULT '',
			manga_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			available_at datetime NOT NULL,
			locked_at datetime NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_job (unique_key),
			KEY status_available (status, available_at),
			KEY class_status_available (queue_class, status, available_at),
			KEY batch_status_available (batch_id, status, available_at),
			KEY batch_manga_job (batch_id, manga_id, job_type, status),
			KEY locked_at (locked_at)
		) {$charset};";

		$logs = "CREATE TABLE {$this->log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(16) NOT NULL,
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level_created (level, created_at)
		) {$charset};";

		dbDelta( $sql );
		dbDelta( $logs );

		add_option(
			'mbi_settings',
			array(
				'source_url'       => 'https://mangalivre.blog',
				'allowed_hosts'    => "mangalivre.blog\nwww.mangalivre.blog\nmangalivre.to\nwww.mangalivre.to",
				'daily_pages'      => 5,
				'jobs_per_run'     => 5,
				'max_attempts'     => 5,
				'convert_webp'     => 1,
				'webp_quality'     => 84,
				'publish_status'   => 'publish',
				'max_catalog_pages'=> 80,
				'max_images_chapter' => 250,
			),
			'',
			false
		);
	}


	private function queue_index_fields( string $job_type, array $payload ): array {
		$queue_class = 'normal';
		$batch_id    = '';
		if ( ! empty( $payload['manual_update'] ) ) {
			$queue_class = 'manual';
			$batch_id    = sanitize_text_field( (string) ( $payload['manual_update_batch'] ?? '' ) );
		} elseif ( in_array( $job_type, array( 'cover_repair', 'chapter_page_repair' ), true ) ) {
			$queue_class = 'repair';
		} elseif ( ! empty( $payload['serial_batch'] ) ) {
			$queue_class = 'serial';
			$batch_id    = sanitize_text_field( (string) $payload['serial_batch'] );
		}
		return array(
			'queue_class' => $queue_class,
			'batch_id'    => $batch_id,
			'manga_id'    => absint( $payload['manga_id'] ?? 0 ),
		);
	}

	public function queue( string $job_type, array $payload, bool $force = false ): int {
		global $wpdb;
		$job_type   = sanitize_key( $job_type );
		$url        = isset( $payload['url'] ) ? esc_url_raw( $payload['url'] ) : '';
		$identity   = isset( $payload['dedupe'] ) ? (string) $payload['dedupe'] : $url;
		$unique_key = md5( $job_type . '|' . $identity );
		$now        = current_time( 'mysql', true );
		$index      = $this->queue_index_fields( $job_type, $payload );
		$encoded    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$existing   = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status FROM {$this->queue_table} WHERE unique_key = %s", $unique_key )
		);

		if ( $existing ) {
			if ( $force && in_array( $existing->status, array( 'completed', 'failed' ), true ) ) {
				$wpdb->update(
					$this->queue_table,
					array(
						'payload'      => $encoded,
						'queue_class'  => $index['queue_class'],
						'batch_id'     => $index['batch_id'],
						'manga_id'     => $index['manga_id'],
						'status'       => 'pending',
						'attempts'     => 0,
						'available_at' => $now,
						'locked_at'    => null,
						'last_error'   => null,
						'updated_at'   => $now,
					),
					array( 'id' => (int) $existing->id )
				);
			}
			return (int) $existing->id;
		}

		$inserted = $wpdb->insert(
			$this->queue_table,
			array(
				'job_type'     => $job_type,
				'unique_key'   => $unique_key,
				'payload'      => $encoded,
				'queue_class'  => $index['queue_class'],
				'batch_id'     => $index['batch_id'],
				'manga_id'     => $index['manga_id'],
				'status'       => 'pending',
				'attempts'     => 0,
				'available_at' => $now,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}



	/**
	 * Insere muitos jobs do mesmo tipo em poucos round-trips SQL.
	 * Obras longas (milhares de capítulos) deixavam o PHP fazer SELECT+INSERT
	 * individual para cada capítulo. Em lotes de 120 isso cai para poucos UPSERTs.
	 */
	public function queue_many( string $job_type, array $payloads, bool $force = false ): int {
		global $wpdb;
		$job_type = sanitize_key( $job_type );
		$payloads = array_values( array_filter( $payloads, 'is_array' ) );
		if ( ! $job_type || ! $payloads ) {
			return 0;
		}

		$accepted = 0;
		foreach ( array_chunk( $payloads, 120 ) as $chunk ) {
			$now      = current_time( 'mysql', true );
			$values   = array();
			$args     = array();
			$fallback = array();
			foreach ( $chunk as $payload ) {
				$url        = isset( $payload['url'] ) ? esc_url_raw( (string) $payload['url'] ) : '';
				$identity   = isset( $payload['dedupe'] ) ? (string) $payload['dedupe'] : $url;
				$unique_key = md5( $job_type . '|' . $identity );
				$index      = $this->queue_index_fields( $job_type, $payload );
				$encoded    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( false === $encoded ) {
					$fallback[] = $payload;
					continue;
				}
				$values[] = '(%s,%s,%s,%s,%s,%d,%s,%d,%s,%s,%s)';
				array_push(
					$args,
					$job_type,
					$unique_key,
					$encoded,
					$index['queue_class'],
					$index['batch_id'],
					$index['manga_id'],
					'pending',
					0,
					$now,
					$now,
					$now
				);
			}

			if ( $values ) {
				$sql = "INSERT INTO {$this->queue_table}
					(job_type, unique_key, payload, queue_class, batch_id, manga_id, status, attempts, available_at, created_at, updated_at)
					VALUES " . implode( ',', $values );
				if ( $force ) {
					// status fica por último: as condições anteriores ainda enxergam o estado original.
					$sql .= " ON DUPLICATE KEY UPDATE
						payload = IF(status IN ('completed','failed'), VALUES(payload), payload),
						queue_class = IF(status IN ('completed','failed'), VALUES(queue_class), queue_class),
						batch_id = IF(status IN ('completed','failed'), VALUES(batch_id), batch_id),
						manga_id = IF(status IN ('completed','failed'), VALUES(manga_id), manga_id),
						attempts = IF(status IN ('completed','failed'), 0, attempts),
						available_at = IF(status IN ('completed','failed'), VALUES(available_at), available_at),
						locked_at = IF(status IN ('completed','failed'), NULL, locked_at),
						last_error = IF(status IN ('completed','failed'), NULL, last_error),
						updated_at = IF(status IN ('completed','failed'), VALUES(updated_at), updated_at),
						status = IF(status IN ('completed','failed'), 'pending', status)";
				} else {
					$sql .= ' ON DUPLICATE KEY UPDATE id = id';
				}
				$prepared = $wpdb->prepare( $sql, ...$args );
				$result   = $wpdb->query( $prepared );
				if ( false === $result ) {
					// Host/versão SQL atípica: mantém compatibilidade funcional, só neste chunk.
					$fallback = $chunk;
				} else {
					$accepted += count( $values );
				}
			}

			if ( $fallback ) {
				foreach ( $fallback as $payload ) {
					if ( $this->queue( $job_type, $payload, $force ) ) {
						$accepted++;
					}
				}
			}
		}
		return $accepted;
	}

	public function claim_next( string $serial_batch_id = '' ): ?object {
		global $wpdb;
		$this->release_stale_jobs();
		$now = current_time( 'mysql', true );

		$job = $this->focused_job( $serial_batch_id );
		if ( $job && strtotime( (string) $job->available_at . ' UTC' ) > time() ) { return null; }
		if ( ! $job && $serial_batch_id ) {
			$job = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->queue_table}
					 WHERE status = 'pending'
					   AND available_at <= %s
					   AND (
					   	queue_class IN ('manual','repair')
					   	OR (queue_class = 'serial' AND batch_id = %s)
					   )
					 ORDER BY CASE queue_class
					   WHEN 'manual' THEN 0
					   WHEN 'repair' THEN 1
					   WHEN 'serial' THEN 2
					   ELSE 3 END,
					   CASE job_type
					   	WHEN 'chapter' THEN 0
					   	WHEN 'manga' THEN 1
					   	WHEN 'image' THEN 2
					   	ELSE 3 END,
					   id ASC
					 LIMIT 1",
					$now,
					$serial_batch_id
				)
			);

			// Compatibilidade temporária com jobs criados antes da v1.6.21.
			if ( ! $job ) {
				$needle = '%"serial_batch":"' . $wpdb->esc_like( $serial_batch_id ) . '"%';
				$job = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$this->queue_table}
						 WHERE status = 'pending'
						   AND available_at <= %s
						   AND queue_class = 'normal'
						   AND payload LIKE %s
						 ORDER BY id ASC LIMIT 1",
						$now,
						$needle
					)
				);
			}
		} elseif ( ! $job ) {
			$job = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->queue_table}
					 WHERE status = 'pending' AND available_at <= %s
					 ORDER BY CASE queue_class
					   WHEN 'manual' THEN 0
					   WHEN 'repair' THEN 1
					   WHEN 'serial' THEN 2
					   ELSE 3 END,
					   CASE job_type
					   	WHEN 'chapter' THEN 0
					   	WHEN 'manga' THEN 1
					   	WHEN 'image' THEN 2
					   	ELSE 3 END,
					   id ASC
					 LIMIT 1",
					$now
				)
			);
		}

		if ( ! $job ) {
			return null;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->queue_table}
				 SET status = 'processing', locked_at = %s, updated_at = %s
				 WHERE id = %d AND status = 'pending'",
				$now,
				$now,
				(int) $job->id
			)
		);
		if ( 1 !== $updated ) {
			return null;
		}

		$job->locked_at = $now;
		$job->payload = json_decode( $job->payload, true ) ?: array();
		return $job;
	}

	private function claim_rows( string $job_type, string $serial_batch_id, int $limit ): array {
		global $wpdb;
		$limit = max( 0, min( 12, $limit ) );
		if ( $limit < 1 ) {
			return array();
		}
		$this->release_stale_jobs();
		$now = current_time( 'mysql', true );

		if ( $serial_batch_id ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$this->queue_table}
				 WHERE status = 'pending'
				   AND job_type = %s
				   AND available_at <= %s
				   AND (
				   	(queue_class = 'serial' AND batch_id = %s)
				   	OR (job_type = 'chapter' AND queue_class = 'manual')
				   )
				 ORDER BY CASE queue_class WHEN 'manual' THEN 0 ELSE 1 END, id ASC
				 LIMIT %d",
				$job_type,
				$now,
				$serial_batch_id,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$this->queue_table}
				 WHERE status = 'pending' AND job_type = %s AND available_at <= %s
				 ORDER BY CASE queue_class WHEN 'manual' THEN 0 ELSE 1 END, id ASC
				 LIMIT %d",
				$job_type,
				$now,
				$limit
			);
		}

		$rows = $wpdb->get_results( $sql );
		$claimed = array();
		foreach ( (array) $rows as $row ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->queue_table}
					 SET status = 'processing', locked_at = %s, updated_at = %s
					 WHERE id = %d AND status = 'pending'",
					$now,
					$now,
					(int) $row->id
				)
			);
			if ( 1 !== $updated ) {
				continue;
			}
			$row->payload = json_decode( $row->payload, true ) ?: array();
			$claimed[] = $row;
		}
		return $claimed;
	}

	/** Reivindica imagens adicionais do mesmo lote. Jobs legados não fazem download. */
	public function claim_additional_images( string $serial_batch_id, int $limit ): array {
		return $this->claim_rows( 'image', $serial_batch_id, min( 6, $limit ) );
	}

	/** Reivindica capítulos adicionais mantendo poucas conexões físicas. */
	public function claim_additional_chapters( string $serial_batch_id, int $limit ): array {
		return $this->claim_rows( 'chapter', $serial_batch_id, min( 5, $limit ) );
	}

	/** Reivindica páginas de obras adicionais para pré-busca em paralelo seguro. */
	public function claim_additional_mangas( string $serial_batch_id, int $limit ): array {
		return $this->claim_rows( 'manga', $serial_batch_id, min( 3, $limit ) );
	}

	public function batch_work_counts( string $serial_batch_id ): array {
		global $wpdb;
		$out = array( 'pending' => 0, 'processing' => 0, 'failed' => 0 );
		if ( ! $serial_batch_id ) {
			return $out;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) total
				 FROM {$this->queue_table}
				 WHERE queue_class = 'serial' AND batch_id = %s
				   AND status IN ('pending','processing','failed')
				 GROUP BY status",
				$serial_batch_id
			)
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row->status ] ) ) {
				$out[ $row->status ] = (int) $row->total;
			}
		}
		return $out;
	}

	/**
	 * Conta somente os jobs da obra atual. Uma falha em uma obra anterior não pode
	 * bloquear a publicação das seguintes dentro do mesmo lote serial.
	 */
	public function batch_manga_work_counts( string $serial_batch_id, int $manga_id ): array {
		global $wpdb;
		$out = array( 'pending' => 0, 'processing' => 0, 'failed' => 0 );
		if ( ! $serial_batch_id || $manga_id < 1 ) {
			return $out;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) total
				 FROM {$this->queue_table}
				 WHERE queue_class = 'serial' AND batch_id = %s AND manga_id = %d
				   AND status IN ('pending','processing','failed')
				 GROUP BY status",
				$serial_batch_id,
				$manga_id
			)
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row->status ] ) ) {
				$out[ $row->status ] = (int) $row->total;
			}
		}
		return $out;
	}

	/** Encerra somente os jobs ainda abertos da obra irrecuperável e libera a próxima. */
	public function abandon_serial_manga_work( string $serial_batch_id, int $manga_id, int $except_job_id = 0 ): int {
		global $wpdb;
		if ( ! $serial_batch_id || $manga_id < 1 ) {
			return 0;
		}
		$except = $except_job_id > 0 ? $wpdb->prepare( ' AND id <> %d', $except_job_id ) : '';
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->queue_table}
				 WHERE queue_class = 'serial' AND batch_id = %s AND manga_id = %d
				   AND status IN ('pending','processing','failed'){$except}",
				$serial_batch_id,
				$manga_id
			)
		);
	}

	/** Contagem indexada do progresso dos capítulos de uma obra dentro do lote ativo. */
	/** Contadores rápidos de um lote manual sem carregar payloads. */
	public function manual_batch_counts( string $batch_id ): array {
		global $wpdb;
		$out = array_fill_keys( array( 'pending', 'processing', 'completed', 'failed' ), 0 );
		$batch_id = sanitize_text_field( $batch_id );
		if ( ! $batch_id ) {
			return $out;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) total FROM {$this->queue_table} WHERE queue_class = 'manual' AND batch_id = %s GROUP BY status",
				$batch_id
			)
		);
		foreach ( (array) $rows as $row ) {
			$status = sanitize_key( (string) $row->status );
			if ( isset( $out[ $status ] ) ) {
				$out[ $status ] = (int) $row->total;
			}
		}
		return $out;
	}

	/** Último lote manual que ainda possui trabalho executável. */
	public function latest_active_manual_batch(): array {
		global $wpdb;
		$batch_id = (string) $wpdb->get_var(
			"SELECT batch_id FROM {$this->queue_table} WHERE queue_class = 'manual' AND batch_id <> '' AND status IN ('pending','processing') ORDER BY id DESC LIMIT 1"
		);
		if ( ! $batch_id ) {
			return array( 'id' => '', 'counts' => array() );
		}
		return array( 'id' => $batch_id, 'counts' => $this->manual_batch_counts( $batch_id ) );
	}

	/** Chaves das atualizações manuais por status; usado uma vez no fechamento do lote. */
	public function manual_batch_update_keys( string $batch_id, string $status = 'completed' ): array {
		global $wpdb;
		$batch_id = sanitize_text_field( $batch_id );
		$status = sanitize_key( $status );
		if ( ! $batch_id || ! in_array( $status, array( 'pending', 'processing', 'completed', 'failed' ), true ) ) {
			return array();
		}
		$payloads = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT payload FROM {$this->queue_table} WHERE queue_class = 'manual' AND batch_id = %s AND status = %s",
				$batch_id,
				$status
			)
		);
		$keys = array();
		foreach ( (array) $payloads as $payload_json ) {
			$payload = json_decode( (string) $payload_json, true );
			$key = sanitize_key( (string) ( is_array( $payload ) ? ( $payload['manual_update_key'] ?? '' ) : '' ) );
			if ( $key ) {
				$keys[ $key ] = true;
			}
		}
		return array_keys( $keys );
	}

	public function batch_chapter_counts( string $serial_batch_id, int $manga_id = 0 ): array {
		global $wpdb;
		$out = array_fill_keys( array( 'pending', 'processing', 'completed', 'failed' ), 0 );
		if ( ! $serial_batch_id ) {
			return $out;
		}
		$where = "queue_class = 'serial' AND batch_id = %s AND job_type = 'chapter'";
		$args  = array( $serial_batch_id );
		if ( $manga_id > 0 ) {
			$where .= ' AND manga_id = %d';
			$args[] = absint( $manga_id );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) total FROM {$this->queue_table} WHERE {$where} GROUP BY status",
				...$args
			)
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row->status ] ) ) {
				$out[ $row->status ] = (int) $row->total;
			}
		}

		// Compatibilidade de progresso para um lote que já estava rodando no momento
		// da atualização. Esses poucos jobs podem ter batch_id preenchido pelo upgrade,
		// mas manga_id ainda em zero. O LIKE fica restrito ao lote indexado e só roda
		// quando a consulta nova não encontrou nenhum capítulo da obra.
		if ( $manga_id > 0 && 0 === array_sum( $out ) ) {
			$needle = '%\"manga_id\":' . absint( $manga_id ) . '%';
			$legacy = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT status, COUNT(*) total
					 FROM {$this->queue_table}
					 WHERE queue_class = 'serial' AND batch_id = %s
					   AND manga_id = 0 AND job_type = 'chapter' AND payload LIKE %s
					 GROUP BY status",
					$serial_batch_id,
					$needle
				)
			);
			foreach ( (array) $legacy as $row ) {
				if ( isset( $out[ $row->status ] ) ) {
					$out[ $row->status ] = (int) $row->total;
				}
			}
		}
		return $out;
	}

	/**
	 * Preenche os índices das tarefas ativas criadas por versões antigas.
	 * Executa poucas atualizações SQL na troca de versão e evita payload LIKE no fluxo normal.
	 */
	public function migrate_active_queue_indexes( string $serial_batch_id = '', string $manual_batch_id = '' ): array {
		global $wpdb;
		$result = array( 'serial' => 0, 'manual' => 0, 'repair' => 0 );

		$result['repair'] = (int) $wpdb->query(
			"UPDATE {$this->queue_table}
			 SET queue_class = 'repair'
			 WHERE status IN ('pending','processing','failed')
			   AND job_type IN ('cover_repair','chapter_page_repair')
			   AND queue_class <> 'repair'"
		);

		if ( $serial_batch_id ) {
			$needle = '%"serial_batch":"' . $wpdb->esc_like( $serial_batch_id ) . '"%';
			$result['serial'] = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->queue_table}
					 SET queue_class = 'serial', batch_id = %s
					 WHERE status IN ('pending','processing','failed')
					   AND payload LIKE %s",
					$serial_batch_id,
					$needle
				)
			);
		}

		if ( $manual_batch_id ) {
			$needle = '%"manual_update_batch":"' . $wpdb->esc_like( $manual_batch_id ) . '"%';
			$result['manual'] = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->queue_table}
					 SET queue_class = 'manual', batch_id = %s
					 WHERE status IN ('pending','processing','failed')
					   AND payload LIKE %s",
					$manual_batch_id,
					$needle
				)
			);
		}
		return $result;
	}

	public function recover_unfinished_chapters_for_batch( string $serial_batch_id ): int {
		global $wpdb;
		if ( ! $serial_batch_id ) {
			return 0;
		}
		$now    = current_time( 'mysql', true );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 120 );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->queue_table}
				 SET status = 'pending', attempts = 0, available_at = %s, locked_at = NULL,
				     last_error = NULL, updated_at = %s
				 WHERE queue_class = 'serial' AND batch_id = %s
				   AND job_type = 'chapter'
				   AND (status IN ('pending','failed') OR (status = 'processing' AND locked_at < %s))",
				$now,
				$now,
				$serial_batch_id,
				$cutoff
			)
		);
	}

	public function recover_unfinished_manga_for_batch( string $serial_batch_id ): int {
		global $wpdb;
		if ( ! $serial_batch_id ) {
			return 0;
		}
		$now    = current_time( 'mysql', true );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 120 );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->queue_table}
				 SET status = 'pending', attempts = 0, available_at = %s, locked_at = NULL,
				     last_error = NULL, updated_at = %s
				 WHERE queue_class = 'serial' AND batch_id = %s
				   AND job_type = 'manga'
				   AND (status IN ('pending','failed') OR (status = 'processing' AND locked_at < %s))",
				$now,
				$now,
				$serial_batch_id,
				$cutoff
			)
		);
	}

	public function wake_fresh_pending_for_batch( string $serial_batch_id ): int {
		global $wpdb;
		if ( ! $serial_batch_id ) {
			return 0;
		}
		$now = current_time( 'mysql', true );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->queue_table}
				 SET available_at = %s, updated_at = %s
				 WHERE queue_class = 'serial' AND batch_id = %s
				   AND status = 'pending' AND attempts = 0 AND available_at > %s
				   AND (last_error IS NULL OR last_error = '')",
				$now,
				$now,
				$serial_batch_id,
				$now
			)
		);
	}

	public function clear_orphan_serial_pending( string $active_batch_id ): int {
		global $wpdb;
		if ( ! $active_batch_id ) {
			return 0;
		}
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->queue_table}
				 WHERE status = 'pending' AND queue_class = 'serial'
				   AND batch_id <> %s",
				$active_batch_id
			)
		);
	}

	public function has_due_jobs( string $serial_batch_id = '' ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		if ( $serial_batch_id ) {
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$this->queue_table}
					 WHERE status = 'pending' AND available_at <= %s
					   AND (
					   	queue_class IN ('manual','repair')
					   	OR (queue_class = 'serial' AND batch_id = %s)
					   )
					 LIMIT 1",
					$now,
					$serial_batch_id
				)
			);
		}
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$this->queue_table}
				 WHERE status = 'pending' AND available_at <= %s LIMIT 1",
				$now
			)
		);
	}

	/** Mesma seleção de classes do worker, sem carregar payloads/conteúdo. */
	public function next_pending_at( string $serial_batch_id = '' ): int {
		global $wpdb;
		$where = $serial_batch_id ? $wpdb->prepare(
			" AND (queue_class IN ('manual','repair') OR (queue_class = 'serial' AND batch_id = %s))",
			$serial_batch_id
		) : '';
		$date = $wpdb->get_var( "SELECT MIN(available_at) FROM {$this->queue_table} WHERE status = 'pending'{$where}" );
		return $date ? (int) strtotime( $date . ' UTC' ) : 0;
	}

	/** Migração única: antecipa só esperas externas legadas, sem apagar jobs. */
	public function migrate_legacy_backoff(): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->queue_table} SET available_at = %s
			 WHERE status = 'pending' AND available_at > %s
			 AND last_error REGEXP 'SOURCE_COOLDOWN|HTTP (408|425|500|502|503|504|52[0-4])|cURL error (6|7|28)|timed out'
			 AND last_error NOT LIKE '%%429%%'",
			gmdate( 'Y-m-d H:i:s', time() + 60 ), gmdate( 'Y-m-d H:i:s', time() + 60 )
		) );
	}

	public function purge_active_content_jobs_for_batch( string $serial_batch_id ): int {
		global $wpdb;
		if ( ! $serial_batch_id ) {
			return 0;
		}
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->queue_table}
				 WHERE queue_class = 'serial' AND batch_id = %s
				   AND status IN ('pending','processing','failed')
				   AND job_type IN ('manga','chapter','image')",
				$serial_batch_id
			)
		);
	}

	public function clear_pending_for_batch( string $serial_batch_id ): int {
		global $wpdb;
		if ( ! $serial_batch_id ) {
			return 0;
		}
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->queue_table}
				 WHERE queue_class = 'serial' AND batch_id = %s AND status = 'pending'",
				$serial_batch_id
			)
		);
	}

	public function complete( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->queue_table,
			array(
				'status'     => 'completed',
				'locked_at'  => null,
				'last_error' => null,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/** Persiste um payload corrigido sem alterar o estado/tentativas do job atual. */
	public function update_job_payload( int $id, array $payload ): void {
		global $wpdb;
		if ( $id < 1 ) {
			return;
		}
		$wpdb->update(
			$this->queue_table,
			array(
				'payload'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}


	/**
	 * Adia um job por indisponibilidade externa sem consumir tentativa.
	 * Usado pelo circuit breaker para evitar transformar uma queda 522 da origem
	 * em centenas de falhas locais.
	 */
	/** Fatal recovery may only touch the exact claim that was interrupted. */
	public function defer_interrupted( object $job, string $error ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->queue_table} SET status='pending', locked_at=NULL, available_at=%s, updated_at=%s, last_error=%s WHERE id=%d AND status='processing' AND locked_at=%s", gmdate( 'Y-m-d H:i:s', time() + 300 ), current_time( 'mysql', true ), substr( $error, 0, 4000 ), (int) $job->id, (string) ( $job->locked_at ?? '' ) ) );
	}

	/** A normal budget yield is not a network retry; retain the exact claim. */
	public function defer_budget( object $job, int $delay, string $reason, bool $focus = true ): void {
		global $wpdb;
		$delay = max( 2, min( 5, $delay ) );
		$generation = (string) get_option( 'mbi_process_generation', '' );
		$serial = (array) get_option( 'mbi_serial_batch', array() ); $batch = ! empty( $serial['active'] ) ? (string) ( $serial['id'] ?? '' ) : '';
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->queue_table} SET status='pending', available_at=%s, locked_at=NULL, last_error=%s, updated_at=%s WHERE id=%d AND status='processing' AND locked_at=%s",
			gmdate( 'Y-m-d H:i:s', time() + $delay ), substr( $reason, 0, 4000 ), current_time( 'mysql', true ), (int) $job->id, (string) ( $job->locked_at ?? '' )
		) );
		if ( 1 === $updated && $focus ) {
			update_option( 'mbi_validation_focus', array( 'id' => (int) $job->id, 'batch' => $batch, 'generation' => $generation, 'after' => time() + $delay, 'until' => time() + 60 ), false );
		}
	}
	/** Same run and batch only. An expired/cancelled focus cannot capture another queue. */
	private function focused_job( string $batch ): ?object {
		global $wpdb;
		wp_cache_delete( 'mbi_validation_focus', 'options' ); wp_cache_delete( 'mbi_process_generation', 'options' );
		$f = (array) get_option( 'mbi_validation_focus', array() );
		if ( ! $f ) { return null; }
		if ( ( $f['until'] ?? 0 ) <= time() || ( $f['generation'] ?? '' ) !== (string) get_option( 'mbi_process_generation', '' ) || ( $f['batch'] ?? '' ) !== $batch ) { delete_option( 'mbi_validation_focus' ); return null; }
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->queue_table} WHERE id=%d AND job_type='chapter' AND status='pending'", (int) $f['id'] ) );
		if ( ! $job || ( $batch && ! in_array( $job->queue_class ?? '', array( 'manual', 'repair' ), true ) && ( ( $job->queue_class ?? '' ) !== 'serial' || ( $job->batch_id ?? '' ) !== $batch ) ) ) { delete_option( 'mbi_validation_focus' ); return null; }
		return $job;
	}

	public function defer( object $job, int $delay, string $reason = '' ): void {
		global $wpdb;
		// Retry-After da origem pode ser maior que 30 minutos; não o antecipe.
		$delay = max( 30, $delay );
		$focus = (array) get_option( 'mbi_validation_focus', array() );
		if ( (int) ( $focus['id'] ?? 0 ) === (int) $job->id ) { delete_option( 'mbi_validation_focus' ); }
		$wpdb->update(
			$this->queue_table,
			array(
				'status'       => 'pending',
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'locked_at'    => null,
				'last_error'   => $reason ? ( function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, 4000 ) : substr( $reason, 0, 4000 ) ) : null,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $job->id )
		);
	}

	/**
	 * Na atualização do motor, retoma apenas falhas de disponibilidade da origem
	 * pertencentes ao lote serial ativo. Conteúdo concluído e erros de parsing não são tocados.
	 */
	public function requeue_origin_pressure_failures( string $batch_id, int $delay = 300 ): int {
		global $wpdb;
		$batch_id = sanitize_text_field( $batch_id );
		if ( ! $batch_id ) {
			return 0;
		}
		$delay = max( 60, min( 30 * MINUTE_IN_SECONDS, $delay ) );
		$available = gmdate( 'Y-m-d H:i:s', time() + $delay );
		$now = current_time( 'mysql', true );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->queue_table}
				 SET status = 'pending', available_at = %s, locked_at = NULL,
				     last_error = CONCAT('SOURCE_COOLDOWN: ', COALESCE(last_error,'')), updated_at = %s
				 WHERE status = 'failed'
				   AND batch_id = %s
				   AND last_error REGEXP 'HTTP (408|425|429|500|502|503|504|520|521|522|523|524)|cURL|timed out|Could not resolve host|getaddrinfo|connection (reset|refused|failed)' ",
				$available,
				$now,
				$batch_id
			)
		);
	}


	public function fail( object $job, string $error, int $max_attempts ): string {
		global $wpdb;
		$attempts = (int) $job->attempts + 1;
		$manual_update = ! empty( $job->payload['manual_update'] );
		$serial_job = ! empty( $job->payload['serial_batch'] );
		$network_job = in_array( (string) $job->job_type, array( 'chapter', 'manga', 'starter', 'catalog' ), true );
		$resolver_pressure = $network_job && (bool) preg_match( '/getaddrinfo\(\)\s+thread failed to start|cURL error 6|Could not resolve host|DNS resolver/iu', $error );
		$transient_network_error = $network_job && (bool) preg_match( '/cURL|curl|resolve|DNS|getaddrinfo|timed?\s*out|HTTP\s*(?:0|408|425|429|500|502|503|504|520|521|522|523|524)|connection\s+(?:reset|refused|failed)|failed\s+to\s+connect|SSL\s+(?:connect|read)|recv\s+failure|send\s+failure/iu', $error );
		$permanent_source_error = in_array( (string) $job->job_type, array( 'manga', 'chapter', 'cover_repair', 'chapter_page_repair' ), true )
			&& (bool) preg_match( '/HTTP\s*(?:404|410)\b|Nenhuma página de imagem|não retornou páginas válidas|Imagem da origem inválida.*HTTP\s*(?:200|206)\b/iu', $error );

		if ( $resolver_pressure ) {
			// Esse erro veio do próprio servidor: muitas resoluções/conexões simultâneas.
			// Muitas tentativas em paralelo só pioram o gargalo, então o motor entra em
			// modo de pressão e faz poucas retentativas sequenciais.
			set_transient( 'mbi_network_pressure', 1, 10 * MINUTE_IN_SECONDS );
			set_transient( 'mbi_chapter_network_backoff', 1, 10 * MINUTE_IN_SECONDS );
			$max_attempts = min( max( 4, $max_attempts ), 5 );
		} elseif ( $transient_network_error ) {
			$max_attempts = max( 'chapter' === (string) $job->job_type ? 8 : 6, $max_attempts );
		}

		// 1.7.40: 4 attempts exhausted temporary network failures too early.
		// Manual updates receive two additional bounded attempts before cron rescue.
		if ( $manual_update && $transient_network_error && ! $resolver_pressure ) { $max_attempts = max( 6, $max_attempts ); }
		elseif ( $serial_job && $transient_network_error && ! $resolver_pressure ) { $max_attempts = 4; }
		$retry = ! $permanent_source_error && $attempts < $max_attempts;
		if ( $resolver_pressure ) {
			$delay = min( 5 * MINUTE_IN_SECONDS, 60 * max( 1, $attempts ) );
		} elseif ( $transient_network_error && preg_match( '/HTTP\s*(?:429|520|521|522|523|524)\b/iu', $error ) ) {
			$delay = min( 30 * MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** max( 0, $attempts - 1 ) ) );
		} elseif ( $transient_network_error ) {
			$delay = min( 10 * MINUTE_IN_SECONDS, max( 60, ( 2 ** max( 0, $attempts - 1 ) ) * 60 ) );
		} elseif ( $serial_job || $manual_update ) {
			$delay = min( 60, max( 10, ( 2 ** max( 0, $attempts - 1 ) ) * 10 ) );
		} else {
			$delay = min( HOUR_IN_SECONDS * 6, ( 2 ** $attempts ) * 60 );
		}

		$wpdb->update(
			$this->queue_table,
			array(
				'status'       => $retry ? 'pending' : 'failed',
				'attempts'     => $attempts,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'locked_at'    => null,
				'last_error'   => function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 4000 ) : substr( $error, 0, 4000 ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $job->id )
		);

		$level = $retry ? 'warning' : 'error';
		$this->log( $level, "Falha no job {$job->job_type} #{$job->id}: {$error}", $job->payload );
		return $retry ? 'retrying' : 'failed';
	}

	public function latest_retry( string $batch_id = '', int $manga_id = 0 ): ?object {
		global $wpdb;
		$where = "status = 'pending' AND attempts > 0";
		if ( $batch_id !== '' ) { $where .= $wpdb->prepare( ' AND batch_id = %s', $batch_id ); }
		if ( $batch_id !== '' && $manga_id > 0 ) { $where .= $wpdb->prepare( ' AND manga_id = %d', $manga_id ); }
		return $wpdb->get_row( "SELECT id, job_type, attempts, available_at, last_error FROM {$this->queue_table} WHERE {$where} ORDER BY updated_at DESC LIMIT 1" );
	}

	/** Libera imediatamente apenas a próxima retentativa pendente, sem reiniciar jobs concluídos. */

	public function force_next_retry_now( string $serial_batch_id = '' ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		if ( $serial_batch_id ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->queue_table}
					 WHERE status = 'pending' AND attempts > 0
					   AND (
					   	queue_class IN ('manual','repair')
					   	OR (queue_class = 'serial' AND batch_id = %s)
					   )
					 ORDER BY available_at ASC, id ASC LIMIT 1",
					$serial_batch_id
				)
			);
		} else {
			$id = (int) $wpdb->get_var(
				"SELECT id FROM {$this->queue_table}
				 WHERE status = 'pending' AND attempts > 0
				 ORDER BY available_at ASC, id ASC LIMIT 1"
			);
		}
		if ( $id < 1 ) {
			return 0;
		}
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->queue_table}
				 SET available_at = %s, locked_at = NULL, updated_at = %s
				 WHERE id = %d AND status = 'pending'",
				$now,
				$now,
				$id
			)
		);
	}

	public function release_stale_jobs( int $max_age_seconds = 180 ): void {
		global $wpdb;
		$max_age_seconds = max( 120, min( 900, $max_age_seconds ) );
		if ( microtime( true ) - $this->last_stale_release < 5 && $max_age_seconds >= $this->last_stale_age ) { return; }
		$this->last_stale_release = microtime( true ); $this->last_stale_age = $max_age_seconds;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->queue_table} SET status = 'pending', locked_at = NULL, updated_at = %s WHERE status = 'processing' AND locked_at < %s",
				current_time( 'mysql', true ),
				$cutoff
			)
		);
	}



	/**
	 * Retoma somente starters que morreram por indisponibilidade transitória da origem.
	 * A v1.6.25 consegue pular páginas intermediárias instáveis, então essas falhas
	 * antigas podem ser tentadas uma vez sem reiniciar loops indefinidos.
	 */
	public function retry_transient_starter_failures(): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->queue_table}
				 SET status = 'pending', attempts = 0, available_at = %s, locked_at = NULL, updated_at = %s
				 WHERE status = 'failed'
				   AND job_type = 'starter'
				   AND (
					last_error REGEXP 'HTTP (408|425|429|500|502|503|504|520|521|522|523|524)'
					OR last_error LIKE '%%cURL%%'
					OR last_error LIKE '%%timed out%%'
					OR last_error LIKE '%%Could not resolve host%%'
					OR last_error LIKE '%%getaddrinfo%%'
				   )",
				$now,
				$now
			)
		);
	}


	/**
	 * Diagnóstico não destrutivo: apenas jobs da atualização manual selecionada.
	 * Erros de estrutura/identidade/imagem 404 permanecem para revisão, nunca são aprovados.
	 */
	private function manual_update_failure_kind( string $error ): string {
		if ( preg_match( '/cURL\s*error|timed?\s*out|timeout|tempo\s*(?:limite|esgotado)|HTTP\s*(?:0|408|425|429|500|502|503|504|520|521|522|523|524)\b|Could not resolve host|getaddrinfo|DNS resolver|connection\s+(?:reset|refused|failed)|failed\s+to\s+connect|SSL\s+(?:connect|read)|recv\s+failure|send\s+failure/iu', $error ) ) {
			return 'rede_temporaria';
		}
		if ( preg_match( '/N[uú]mero do cap[ií]tulo|cap[ií]tulo divergente|cap[ií]tulo difere|n[uú]mero n[aã]o|n[uú]mero.*(?:comprovado|divergente)/iu', $error ) ) {
			return 'numero_nao_comprovado';
		}
		if ( preg_match( '/VALIDACAO_IMAGENS|imagem|p[aá]gina|reader|proxy|worker|entrega|HTTP\s*(?:401|403|404|410)\b/iu', $error ) ) {
			return 'imagem_ou_origem';
		}
		return 'outros';
	}

	public function manual_failure_summary( string $batch_id ): array {
		global $wpdb;
		$summary = array( 'total' => 0, 'rede_temporaria' => 0, 'numero_nao_comprovado' => 0, 'imagem_ou_origem' => 0, 'outros' => 0 );
		$batch_id = sanitize_text_field( $batch_id );
		if ( '' === $batch_id ) { return $summary; }
		$summary['total'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->queue_table} WHERE queue_class='manual' AND batch_id=%s AND job_type='chapter' AND status='failed'", $batch_id
		) );
		$errors = $wpdb->get_col( $wpdb->prepare(
			"SELECT COALESCE(last_error,'') FROM {$this->queue_table} WHERE queue_class='manual' AND batch_id=%s AND job_type='chapter' AND status='failed' ORDER BY id DESC LIMIT 500", $batch_id
		) );
		foreach ( (array) $errors as $error ) { $summary[ $this->manual_update_failure_kind( (string) $error ) ]++; }
		$summary['not_examined'] = max( 0, $summary['total'] - count( (array) $errors ) );
		return $summary;
	}

	/**
	 * Reabre no máximo 12 jobs transitórios por clique. Nunca toca tarefas concluídas,
	 * listas estruturais, jobs seriais, reparos ou outra seleção de atualizações.
	 * 2 rodadas extras por job no máximo, mesmo após atualizar o plugin.
	 */
	public function retry_manual_network_failures( string $batch_id, int $limit = 12 ): array {
		global $wpdb;
		$batch_id = sanitize_text_field( $batch_id );
		$result = array( 'requeued' => 0, 'eligible' => 0, 'already_retried' => 0 );
		if ( '' === $batch_id ) { return $result; }
		$limit = max( 1, min( 12, $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, payload, last_error FROM {$this->queue_table} WHERE queue_class='manual' AND batch_id=%s AND job_type='chapter' AND status='failed' ORDER BY id ASC LIMIT 500", $batch_id
		) );
		foreach ( (array) $rows as $row ) {
			if ( 'rede_temporaria' !== $this->manual_update_failure_kind( (string) $row->last_error ) ) { continue; }
			$payload = json_decode( (string) $row->payload, true );
			if ( ! is_array( $payload ) || empty( $payload['manual_update'] ) || $batch_id !== (string) ( $payload['manual_update_batch'] ?? '' ) ) { continue; }
			$round = absint( $payload['_mbi_manual_rescue_round'] ?? 0 );
			if ( $round >= 2 ) { $result['already_retried']++; continue; }
			$result['eligible']++;
			if ( $result['requeued'] >= $limit ) { continue; }
			$payload['_mbi_manual_rescue_round'] = $round + 1;
			$now = current_time( 'mysql', true );
			// Conditional update: protects against parallel claims and repeat clicks.
			$changed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$this->queue_table} SET status='pending', attempts=0, payload=%s, available_at=%s, locked_at=NULL, updated_at=%s WHERE id=%d AND status='failed' AND queue_class='manual' AND batch_id=%s",
				wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				gmdate( 'Y-m-d H:i:s', time() + 30 + ( $result['requeued'] * 10 ) ),
				$now, (int) $row->id, $batch_id
			) );
			if ( 1 === (int) $changed ) { $result['requeued']++; }
		}
		return $result;
	}

	public function retry_failed(): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$serial_marker = '%"serial_batch":"%';
		$manual_marker = '%"manual_update":1%';
		$empty_chapter_error = '%Nenhuma página de imagem foi encontrada no capítulo%';
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->queue_table}
				 SET status = 'pending',
				     attempts = CASE
				       WHEN job_type = 'chapter' AND last_error LIKE %s THEN 1
				       ELSE 0
				     END,
				     last_error = NULL,
				     available_at = %s, locked_at = NULL, updated_at = %s
				 WHERE status = 'failed'
				   AND (
				   	queue_class IN ('serial','manual','repair')
				   	OR job_type = 'starter'
				   	OR payload LIKE %s
				   	OR payload LIKE %s
				   )",
				$empty_chapter_error,
				$now,
				$now,
				$serial_marker,
				$manual_marker
			)
		);
	}

	public function reset_import_retries(): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->queue_table} SET status = 'pending', attempts = 0, last_error = NULL, available_at = %s, locked_at = NULL, updated_at = %s WHERE status IN ('pending', 'failed') AND job_type IN ('starter', 'catalog', 'manga', 'chapter', 'image')",
				$now,
				$now
			)
		);
	}

	public function clear_pending_content_jobs(): int {
		global $wpdb;
		return (int) $wpdb->query(
			"DELETE FROM {$this->queue_table} WHERE status = 'pending' AND job_type IN ('starter', 'catalog', 'manga', 'chapter', 'image')"
		);
	}

	/** Remove pendências/falhas antigas de conteúdo antes de iniciar um lote serial isolado. */
	public function clear_unfinished_content_jobs(): int {
		global $wpdb;
		return (int) $wpdb->query(
			"DELETE FROM {$this->queue_table} WHERE status IN ('pending', 'failed') AND job_type IN ('starter', 'catalog', 'manga', 'chapter', 'image')"
		);
	}

	public function cancel_pending_jobs(): int {
		return $this->clear_pending_content_jobs();
	}


	/** Zera integralmente o motor de jobs sem tocar em posts, capítulos ou mídia. */
	public function purge_all_jobs(): int {
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->queue_table}" );
		$wpdb->query( "DELETE FROM {$this->queue_table}" );
		return $count;
	}


	/** Contagem de trabalho ativo que não pertence à descoberta de catálogo. */
	public function active_non_starter_counts(): array {
		global $wpdb;
		$out = array( 'pending' => 0, 'processing' => 0 );
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) total FROM {$this->queue_table} WHERE status IN ('pending','processing') AND job_type <> 'starter' GROUP BY status"
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row->status ] ) ) {
				$out[ $row->status ] = (int) $row->total;
			}
		}
		return $out;
	}

	/**
	 * Cancela somente jobs de descoberta do catálogo. Conteúdo já criado e qualquer
	 * lote serial de mangá/capítulos permanecem intocados.
	 */
	public function cancel_starter_jobs( string $reason = 'Carga de catálogo cancelada.' ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$reason = sanitize_text_field( $reason );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->queue_table}
				 SET status = 'completed', locked_at = NULL, last_error = %s, updated_at = %s
				 WHERE job_type = 'starter' AND status IN ('pending','processing')",
				$reason,
				$now
			)
		);
	}

	public function active_starter_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->queue_table} WHERE job_type = 'starter' AND status IN ('pending','processing')" );
	}

	public function counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) total FROM {$this->queue_table} GROUP BY status", OBJECT_K );
		$out  = array_fill_keys( array( 'pending', 'processing', 'completed', 'failed' ), 0 );

		foreach ( $rows as $status => $row ) {
			$out[ $status ] = (int) $row->total;
		}

		return $out;
	}

	/** A short display snapshot; control-flow methods always read the live queue. */
	public function dashboard_counts(): array {
		$cached = get_transient( 'mbi_queue_display46' );
		if ( is_array( $cached ) ) { return $cached; }
		$types = $this->type_counts();
		$counts = array_fill_keys( array( 'pending', 'processing', 'completed', 'failed' ), 0 );
		foreach ( $types as $statuses ) { foreach ( $statuses as $status => $total ) { if ( isset( $counts[$status] ) ) { $counts[$status] += (int) $total; } } }
		$out = array( 'counts' => $counts, 'types' => $types );
		set_transient( 'mbi_queue_display46', $out, 10 );
		return $out;
	}

	public function type_counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT job_type, status, COUNT(*) total FROM {$this->queue_table} GROUP BY job_type, status" );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ $row->job_type ][ $row->status ] = (int) $row->total;
		}
		return $out;
	}

	public function recent_jobs( int $limit = 30 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->queue_table} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	public function log( string $level, string $message, array $context = array() ): void {
		global $wpdb;
		$wpdb->insert(
			$this->log_table,
			array(
				'level'      => sanitize_key( $level ),
				'message'    => wp_strip_all_tags( $message ),
				'context'    => $context ? wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : null,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	public function recent_logs( int $limit = 30 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->log_table} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	public function error_logs( int $limit = 1000 ): array {
		global $wpdb;
		$limit = max( 1, min( 10000, $limit ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->log_table} WHERE level IN ('error','warning') ORDER BY id DESC LIMIT %d",
				$limit
			)
		);
	}

	public function error_log_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->log_table} WHERE level IN ('error','warning')" );
	}

	public function clear_completed(): int {
		global $wpdb;
		return (int) $wpdb->query( "DELETE FROM {$this->queue_table} WHERE status = 'completed'" );
	}
}
