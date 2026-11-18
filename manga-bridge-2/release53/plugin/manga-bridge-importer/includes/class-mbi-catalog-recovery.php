<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Recupera publicações ainda existentes no banco sem consultar a origem. */
final class MBI_Catalog_Recovery {
	private const OPTION = 'mbi_catalog_restore_session';
	private MBI_Importer $importer;
	private MBI_DB $db;

	public function __construct( MBI_Importer $importer, MBI_DB $db ) {
		$this->importer = $importer;
		$this->db = $db;
	}

	public function counts(): array {
		$mangas = wp_count_posts( 'mbi_manga' );
		$chapters = wp_count_posts( 'mbi_chapter' );
		return array(
			'manga_published' => (int) ( $mangas->publish ?? 0 ),
			'manga_draft' => (int) ( $mangas->draft ?? 0 ),
			'manga_trash' => (int) ( $mangas->trash ?? 0 ),
			'chapter_published' => (int) ( $chapters->publish ?? 0 ),
			'chapter_draft' => (int) ( $chapters->draft ?? 0 ),
			'chapter_trash' => (int) ( $chapters->trash ?? 0 ),
		);
	}

	public function candidates( int $page = 1 ): array {
		$query = new WP_Query( array(
			'post_type' => 'mbi_manga',
			'post_status' => 'draft',
			'posts_per_page' => 50,
			'paged' => max( 1, $page ),
			'fields' => 'ids',
			'orderby' => 'ID',
			'order' => 'DESC',
			'meta_query' => array( array( 'key' => '_mbi_target_status', 'value' => 'publish' ) ),
		) );
		$rows = array();
		foreach ( $query->posts as $id ) {
			$id = (int) $id;
			$counts = $this->chapter_counts( $id );
			$rows[] = array(
				'id' => $id,
				'title' => get_the_title( $id ),
				'proof' => (bool) get_post_meta( $id, '_mbi_serial_completed_batch', true ) || '1' === (string) get_post_meta( $id, '_mbi_featured', true ),
				'review' => 'review' === (string) get_post_meta( $id, '_mbi_validation_status', true ),
				'chapters' => $counts,
				'eligible' => $this->manga_has_content( $id ) && $counts['total'] > 0,
			);
		}
		wp_reset_postdata();
		return array( 'rows' => $rows, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages );
	}

	private function chapter_counts( int $manga_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_status, COUNT(*) AS amount FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'mbi_chapter' AND post_status IN ('draft', 'publish') GROUP BY post_status",
			$manga_id
		) );
		$counts = array( 'published' => 0, 'draft' => 0, 'total' => 0 );
		foreach ( $rows as $row ) {
			$counts[ 'publish' === $row->post_status ? 'published' : 'draft' ] = (int) $row->amount;
		}
		$counts['total'] = $counts['published'] + $counts['draft'];
		return $counts;
	}

	private function manga_has_content( int $manga_id ): bool {
		$cover = (string) get_post_meta( $manga_id, '_mbi_cover_source_url', true );
		if ( ! $cover ) {
			$cover = (string) get_post_meta( $manga_id, '_mbi_cover_original_url', true );
		}
		$parts = wp_parse_url( $cover );
		return (bool) get_the_title( $manga_id )
			&& (bool) trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $manga_id ) ) )
			&& is_array( $parts ) && in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& ! empty( $parts['host'] );
	}

	public function state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	public function start( array $ids ): array {
		if ( ! $this->importer->is_paused() ) {
			return array( 'error' => 'Pause a importação antes de iniciar a restauração.' );
		}
		$active = $this->state();
		if ( ! empty( $active['active'] ) ) {
			return array( 'error' => 'Uma restauração já está em andamento.' );
		}
		$worker_lock = get_option( 'mbi_worker_lock', array() );
		if ( is_array( $worker_lock ) && (int) ( $worker_lock['time'] ?? 0 ) > time() - 120 ) {
			return array( 'error' => 'O processador ainda está ativo. Aguarde até dois minutos após pausar a importação.' );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids || count( $ids ) > 50 ) {
			return array( 'error' => 'Selecione entre 1 e 50 obras desta página.' );
		}
		foreach ( $ids as $id ) {
			if ( 'mbi_manga' !== get_post_type( $id ) || 'draft' !== get_post_status( $id ) || 'publish' !== (string) get_post_meta( $id, '_mbi_target_status', true ) || ! $this->manga_has_content( $id ) || 0 === $this->chapter_counts( $id )['total'] ) {
				return array( 'error' => 'A obra ' . $id . ' não atende aos critérios de recuperação. Nenhuma obra foi alterada.' );
			}
		}
		$state = array(
			'active' => 1,
			'run' => wp_generate_uuid4(),
			'ids' => $ids,
			'index' => 0,
			'cursor' => 0,
			'current_draft_total' => $this->chapter_counts( $ids[0] )['draft'],
			'current_draft_done' => 0,
			'percent' => 0,
			'chapters_restored' => 0,
			'mangas_restored' => 0,
			'chapters_skipped' => 0,
			'mangas_skipped' => 0,
			'errors' => array(),
			'started_at' => current_time( 'mysql' ),
		);
		update_option( self::OPTION, $state, false );
		$this->db->log( 'warning', 'Restauração manual do catálogo iniciada sem requisições à origem.', array( 'run' => $state['run'], 'ids' => $ids ) );
		return $state;
	}

	public function tick(): array {
		$lock = (int) get_option( 'mbi_catalog_restore_lock', 0 );
		if ( $lock && $lock < time() - 90 ) {
			delete_option( 'mbi_catalog_restore_lock' );
		}
		if ( ! add_option( 'mbi_catalog_restore_lock', time(), '', false ) ) {
			return $this->state();
		}
		try {
			return $this->tick_locked();
		} finally {
			delete_option( 'mbi_catalog_restore_lock' );
		}
	}

	private function tick_locked(): array {
		global $wpdb;
		$state = $this->state();
		if ( empty( $state['active'] ) ) {
			return $state;
		}
		if ( ! $this->importer->is_paused() ) {
			$state['errors'][] = 'Importação retomada; restauração interrompida para evitar concorrência.';
			$state['active'] = 0;
			update_option( self::OPTION, $state, false );
			return $state;
		}
		$manga_id = (int) $state['ids'][ (int) $state['index'] ];
		if ( 'mbi_manga' !== get_post_type( $manga_id ) || ! in_array( get_post_status( $manga_id ), array( 'draft', 'publish' ), true ) ) {
			$state['mangas_skipped']++;
			$state['errors'][] = 'Obra ' . $manga_id . ': registro alterado ou indisponível.';
			return $this->advance( $state );
		}
		$chapter_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_parent = %d AND post_type = 'mbi_chapter' AND post_status = 'draft' ORDER BY ID ASC LIMIT 30",
			(int) $state['cursor'], $manga_id
		) );
		if ( $chapter_ids ) {
			foreach ( $chapter_ids as $chapter_id ) {
				$chapter_id = (int) $chapter_id;
				$state['cursor'] = $chapter_id;
				$state['current_draft_done']++;
				if ( 'publish' !== (string) get_post_meta( $chapter_id, '_mbi_target_status', true ) || ! $this->chapter_has_pages( $chapter_id ) ) {
					$state['chapters_skipped']++;
					continue;
				}
				$result = wp_update_post( array( 'ID' => $chapter_id, 'post_status' => 'publish' ), true );
				if ( is_wp_error( $result ) || 'publish' !== get_post_status( $chapter_id ) ) {
					$state['chapters_skipped']++;
					$state['errors'][] = 'Capítulo ' . $chapter_id . ': publicação não confirmada.';
					continue;
				}
				update_post_meta( $chapter_id, '_mbi_catalog_restore_run', $state['run'] );
				$state['chapters_restored']++;
			}
			$state['percent'] = min( 99, (int) floor( 100 * ( $state['index'] + min( 1, $state['current_draft_done'] / max( 1, $state['current_draft_total'] ) ) ) / count( $state['ids'] ) ) );
			update_option( self::OPTION, $state, false );
			return $state;
		}
		if ( 'draft' === get_post_status( $manga_id ) ) {
			$counts = $this->chapter_counts( $manga_id );
			if ( $counts['published'] > 0 && $this->manga_has_content( $manga_id ) ) {
				$result = wp_update_post( array( 'ID' => $manga_id, 'post_status' => 'publish' ), true );
				if ( ! is_wp_error( $result ) && 'publish' === get_post_status( $manga_id ) ) {
					update_post_meta( $manga_id, '_mbi_ready', 1 );
					update_post_meta( $manga_id, '_mbi_catalog_restore_run', $state['run'] );
					$state['mangas_restored']++;
				} else {
					$state['mangas_skipped']++;
					$state['errors'][] = 'Obra ' . $manga_id . ': publicação não confirmada.';
				}
			} else {
				$state['mangas_skipped']++;
				$state['errors'][] = 'Obra ' . $manga_id . ': nenhuma capa, sinopse ou capítulo legível; mantida em rascunho.';
			}
		}
		return $this->advance( $state );
	}

	private function chapter_has_pages( int $id ): bool {
		foreach ( array( '_mbi_chapter_page_urls', '_mbi_chapter_page_ids' ) as $key ) {
			$pages = get_post_meta( $id, $key, true );
			if ( is_array( $pages ) && count( array_filter( $pages ) ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	private function advance( array $state ): array {
		$state['index']++;
		$state['cursor'] = 0;
		$state['current_draft_done'] = 0;
		$state['percent'] = (int) floor( 100 * $state['index'] / count( $state['ids'] ) );
		if ( $state['index'] < count( $state['ids'] ) ) {
			$state['current_draft_total'] = $this->chapter_counts( (int) $state['ids'][ $state['index'] ] )['draft'];
		}
		if ( $state['index'] >= count( $state['ids'] ) ) {
			$state['active'] = 0;
			$state['finished_at'] = current_time( 'mysql' );
			$this->db->log( 'warning', 'Restauração manual do catálogo concluída.', array( 'run' => $state['run'], 'mangas' => $state['mangas_restored'], 'chapters' => $state['chapters_restored'], 'skipped' => $state['mangas_skipped'] ) );
		}
		update_option( self::OPTION, $state, false );
		return $state;
	}
}
