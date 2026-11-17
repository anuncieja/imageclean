<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MBI_Skip_Exception extends RuntimeException {}

final class MBI_Source_Backoff_Exception extends RuntimeException {
	public int $delay;
	public string $source_host;

	public function __construct( string $message, string $source_host, int $delay ) {
		parent::__construct( $message );
		$this->source_host = $source_host;
		$this->delay = max( 30, $delay );
	}
}

final class MBI_Importer {
	private MBI_DB $db;
	private MBI_Parser $parser;
	private array $prefetched_html = array();
	private string $worker_lock_token = '';

	public function __construct( MBI_DB $db, MBI_Parser $parser ) {
		$this->db     = $db;
		$this->parser = $parser;
	}

	public function settings(): array {
		$defaults = array(
			'source_url'             => 'https://mangalivre.blog',
			'allowed_hosts'          => "mangalivre.blog\nwww.mangalivre.blog\nmangalivre.to\nwww.mangalivre.to",
			'daily_pages'            => 5, // compatibilidade com versões anteriores.
			'update_pages'           => 5,
			'updates_enabled'        => 0,
			'auto_catalog_enabled'   => 0,
			'auto_catalog_limit'     => 10,
			'processing_speed'       => 'ultra',
			'page_storage_mode'      => 'remote',
			'continuous_worker'      => 1,
			'jobs_per_run'           => 30,
			'max_attempts'           => 5,
			'convert_webp'           => 0,
			'webp_quality'           => 84,
			'publish_status'         => 'publish',
			'max_catalog_pages'      => 80,
			'max_images_chapter'     => 250,
			'image_proxy_enabled'     => 0,
			'image_proxy_base_url'    => 'https://img.lermangaonline.com.br',
			'image_proxy_key_b64'     => '',
		);
		return wp_parse_args( get_option( 'mbi_settings', array() ), $defaults );
	}

	private function speed_profile(): array {
		$speed = sanitize_key( (string) ( $this->settings()['processing_speed'] ?? 'ultra' ) );
		$profiles = array(
			'normal' => array( 'jobs' => 30,  'seconds' => 24, 'parallel' => 2, 'chapters_parallel' => 1, 'mangas_parallel' => 1 ),
			'fast'   => array( 'jobs' => 80,  'seconds' => 34, 'parallel' => 2, 'chapters_parallel' => 2, 'mangas_parallel' => 1 ),
			'turbo'  => array( 'jobs' => 140, 'seconds' => 40, 'parallel' => 2, 'chapters_parallel' => 2, 'mangas_parallel' => 1 ),
			// v1.6.26: velocidade sustentável. O gargalo observado no log foi a origem
			// respondendo 522 em rajadas; mais streams não aumentam throughput quando a
			// origem está saturada. Ultra mantém apenas duas leituras simultâneas por host.
			'ultra'  => array( 'jobs' => 220, 'seconds' => 42, 'parallel' => 2, 'chapters_parallel' => 2, 'mangas_parallel' => 1 ),
		);
		return $profiles[ $speed ] ?? $profiles['ultra'];
	}

	private function safe_chapter_parallelism( int $requested ): int {
		// Uma conexão contínua por origem é mais rápida na prática do que duas em
		// rajada seguidas por 429/522 e minutos de cooldown. O worker continua
		// processando vários capítulos na mesma execução, apenas sem martelar o host.
		return 1;
	}

	private function safe_manga_parallelism( int $requested ): int {
		$requested = max( 1, min( 1, $requested ) );
		return 1;
	}

	private function resolver_pressure_error( string $error ): bool {
		return (bool) preg_match( '/getaddrinfo\(\)\s+thread failed to start|cURL error 6|Could not resolve host|DNS resolver/iu', $error );
	}

	private function source_defect_error( string $error ): bool {
		return (bool) preg_match( '/HTTP\s*(?:404|410)\b|Nenhuma página de imagem|não (?:possui|retornou).*?(?:capa|sinopse|capítulos|páginas).*?válid|Imagem da origem inválida.*HTTP\s*(?:200|206)\b|SEM_IMAGENS_NA_ORIGEM/iu', $error );
	}

	/** Erros de disponibilidade/pressão da origem. Não são erro de parsing nem corrupção local. */
	private function origin_pressure_error( string $error ): bool {
		return (bool) preg_match(
			'/SOURCE_COOLDOWN|cURL|curl|resolve|DNS|getaddrinfo|timed?\s*out|HTTP\s*(?:0|408|425|429|500|502|503|504|520|521|522|523|524)|connection\s+(?:reset|refused|failed)|failed\s+to\s+connect|SSL\s+(?:connect|read)|recv\s+failure|send\s+failure/iu',
			$error
		);
	}

	private function source_host( string $url ): string {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	private function source_circuit_key( string $host ): string {
		return 'mbi_source_circuit_' . md5( strtolower( $host ) );
	}

	/** Segundos restantes do cooldown daquela origem. */
	private function source_circuit_remaining( string $url ): int {
		$host = $this->source_host( $url );
		if ( ! $host ) {
			return 0;
		}
		$state = get_transient( $this->source_circuit_key( $host ) );
		if ( ! is_array( $state ) ) {
			return 0;
		}
		if ( empty( $state['policy'] ) && ! preg_match( '/HTTP\s*429\b/i', (string) ( $state['last_error'] ?? '' ) ) ) {
			// Migra apenas a espera legada, sem retirar o bloqueio de rate limit.
			$state['until'] = min( (int) ( $state['until'] ?? 0 ), time() + 60 );
			$state['policy'] = 2;
			set_transient( $this->source_circuit_key( $host ), $state, 45 * MINUTE_IN_SECONDS );
		}
		$until = (int) ( $state['until'] ?? 0 );
		if ( $until <= time() ) {
			// Mantém o histórico por alguns minutos para que uma nova falha logo após
			// a reabertura aumente o cooldown. Um HTTP 200 limpa o estado.
			return 0;
		}
		return max( 1, $until - time() );
	}

	/** Abre circuit breaker por host; rajadas simultâneas contam como um único evento. */
	private function open_source_circuit( string $url, string $error, int $retry_after = 0 ): int {
		$host = $this->source_host( $url );
		if ( ! $host ) {
			return 180;
		}
		if ( preg_match( '/cURL error 28|timed?\s*out/iu', $error ) && ! preg_match( '/HTTP\s*(?:429|5\d\d)\b|DNS|getaddrinfo|resolve/iu', $error ) ) {
			$timeout_key = 'mbi_timeout_source49_' . md5( $host );
			$streak = (array) get_transient( $timeout_key );
			$count = ( $streak['at'] ?? 0 ) > time() - 90 ? (int) ( $streak['count'] ?? 0 ) + 1 : 1;
			set_transient( $timeout_key, array( 'count' => $count, 'at' => time() ), 90 );
			if ( $count < 3 && $retry_after <= 0 && $this->source_circuit_remaining( $url ) === 0 ) { return 0; }
		}
		$key = $this->source_circuit_key( $host );
		$now = time();
		$state = get_transient( $key );
		$state = is_array( $state ) ? $state : array();
		$last = (int) ( $state['last_failure'] ?? 0 );
		$failures = (int) ( $state['failures'] ?? 0 );
		if ( $last < $now - 15 * MINUTE_IN_SECONDS ) {
			$failures = 0;
		}
		// Handles do mesmo curl_multi terminam quase juntos; não multiplique o castigo.
		if ( $last < $now - 5 ) {
			$failures++;
		}
		$failures = max( 1, $failures );
		// Uma falha isolada não significa uma queda de meia hora. Mantém recuo
		// exponencial e uma única sondagem após a espera; 429 continua conservador.
		$limited = (bool) preg_match( '/HTTP\s*429\b/iu', $error );
		$base = $limited ? 120 : 30;
		$delay = min( $limited ? 1800 : 300, $base * ( 2 ** min( 4, max( 0, $failures - 1 ) ) ) );
		$delay = max( $delay, $retry_after, max( 0, (int) ( $state['until'] ?? 0 ) - $now ) );
		$state = array(
			'policy'       => 2,
			'host'         => $host,
			'failures'     => $failures,
			'last_failure' => $now,
			'until'        => $now + $delay,
			'last_error'   => sanitize_text_field( $error ),
		);
		set_transient( $key, $state, max( 45 * MINUTE_IN_SECONDS, $delay + 15 * MINUTE_IN_SECONDS ) );
		set_transient( 'mbi_network_pressure', 1, $delay );
		set_transient( 'mbi_chapter_network_backoff', 1, $delay );
		return $delay;
	}

	private function retry_after_seconds( string $header ): int {
		$header = trim( $header );
		if ( '' === $header ) {
			return 0;
		}
		return ctype_digit( $header ) ? (int) $header : max( 0, (int) strtotime( $header ) - time() );
	}

	private function clear_source_circuit( string $url ): void {
		$host = $this->source_host( $url );
		if ( $host ) {
			$state = get_transient( $this->source_circuit_key( $host ) );
			if ( is_array( $state ) && (int) ( $state['until'] ?? 0 ) > time() ) {
				// Um 200 paralelo iniciado antes de um 429 não cancela Retry-After.
				return;
			}
			delete_transient( $this->source_circuit_key( $host ) );
			delete_transient( 'mbi_timeout_source49_' . md5( $host ) );
		}
	}

	private function defer_source_job( object $job, string $error, int $retry_after = 0 ): int {
		$url = esc_url_raw( (string) ( $job->payload['url'] ?? '' ) );
		if ( $retry_after > 0 ) {
			$this->open_source_circuit( $url, $error, $retry_after );
		}
		$remaining = $this->source_circuit_remaining( $url );
		$delay = $remaining > 0 ? $remaining : $this->open_source_circuit( $url, $error );
		$this->db->defer( $job, $delay, $error );
		return $delay;
	}

	public function processing_speed_label(): string {
		$speed = sanitize_key( (string) ( $this->settings()['processing_speed'] ?? 'ultra' ) );
		return array( 'normal' => 'Normal', 'fast' => 'Rápido', 'turbo' => 'Turbo', 'ultra' => 'Ultra' )[ $speed ] ?? 'Ultra';
	}

	public function page_storage_mode(): string {
		// v1.5.1: imagens são sempre remotas. A opção local foi descontinuada.
		return 'remote';
	}


	public function image_proxy_ready(): bool {
		return MBI_Image_Proxy::ready( $this->settings() );
	}

	public function image_proxy_status(): array {
		$settings = $this->settings();
		$key_ok   = false !== MBI_Image_Proxy::key_bytes( $settings );
		$base     = MBI_Image_Proxy::base_url( $settings );
		return array(
			'enabled' => ! empty( $settings['image_proxy_enabled'] ),
			'ready'   => MBI_Image_Proxy::ready( $settings ),
			'base'    => $base,
			'key_ok'  => $key_ok,
			'openssl' => function_exists( 'openssl_encrypt' ),
		);
	}

	private function image_delivery_url( string $source_url ): string {
		$source_url = MBI_Image_Proxy::normalize_source_url( $source_url );
		return MBI_Image_Proxy::valid_browser_url( $source_url ) ? MBI_Image_Proxy::protect( $source_url, $this->settings(), false ) : '';
	}

	private function image_delivery_urls( array $source_urls ): array {
		return MBI_Image_Proxy::protect_many( $source_urls, $this->settings() );
	}

	public function media_repair_html( string $url ): string {
		return $this->fetch_html( $url, 8 );
	}

	/** Fresh, bounded HTML for the independent cover-only recovery. */
	public function cover_refresh_html( string $url, int $max_bytes = 4194304, int $timeout = 8 ): string {
		$max_bytes = max( 1048576, min( 8388608, $max_bytes ) );
		$this->assert_allowed_source_url( $url );
		// Re-read the exact source address; some origins reject extra query parameters.
		$response = wp_safe_remote_get( $url, array(
			'timeout' => max( 3, min( 15, $timeout ) ), 'redirection' => 2, 'limit_response_size' => $max_bytes + 1,
			'headers' => array( 'User-Agent' => $this->user_agent(), 'Accept' => 'text/html,application/xhtml+xml', 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ),
		) );
		if ( is_wp_error( $response ) ) { throw new RuntimeException( $response->get_error_message() ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) { throw new RuntimeException( 'Página da origem: HTTP ' . $code . '. Capa anterior preservada.' ); }
		$html = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $html ) < 100 || strlen( $html ) > $max_bytes ) { throw new RuntimeException( 'HTML incompleto ou acima do limite seguro de ' . (int) ( $max_bytes / 1048576 ) . ' MB.' ); }
		return $html;
	}

	public function proxy_migration_status(): array {
		$state = get_option( 'mbi_proxy_migration', array() );
		$defaults = array(
			'active'            => 0,
			'done'              => 0,
			'cursor'            => 0,
			'posts_scanned'     => 0,
			'mangas_migrated'   => 0,
			'chapters_migrated' => 0,
			'urls_migrated'     => 0,
			'skipped'           => 0,
			'updated_at'        => '',
		);
		return wp_parse_args( is_array( $state ) ? $state : array(), $defaults );
	}

	public function start_proxy_migration( bool $restart = false ): array {
		if ( ! $this->image_proxy_ready() ) {
			throw new RuntimeException( 'Configure e ative o Proxy/CDN com uma chave Base64 válida antes de migrar as imagens.' );
		}

		$state = $this->proxy_migration_status();
		if ( $restart || ! empty( $state['done'] ) || empty( $state['active'] ) ) {
			$state = array(
				'active'            => 1,
				'done'              => 0,
				'cursor'            => 0,
				'posts_scanned'     => 0,
				'mangas_migrated'   => 0,
				'chapters_migrated' => 0,
				'urls_migrated'     => 0,
				'skipped'           => 0,
				'updated_at'        => current_time( 'mysql' ),
			);
			update_option( 'mbi_proxy_migration', $state, false );
		}

		// v1.5.6: o clique inicial apenas agenda o trabalho. Nada pesado roda dentro de admin-post.php.
		if ( ! wp_next_scheduled( 'mbi_proxy_migration' ) ) {
			wp_schedule_single_event( time() + 3, 'mbi_proxy_migration' );
		}
		return $state;
	}

	public function proxy_migration_cron(): void {
		$state = $this->proxy_migration_status();
		if ( empty( $state['active'] ) || ! $this->image_proxy_ready() ) {
			return;
		}
		$state = $this->migrate_proxy_batch( 40 );
		if ( ! empty( $state['active'] ) ) {
			wp_schedule_single_event( time() + 5, 'mbi_proxy_migration' );
		}
	}

	public function migrate_proxy_batch( int $limit = 120 ): array {
		global $wpdb;
		$state = $this->proxy_migration_status();
		if ( empty( $state['active'] ) ) {
			return $state;
		}
		if ( ! $this->image_proxy_ready() ) {
			$state['active'] = 0;
			$state['updated_at'] = current_time( 'mysql' );
			update_option( 'mbi_proxy_migration', $state, false );
			return $state;
		}

		$limit  = max( 10, min( 500, $limit ) );
		$cursor = absint( $state['cursor'] ?? 0 );
		$ids = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('mbi_manga','mbi_chapter') AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT %d",
				$cursor,
				$limit
			)
		);

		if ( ! $ids ) {
			$state['active'] = 0;
			$state['done']   = 1;
			$state['updated_at'] = current_time( 'mysql' );
			update_option( 'mbi_proxy_migration', $state, false );
			wp_clear_scheduled_hook( 'mbi_proxy_migration' );
			$this->db->log( 'info', 'Migração Proxy/CDN concluída.', $state );
			return $state;
		}

		$settings = $this->settings();
		foreach ( $ids as $row ) {
			$post_id = (int) $row->ID;
			$state['cursor'] = $post_id;
			$state['posts_scanned']++;

			if ( 'mbi_manga' === $row->post_type ) {
				$current  = esc_url_raw( (string) get_post_meta( $post_id, '_mbi_cover_source_url', true ) );
				$original = esc_url_raw( (string) get_post_meta( $post_id, '_mbi_cover_original_url', true ) );
				if ( ! $original && $current && ! MBI_Image_Proxy::is_proxy_url( $current, $settings ) ) {
					$original = $current;
				}
				$original = MBI_Image_Proxy::normalize_source_url( $original );
				if ( $original && MBI_Image_Proxy::valid_browser_url( $original ) ) {
					$delivery = MBI_Image_Proxy::protect( $original, $settings, false );
					if ( $delivery && MBI_Image_Proxy::is_proxy_url( $delivery, $settings ) && $delivery !== $current ) {
						update_post_meta( $post_id, '_mbi_cover_original_url', $original );
						update_post_meta( $post_id, '_mbi_cover_source_url', $delivery );
						$state['mangas_migrated']++;
						$state['urls_migrated']++;
					} else {
						$state['skipped']++;
					}
				} else {
					$state['skipped']++;
				}
				continue;
			}

			$current_pages = array_values( array_filter( array_map( 'esc_url_raw', (array) get_post_meta( $post_id, '_mbi_chapter_page_urls', true ) ) ) );
			$originals = array_values( array_filter( array_map( 'esc_url_raw', (array) get_post_meta( $post_id, '_mbi_source_image_urls', true ) ) ) );
			if ( ! $originals ) {
				$originals = array_values( array_filter( array_map( 'esc_url_raw', (array) get_post_meta( $post_id, '_mbi_chapter_page_urls_original', true ) ) ) );
			}
			if ( ! $originals && $current_pages ) {
				$originals = array_values( array_filter( $current_pages, static fn( $url ) => ! MBI_Image_Proxy::is_proxy_url( $url, $settings ) ) );
			}

			$originals = array_values( array_filter( array_map( array( 'MBI_Image_Proxy', 'normalize_source_url' ), $originals ), array( 'MBI_Image_Proxy', 'valid_browser_url' ) ) );
			if ( ! $originals ) {
				$state['skipped']++;
				continue;
			}
			$delivery_pages = MBI_Image_Proxy::protect_many( $originals, $settings );
			if ( count( $delivery_pages ) !== count( $originals ) || count( array_filter( $delivery_pages, static fn( $u ) => MBI_Image_Proxy::is_proxy_url( (string) $u, $settings ) ) ) !== count( $originals ) ) {
				$state['skipped']++;
				continue;
			}
			update_post_meta( $post_id, '_mbi_source_image_urls', $originals );
			update_post_meta( $post_id, '_mbi_chapter_page_urls_original', $originals );
			if ( $delivery_pages !== $current_pages ) {
				update_post_meta( $post_id, '_mbi_chapter_page_urls', $delivery_pages );
				$state['chapters_migrated']++;
				$state['urls_migrated'] += count( $delivery_pages );
			} else {
				$state['skipped']++;
			}
		}

		$state['updated_at'] = current_time( 'mysql' );
		update_option( 'mbi_proxy_migration', $state, false );
		return $state;
	}

	/**
	 * Prepara uma URL real de teste sem fazer qualquer chamada externa no PHP.
	 * A validação de rede é feita pelo navegador no painel administrativo.
	 */
	public function prepare_image_proxy_test(): array {
		if ( ! $this->image_proxy_ready() ) {
			return array( 'ok' => false, 'message' => 'Proxy/CDN não está pronto: verifique ativação, endereço HTTPS e chave Base64 de 32 bytes.' );
		}
		$settings = $this->settings();
		// Test up to 25 covers + 25 chapters, without probing the origin/Worker
		// server-side. A stale/IDN first candidate must not invalidate the test.
		$choose = function ( string $candidate ) use ( $settings ): string {
			$candidate = trim( $candidate );
			if ( MBI_Image_Proxy::is_proxy_url( $candidate, $settings ) ) {
				$candidate = MBI_Image_Proxy::unprotect( $candidate, $settings, false );
			}
			$source = MBI_Image_Proxy::normalize_source_url( $candidate );
			if ( ! $source || ! MBI_Image_Proxy::valid_browser_url( $source ) ) { return ''; }
			$proxy = MBI_Image_Proxy::protect( $source, $settings, false );
			return $proxy && MBI_Image_Proxy::is_proxy_url( $proxy, $settings ) && $proxy !== $source ? $proxy : '';
		};
		$mangas = get_posts( array(
			'post_type' => 'mbi_manga', 'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => 25, 'fields' => 'ids', 'no_found_rows' => true,
		) );
		foreach ( $mangas as $id ) {
			foreach ( array( '_mbi_cover_original_url', '_mbi_cover_source_url' ) as $key ) {
				$proxy = $choose( (string) get_post_meta( (int) $id, $key, true ) );
				if ( $proxy ) {
					return array( 'ok' => true, 'proxy_url' => $proxy, 'message' => 'Token preparado localmente; o navegador fará o teste real do Worker.' );
				}
			}
		}
		$chapters = get_posts( array(
			'post_type' => 'mbi_chapter', 'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => 25, 'fields' => 'ids', 'no_found_rows' => true,
		) );
		foreach ( $chapters as $id ) {
			foreach ( array( '_mbi_source_image_urls', '_mbi_chapter_page_urls_original', '_mbi_chapter_page_urls' ) as $key ) {
				foreach ( array_slice( (array) get_post_meta( (int) $id, $key, true ), 0, 3 ) as $source ) {
					$proxy = $choose( (string) $source );
					if ( $proxy ) {
						return array( 'ok' => true, 'proxy_url' => $proxy, 'message' => 'Token preparado localmente; o navegador fará o teste real do Worker.' );
					}
				}
			}
		}
		return array( 'ok' => false, 'message' => 'Não há imagens de origem válidas nas amostras para testar. Nenhum endereço foi alterado.' );
	}

	/** Compatibilidade: não executa mais wp_remote_head síncrono. */
	public function test_image_proxy(): array {
		$result = $this->prepare_image_proxy_test();
		if ( empty( $result['ok'] ) ) {
			return $result;
		}
		return array(
			'ok' => true,
			'message' => 'Configuração local do Proxy/CDN válida. Na v1.5.6 o teste externo é feito pelo navegador para não sobrecarregar o servidor.',
		);
	}

	/** v1.6.9: identifica capas ausentes/genéricas sem tocar em imagens válidas. */
	private function cover_needs_repair( int $manga_id ): bool {
		$settings = $this->settings();
		$current  = esc_url_raw( (string) get_post_meta( $manga_id, '_mbi_cover_source_url', true ) );
		$original = esc_url_raw( (string) get_post_meta( $manga_id, '_mbi_cover_original_url', true ) );
		if ( ! $original && $current && ! MBI_Image_Proxy::is_proxy_url( $current, $settings ) ) {
			$original = $current;
		}
		if ( ! $current || ! wp_http_validate_url( $current ) ) {
			return true;
		}
		if ( $original && MBI_Parser::looks_like_placeholder_url( $original ) ) {
			return true;
		}
		return false;
	}

	public function invalid_cover_count( bool $cached_only = false ): int {
		// v1.6.12: o contador não pode transformar a abertura do painel em uma varredura
		// completa a cada request. Mantemos um cache curto; o reparo invalida o cache.
		$cached = get_transient( 'mbi_invalid_cover_count' );
		if ( false !== $cached ) {
			return max( 0, (int) $cached );
		}
		if ( $cached_only ) { return -1; }
		$ids = get_posts( array(
			'post_type'      => 'mbi_manga',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		) );
		$count = 0;
		foreach ( $ids as $id ) {
			if ( $this->cover_needs_repair( (int) $id ) ) {
				$count++;
			}
		}
		set_transient( 'mbi_invalid_cover_count', $count, 5 * MINUTE_IN_SECONDS );
		return $count;
	}

	/** Enfileira somente capas inválidas; capítulos e metadados da obra são preservados. */
	public function queue_invalid_cover_repairs( int $limit = 500 ): array {
		$this->release_stale_activity_markers();
		$counts = $this->db->counts();
		if ( $this->is_serial_active() || (int) ( $counts['pending'] ?? 0 ) > 0 || (int) ( $counts['processing'] ?? 0 ) > 0 ) {
			return array( 'scanned' => 0, 'queued' => 0, 'error' => 'Conclua ou pause a importação atual antes de iniciar o reparo seletivo de capas.' );
		}
		$limit = max( 1, min( 2000, $limit ) );
		$ids = get_posts( array(
			'post_type'      => 'mbi_manga',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
		$scanned = 0;
		$queued  = 0;
		foreach ( $ids as $id ) {
			if ( $queued >= $limit ) {
				break;
			}
			$id = (int) $id;
			$scanned++;
			if ( ! $this->cover_needs_repair( $id ) ) {
				continue;
			}
			$source = esc_url_raw( (string) get_post_meta( $id, '_mbi_source_url', true ) );
			if ( ! $source || ! wp_http_validate_url( $source ) ) {
				continue;
			}
			$job_id = $this->db->queue(
				'cover_repair',
				array(
					'url'        => $source,
					'manga_id'   => $id,
					'generation' => $this->current_generation(),
					'dedupe'     => 'cover-repair|' . $id . '|' . md5( $source ),
				),
				true
			);
			if ( $job_id ) {
				$queued++;
			}
		}
		if ( $queued ) {
			$this->kick_worker();
		}
		delete_transient( 'mbi_invalid_cover_count' );
		return array( 'scanned' => $scanned, 'queued' => $queued );
	}

	/**
	 * Prepara amostras por HOST de origem. O navegador testa a URL original e a protegida;
	 * assim o painel diferencia "origem quebrada" de "Worker/ALLOWED_HOSTS quebrado".
	 */
	public function prepare_image_proxy_origin_audit( int $host_limit = 12 ): array {
		if ( ! $this->image_proxy_ready() ) {
			return array( 'ok' => false, 'message' => 'Proxy/CDN ainda não está pronto.' );
		}
		$host_limit = max( 1, min( 12, $host_limit ) );
		// v1.6.11: esta rotina também pode ser preparada durante a abertura do painel.
		// Limitar a amostra evita centenas/milhares de leituras de post_meta em catálogos grandes,
		// que podiam estourar o tempo/memória do PHP e causar HTTP 500 ao abrir o Importador.
		$manga_scan_limit   = min( 60, max( 15, $host_limit * 4 ) );
		$chapter_scan_limit = min( 100, max( 30, $host_limit * 6 ) );
		$settings = $this->settings();
		$samples = array();
		$add = function ( string $url, string $role ) use ( &$samples, $host_limit, $settings ): void {
			$url = esc_url_raw( $url );
			if ( ! $url || ! wp_http_validate_url( $url ) || MBI_Parser::looks_like_placeholder_url( $url ) || MBI_Parser::looks_like_non_content_asset_url( $url ) ) {
				return;
			}
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( ! $host || isset( $samples[ $host ] ) || count( $samples ) >= $host_limit ) {
				return;
			}
			$proxy = $this->image_delivery_url( $url );
			if ( ! $proxy || $proxy === $url ) {
				return;
			}
			$samples[ $host ] = array(
				'host'       => $host,
				'role'       => $role,
				'source_url' => $url,
				'proxy_url'  => $proxy,
			);
		};

		$mangas = get_posts( array(
			'post_type' => 'mbi_manga', 'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => $manga_scan_limit, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'DESC',
		) );
		foreach ( $mangas as $manga_id ) {
			$original = esc_url_raw( (string) get_post_meta( (int) $manga_id, '_mbi_cover_original_url', true ) );
			if ( ! $original ) {
				$current = esc_url_raw( (string) get_post_meta( (int) $manga_id, '_mbi_cover_source_url', true ) );
				if ( $current && ! MBI_Image_Proxy::is_proxy_url( $current, $this->settings() ) ) {
					$original = $current;
				}
			}
			$add( $original, 'capa' );
			if ( count( $samples ) >= $host_limit ) {
				break;
			}
		}

		if ( count( $samples ) < $host_limit ) {
			$chapters = get_posts( array(
				'post_type' => 'mbi_chapter', 'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
				'posts_per_page' => $chapter_scan_limit, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'DESC',
			) );
			foreach ( $chapters as $chapter_id ) {
				$urls = (array) get_post_meta( (int) $chapter_id, '_mbi_source_image_urls', true );
				if ( ! $urls ) {
					$urls = (array) get_post_meta( (int) $chapter_id, '_mbi_chapter_page_urls_original', true );
				}
				$urls = array_slice( array_values( $urls ), 0, 3 );
				foreach ( $urls as $url ) {
					$add( (string) $url, 'capítulo' );
					if ( count( $samples ) >= $host_limit ) {
						break 2;
					}
				}
			}
		}

		if ( ! $samples ) {
			return array( 'ok' => false, 'message' => 'Nenhuma URL original válida foi encontrada para a auditoria.' );
		}
		return array( 'ok' => true, 'samples' => array_values( $samples ) );
	}



	/**
	 * v1.6.13: localiza capítulos antigos contaminados por assets de interface
	 * (ex.: flagcdn/tenor) sem varrer 18 mil capítulos em PHP.
	 */
	public function queue_invalid_chapter_page_repairs( int $limit = 500 ): array {
		global $wpdb;
		$this->release_stale_activity_markers();
		$counts = $this->db->counts();
		if ( $this->is_serial_active() || (int) ( $counts['processing'] ?? 0 ) > 0 ) {
			return array( 'scanned' => 0, 'queued' => 0, 'error' => 'Conclua ou pause a importação atual antes de reparar páginas de capítulos.' );
		}
		$limit = max( 1, min( 1000, $limit ) );
		$patterns = array( '%flagcdn.com%', '%media.tenor.com%', '%tenor.com%', '%giphy.com%', '%gravatar.com%' );
		$likes = array();
		$args  = array( 'mbi_chapter', '_mbi_source_image_urls', '_mbi_chapter_page_urls_original' );
		foreach ( $patterns as $pattern ) {
			$likes[] = 'pm.meta_value LIKE %s';
			$args[] = $pattern;
		}
		$args[] = $limit;
		$sql = "SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_type = %s
			AND pm.meta_key IN (%s,%s)
			AND (" . implode( ' OR ', $likes ) . ")
			ORDER BY p.ID ASC
			LIMIT %d";
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		$scanned = 0;
		$queued  = 0;
		foreach ( $ids as $chapter_id ) {
			$chapter_id = (int) $chapter_id;
			$scanned++;
			$source = esc_url_raw( (string) get_post_meta( $chapter_id, '_mbi_source_url', true ) );
			$manga_id = (int) wp_get_post_parent_id( $chapter_id );
			if ( ! $source || ! wp_http_validate_url( $source ) || ! $manga_id ) {
				continue;
			}
			$job_id = $this->db->queue(
				'chapter_page_repair',
				array(
					'url'        => $source,
					'chapter_id' => $chapter_id,
					'manga_id'   => $manga_id,
					'generation' => $this->current_generation(),
					'dedupe'     => 'chapter-page-repair|' . $chapter_id . '|' . md5( $source ),
				),
				true
			);
			if ( $job_id ) {
				$queued++;
			}
		}
		if ( $queued ) {
			$this->kick_worker();
		}
		return array( 'scanned' => $scanned, 'queued' => $queued );
	}

	private function import_chapter_page_repair( array $payload ): void {
		$this->assert_current_generation( $payload );
		$chapter_id = absint( $payload['chapter_id'] ?? 0 );
		if ( ! $chapter_id || get_post_type( $chapter_id ) !== 'mbi_chapter' ) { throw new RuntimeException( 'Capítulo inválido para reparo.' ); }
		$payload['manga_id'] = (int) wp_get_post_parent_id( $chapter_id );
		$this->import_chapter( $payload );
		update_post_meta( $chapter_id, '_mbi_page_repair_status', 'delivery_verified' );
		update_post_meta( $chapter_id, '_mbi_page_repair_checked_at', current_time( 'mysql' ) );
	}

	private function current_generation(): string {
		wp_cache_delete( 'mbi_process_generation', 'options' );
		$generation = (string) get_option( 'mbi_process_generation', '' );
		if ( ! $generation ) {
			$generation = wp_generate_uuid4();
			update_option( 'mbi_process_generation', $generation, false );
		delete_option( 'mbi_validation_focus' ); delete_option( 'mbi_image_validation_progress' );
		}
		return $generation;
	}

	private function rotate_generation(): string {
		$generation = wp_generate_uuid4();
		update_option( 'mbi_process_generation', $generation, false );
		delete_option( 'mbi_validation_focus' ); delete_option( 'mbi_image_validation_progress' );
		update_option( 'mbi_generation_enforced', 1, false );
		return $generation;
	}

	private function assert_current_generation( array $payload ): void {
		if ( ! get_option( 'mbi_generation_enforced', false ) ) {
			return;
		}
		$expected = $this->current_generation();
		$received = sanitize_text_field( (string) ( $payload['generation'] ?? '' ) );
		if ( ! $received || ! hash_equals( $expected, $received ) ) {
			throw new MBI_Skip_Exception( 'Job invalidado por um reinício controlado do processador.' );
		}
	}

	private function runtime( string $stage, string $message, array $context = array() ): void {
		$last = (array) get_option( 'mbi_runtime_status', array() );
		if ( ( $last['stage'] ?? '' ) === sanitize_key( $stage ) && ( $last['message'] ?? '' ) === sanitize_text_field( $message ) && ( $last['context'] ?? array() ) === $context && (int) ( $last['updated_at'] ?? 0 ) > time() - 5 ) { return; }
		update_option(
			'mbi_runtime_status',
			array(
				'stage'      => sanitize_key( $stage ),
				'message'    => sanitize_text_field( $message ),
				'context'    => $context,
				'updated_at' => time(),
			),
			false
		);
	}

	public function runtime_status(): array {
		$status = get_option( 'mbi_runtime_status', array() );
		$status = is_array( $status ) ? $status : array();
		$status['resources'] = MBI_Resource_Guard::status();
		return $status;
	}

	/**
	 * Reset atômico do motor e reinício do reparo dos rascunhos.
	 * Não apaga posts, capítulos, anexos nem metadados de páginas já válidos.
	 */
	public function hard_reset_and_restart_drafts(): array {
		update_option( 'mbi_queue_paused', 1, false );

		$generation = $this->rotate_generation();
		delete_option( 'mbi_worker_lock' );
		$this->worker_lock_token = '';
		delete_transient( 'mbi_worker_dispatching' );
		update_option( 'mbi_worker_token', wp_generate_password( 48, false, false ), false );
		delete_option( 'mbi_serial_batch' );
		delete_option( 'mbi_suspended_serial_batch' );
		delete_option( 'mbi_updates_due' );
		delete_option( 'mbi_pending_catalog_amount' );

		$removed = $this->db->purge_all_jobs();

		// Recria o cron do processador para eliminar agendamentos antigos/duplicados.
		wp_clear_scheduled_hook( 'mbi_process_queue' );
		wp_schedule_event( time() + 10, 'mbi_every_minute', 'mbi_process_queue' );

		$query = new WP_Query(
			array(
				'post_type'      => 'mbi_manga',
				'post_status'    => array( 'draft', 'private', 'pending' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$items   = array();
		$skipped = 0;
		foreach ( $query->posts as $manga_id ) {
			$source_url = esc_url_raw( (string) get_post_meta( $manga_id, '_mbi_source_url', true ) );
			if ( ! $source_url ) {
				$skipped++;
				continue;
			}
			update_post_meta( $manga_id, '_mbi_ready', 0 );
			update_post_meta( $manga_id, '_mbi_target_status', 'publish' );
			delete_post_meta( $manga_id, '_mbi_serial_batch_id' );
			delete_post_meta( $manga_id, '_mbi_serial_completed_batch' );
			delete_post_meta( $manga_id, '_mbi_update_in_progress' );
			$items[] = array( 'url' => $source_url, 'manga_id' => (int) $manga_id );
		}
		wp_reset_postdata();

		update_option( 'mbi_queue_paused', 0, false );
		$state = $this->start_serial_batch( $items, 'validation', count( $items ) );
		$this->runtime(
			'reset',
			$items ? 'Motor zerado. Novo lote de reparo criado e primeira obra liberada.' : 'Motor zerado. Nenhum rascunho com URL de origem foi encontrado.',
			array( 'generation' => $generation, 'removed_jobs' => $removed, 'drafts' => count( $items ), 'skipped' => $skipped )
		);
		$this->db->log( 'warning', 'RESET CONTROLADO executado. Fila e locks foram zerados; conteúdo existente foi preservado.', array( 'generation' => $generation, 'removed_jobs' => $removed, 'drafts' => count( $items ), 'skipped' => $skipped ) );

		if ( $items ) {
			wp_schedule_single_event( time() + 1, 'mbi_process_queue' );
			$this->kick_worker();
		}
		return array(
			'removed_jobs' => $removed,
			'queued'       => count( $items ),
			'skipped'      => $skipped,
			'batch'        => (string) ( $state['id'] ?? '' ),
			'generation'   => $generation,
		);
	}

	/** Reinicia somente a obra atual do lote no modo remoto rápido, sem perder o avanço do lote. */
	public function restart_current_serial_for_remote_pages(): array {
		$state = $this->serial_state();
		if ( empty( $state['active'] ) || empty( $state['id'] ) ) {
			return array( 'active' => false, 'removed' => 0 );
		}

		update_option( 'mbi_queue_paused', 1, false );
		$generation = $this->rotate_generation();
		delete_option( 'mbi_worker_lock' );
		$this->worker_lock_token = '';
		delete_transient( 'mbi_worker_dispatching' );
		update_option( 'mbi_worker_token', wp_generate_password( 48, false, false ), false );

		$batch_id = (string) $state['id'];
		$removed  = $this->db->purge_active_content_jobs_for_batch( $batch_id );
		$state['generation'] = $generation;
		update_option( 'mbi_serial_batch', $state, false );
		update_option( 'mbi_queue_paused', 0, false );
		$this->queue_serial_current( $state );
		$this->runtime( 'remote-fast', 'Modo 100% remoto ativado. A obra atual foi retomada sem downloads locais de capas ou páginas.', array( 'batch' => $batch_id, 'removed' => $removed ) );
		wp_schedule_single_event( time() + 1, 'mbi_process_queue' );
		$this->kick_worker();
		return array( 'active' => true, 'removed' => $removed, 'batch' => $batch_id );
	}


	/**
	 * Inicia/retoma a limpeza dos anexos legados criados pelo próprio Mangá Bridge.
	 * A limpeza é destrutiva apenas para esses anexos e preserva as URLs remotas antes da exclusão.
	 */
	public function start_legacy_media_cleanup( bool $reset = false ): array {
		$state = get_option( 'mbi_legacy_media_cleanup', array() );
		$state = is_array( $state ) ? $state : array();

		if ( $reset || empty( $state ) || ! empty( $state['done'] ) ) {
			$state = array(
				'active'            => 1,
				'done'              => 0,
				'deleted'           => 0,
				'protected'         => 0,
				'migrated_covers'   => 0,
				'migrated_chapters' => 0,
				'started_at'        => time(),
				'updated_at'        => time(),
				'remaining'         => $this->count_legacy_media_candidates(),
			);
		} else {
			$state['active']     = 1;
			$state['done']       = 0;
			$state['updated_at'] = time();
			if ( ! isset( $state['remaining'] ) ) {
				$state['remaining'] = $this->count_legacy_media_candidates();
			}
		}

		update_option( 'mbi_legacy_media_cleanup', $state, false );
		if ( ! wp_next_scheduled( 'mbi_cleanup_legacy_media' ) ) {
			wp_schedule_single_event( time() + 2, 'mbi_cleanup_legacy_media' );
		}
		return $this->legacy_media_cleanup_status();
	}

	public function legacy_media_cleanup_status(): array {
		$state = get_option( 'mbi_legacy_media_cleanup', array() );
		$state = is_array( $state ) ? $state : array();
		return wp_parse_args(
			$state,
			array(
				'active'            => 0,
				'done'              => 0,
				'deleted'           => 0,
				'protected'         => 0,
				'migrated_covers'   => 0,
				'migrated_chapters' => 0,
				'started_at'        => 0,
				'updated_at'        => 0,
				'remaining'         => 0,
			)
		);
	}

	public function cleanup_legacy_media_cron(): void {
		$this->cleanup_legacy_media_batch( 120 );
	}

	/**
	 * Remove em lote apenas attachments identificados como criados pelo Mangá Bridge.
	 * Antes de apagar, migra capa/páginas para metadados de URL remota.
	 */
	public function cleanup_legacy_media_batch( int $limit = 80 ): array {
		$limit = max( 1, min( 250, $limit ) );
		$state = $this->legacy_media_cleanup_status();
		if ( empty( $state['active'] ) ) {
			return $state;
		}

		$ids = $this->legacy_media_candidate_ids( $limit );
		if ( ! $ids ) {
			$state['active']     = 0;
			$state['done']       = 1;
			$state['remaining']  = 0;
			$state['updated_at'] = time();
			update_option( 'mbi_legacy_media_cleanup', $state, false );
			wp_clear_scheduled_hook( 'mbi_cleanup_legacy_media' );
			$this->db->log(
				'info',
				'Limpeza das imagens antigas concluída.',
				array(
					'deleted'           => (int) $state['deleted'],
					'protected'         => (int) $state['protected'],
					'migrated_covers'   => (int) $state['migrated_covers'],
					'migrated_chapters' => (int) $state['migrated_chapters'],
				)
			);
			return $state;
		}

		$deleted = 0;
		$protected = 0;
		$migrated_covers = 0;
		$migrated_chapters = 0;
		$started = microtime( true );

		foreach ( $ids as $attachment_id ) {
			if ( ( microtime( true ) - $started ) > 18 ) {
				break;
			}
			$attachment_id = absint( $attachment_id );
			$post = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				continue;
			}

			$source_url = esc_url_raw( (string) get_post_meta( $attachment_id, '_mbi_source_url', true ) );
			$parent_id  = absint( $post->post_parent );
			$parent_type = $parent_id ? get_post_type( $parent_id ) : '';
			$safe = false;

			if ( 'mbi_manga' === $parent_type ) {
				$safe = $this->prepare_remote_cover_for_cleanup( $parent_id, $attachment_id, $source_url );
				if ( $safe ) {
					$migrated_covers++;
				}
			} elseif ( 'mbi_chapter' === $parent_type ) {
				$safe = $this->prepare_remote_chapter_for_cleanup( $parent_id );
				if ( $safe ) {
					$migrated_chapters++;
				}
			} elseif ( '1' === (string) get_post_meta( $attachment_id, '_mbi_fast_page_attachment', true ) ) {
				// Marcador inequívoco das páginas criadas pelo importador Ultra antigo.
				$safe = true;
			}

			if ( ! $safe ) {
				update_post_meta( $attachment_id, '_mbi_cleanup_protected', 1 );
				$protected++;
				continue;
			}

			if ( wp_delete_attachment( $attachment_id, true ) ) {
				$deleted++;
			} else {
				update_post_meta( $attachment_id, '_mbi_cleanup_protected', 1 );
				$protected++;
			}
		}

		$processed = $deleted + $protected;
		$state['deleted']           = (int) $state['deleted'] + $deleted;
		$state['protected']         = (int) $state['protected'] + $protected;
		$state['migrated_covers']   = (int) $state['migrated_covers'] + $migrated_covers;
		$state['migrated_chapters'] = (int) $state['migrated_chapters'] + $migrated_chapters;
		$state['remaining']         = max( 0, (int) $state['remaining'] - $processed );
		$state['updated_at']        = time();

		$more = $this->legacy_media_candidate_ids( 1 );
		if ( ! $more ) {
			$state['active']    = 0;
			$state['done']      = 1;
			$state['remaining'] = 0;
			wp_clear_scheduled_hook( 'mbi_cleanup_legacy_media' );
		} else {
			if ( ! wp_next_scheduled( 'mbi_cleanup_legacy_media' ) ) {
				wp_schedule_single_event( time() + 10, 'mbi_cleanup_legacy_media' );
			}
		}

		update_option( 'mbi_legacy_media_cleanup', $state, false );
		return $state;
	}

	private function prepare_remote_cover_for_cleanup( int $manga_id, int $attachment_id, string $source_url ): bool {
		$remote = esc_url_raw( (string) get_post_meta( $manga_id, '_mbi_cover_source_url', true ) );
		$current_thumbnail = get_post_thumbnail_id( $manga_id );

		// Se ainda não existe capa remota, prioriza a origem da miniatura atualmente ativa.
		if ( ! wp_http_validate_url( $remote ) && $current_thumbnail ) {
			$current_source = esc_url_raw( (string) get_post_meta( $current_thumbnail, '_mbi_source_url', true ) );
			if ( wp_http_validate_url( $current_source ) ) {
				$remote = $current_source;
			}
		}
		if ( ! wp_http_validate_url( $remote ) ) {
			$remote = esc_url_raw( $source_url );
		}
		if ( ! wp_http_validate_url( $remote ) ) {
			return false;
		}
		$delivery = $this->image_delivery_url( $remote );
		if ( ! $delivery || ( $this->image_proxy_ready() && ! MBI_Image_Proxy::is_proxy_url( $delivery, $this->settings() ) ) ) { return false; }
		update_post_meta( $manga_id, '_mbi_cover_original_url', $remote );
		update_post_meta( $manga_id, '_mbi_cover_source_url', $delivery );
		if ( $current_thumbnail === $attachment_id ) {
			delete_post_thumbnail( $manga_id );
		}
		return true;
	}

	private function prepare_remote_chapter_for_cleanup( int $chapter_id ): bool {
		$expected = absint( get_post_meta( $chapter_id, '_mbi_expected_pages', true ) );
		$remote   = array_values( array_filter( array_map( 'esc_url_raw', (array) get_post_meta( $chapter_id, '_mbi_chapter_page_urls', true ) ) ) );
		$source   = array_values( array_filter( array_map( 'esc_url_raw', (array) get_post_meta( $chapter_id, '_mbi_source_image_urls', true ) ) ) );

		if ( $expected < 1 ) {
			$expected = max( count( $remote ), count( $source ) );
		}
		if ( $expected < 1 ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $chapter_id, '_mbi_chapter_page_ids', true ) ) ) );
			$recovered = array();
			foreach ( $ids as $id ) {
				$url = esc_url_raw( (string) get_post_meta( $id, '_mbi_source_url', true ) );
				if ( ! wp_http_validate_url( $url ) ) {
					return false;
				}
				$recovered[] = $url;
			}
			$expected = count( $recovered );
			$source   = $recovered;
			$remote   = $recovered;
		}

		$valid_remote = count( $remote ) === $expected;
		if ( $valid_remote ) {
			foreach ( $remote as $url ) {
				if ( ! wp_http_validate_url( $url ) ) {
					$valid_remote = false;
					break;
				}
			}
		}

		if ( ! $valid_remote ) {
			$valid_source = count( $source ) === $expected;
			if ( $valid_source ) {
				foreach ( $source as $url ) {
					if ( ! wp_http_validate_url( $url ) ) {
						$valid_source = false;
						break;
					}
				}
			}
			if ( ! $valid_source ) {
				$ids = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $chapter_id, '_mbi_chapter_page_ids', true ) ) ) );
				if ( count( $ids ) !== $expected ) {
					return false;
				}
				$source = array();
				foreach ( $ids as $id ) {
					$url = esc_url_raw( (string) get_post_meta( $id, '_mbi_source_url', true ) );
					if ( ! wp_http_validate_url( $url ) ) {
						return false;
					}
					$source[] = $url;
				}
			}
			$remote = $source;
		}

		if ( count( $remote ) !== $expected ) {
			return false;
		}
		$delivery = $this->image_delivery_urls( $remote );
		if ( count( $delivery ) !== $expected || in_array( '', $delivery, true ) || ( $this->image_proxy_ready() && count( array_filter( $delivery, fn( $url ) => MBI_Image_Proxy::is_proxy_url( (string) $url, $this->settings() ) ) ) !== $expected ) ) { return false; }
		update_post_meta( $chapter_id, '_mbi_expected_pages', $expected );
		update_post_meta( $chapter_id, '_mbi_source_image_urls', $remote );
		update_post_meta( $chapter_id, '_mbi_chapter_page_urls_original', $remote );
		update_post_meta( $chapter_id, '_mbi_chapter_page_urls', $delivery );
		delete_post_meta( $chapter_id, '_mbi_chapter_page_ids' );
		delete_post_meta( $chapter_id, '_mbi_chapter_page_map' );
		return true;
	}

	private function legacy_media_candidate_ids( int $limit ): array {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_mbi_source_url'
			 LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
			 LEFT JOIN {$wpdb->postmeta} fast ON fast.post_id = p.ID AND fast.meta_key = '_mbi_fast_page_attachment'
			 LEFT JOIN {$wpdb->postmeta} protected ON protected.post_id = p.ID AND protected.meta_key = '_mbi_cleanup_protected'
			 WHERE p.post_type = 'attachment'
			   AND protected.post_id IS NULL
			   AND (parent.post_type IN ('mbi_manga','mbi_chapter') OR fast.meta_value = '1')
			 ORDER BY p.ID ASC
			 LIMIT %d",
			$limit
		);
		return array_map( 'absint', (array) $wpdb->get_col( $sql ) );
	}

	private function count_legacy_media_candidates(): int {
		global $wpdb;
		$sql = "SELECT COUNT(DISTINCT p.ID)
		        FROM {$wpdb->posts} p
		        INNER JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_mbi_source_url'
		        LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
		        LEFT JOIN {$wpdb->postmeta} fast ON fast.post_id = p.ID AND fast.meta_key = '_mbi_fast_page_attachment'
		        LEFT JOIN {$wpdb->postmeta} protected ON protected.post_id = p.ID AND protected.meta_key = '_mbi_cleanup_protected'
		        WHERE p.post_type = 'attachment'
		          AND protected.post_id IS NULL
		          AND (parent.post_type IN ('mbi_manga','mbi_chapter') OR fast.meta_value = '1')";
		return max( 0, (int) $wpdb->get_var( $sql ) );
	}

	public function ensure_base_categories(): void {
		foreach ( array( 'Mangás' => 'mangas', 'Manhwas' => 'manhwas', 'Manhuas' => 'manhuas' ) as $name => $slug ) {
			if ( ! get_term_by( 'slug', $slug, 'category' ) ) {
				wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
			}
		}
	}

	public function serial_state(): array {
		wp_cache_delete( 'mbi_serial_batch', 'options' );
		$state = get_option( 'mbi_serial_batch', array() );
		return is_array( $state ) ? $state : array();
	}

	/** Atualiza somente metadados de pedidos ativos; pedidos encerrados não reiniciam. */
	public function upgrade_quantity_request(): void {
		$state = $this->serial_state();
		if ( empty( $state['active'] ) || ! in_array( (string) ( $state['mode'] ?? '' ), array( 'catalog', 'auto_catalog', 'validation_amount' ), true ) ) {
			return;
		}
		$catalog = $this->catalog_discovery_status();
		if ( 'validation_amount' === $state['mode'] ) {
			$state['target'] = max( absint( $state['target'] ?? 0 ), absint( $state['requested_total'] ?? 0 ) );
		} elseif ( (string) ( $catalog['serial_batch'] ?? '' ) === (string) $state['id'] ) {
			$state['target'] = max( absint( $state['target'] ?? 0 ), absint( $catalog['limit'] ?? 0 ) );
			$state['catalog_seen'] = (array) ( $catalog['seen'] ?? array() );
			$state['catalog_next_page'] = absint( $catalog['last_page_scanned'] ?? $catalog['page'] ?? 1 ) + 1;
			$state['catalog_page_limit'] = max( 1, absint( $catalog['page_limit'] ?? 1 ) );
			$state['catalog_source_stopped'] = absint( $catalog['consecutive_source_failures'] ?? 0 ) >= 2;
		}
		update_option( 'mbi_serial_batch', $state, false );
	}

	public function is_serial_active(): bool {
		$state = $this->serial_state();
		return ! empty( $state['active'] ) && ! empty( $state['id'] );
	}

	/**
	 * v1.6.27: corta um possível lote fantasma criado por um starter da versão
	 * anterior que já estava executando em outro processo PHP durante o upgrade.
	 * Um lote novo criado pela descoberta incremental é preservado porque seu ID
	 * fica registrado em mbi_catalog_discovery['serial_batch'].
	 */
	public function cleanup_catalog_cutover_guard(): array {
		$until = absint( get_option( 'mbi_catalog_cutover_guard_until', 0 ) );
		$result = array( 'guard' => $until, 'cancelled_batch' => '', 'removed_jobs' => 0 );
		if ( $until < 1 ) {
			return $result;
		}

		$serial = $this->serial_state();
		if ( ! empty( $serial['active'] ) && ! empty( $serial['id'] ) ) {
			$mode = sanitize_key( (string) ( $serial['mode'] ?? '' ) );
			if ( in_array( $mode, array( 'catalog', 'catalog_full', 'auto_catalog' ), true ) ) {
				$catalog = $this->catalog_discovery_status();
				$expected_batch = sanitize_text_field( (string) ( $catalog['serial_batch'] ?? '' ) );
				$actual_batch = sanitize_text_field( (string) $serial['id'] );
				if ( ! $expected_batch || ! hash_equals( $expected_batch, $actual_batch ) ) {
					$result['removed_jobs'] = $this->db->clear_pending_for_batch( $actual_batch );
					$serial['active'] = false;
					$serial['current_url'] = '';
					$serial['current_manga_id'] = 0;
					$serial['released_stale_at'] = current_time( 'mysql' );
					$serial['release_reason'] = 'legacy_catalog_cutover';
					update_option( 'mbi_serial_batch', $serial, false );
					$result['cancelled_batch'] = $actual_batch;
					$this->db->log(
						'warning',
						'Lote fantasma de catálogo criado por um starter antigo foi interrompido após o upgrade. Conteúdo já gravado foi preservado.',
						array( 'batch' => $actual_batch, 'removed_pending_jobs' => $result['removed_jobs'] )
					);
				}
			}
		}

		if ( time() >= $until ) {
			delete_option( 'mbi_catalog_cutover_guard_until' );
		}
		return $result;
	}

	/**
	 * v1.6.20: remove marcadores antigos de atividade somente quando não existe
	 * nenhum job executável. Isso evita que um lote já encerrado bloqueie a
	 * detecção manual de novidades para sempre. Jobs com falha são preservados.
	 */
	public function release_stale_activity_markers(): array {
		$this->cleanup_catalog_cutover_guard();
		// Se uma versão antiga terminou uma descoberta de catálogo depois de o plugin
		// ter invalidado aquela geração, não deixe esse lote fantasma bloquear o painel.
		$serial_before = $this->serial_state();
		if ( ! empty( $serial_before['active'] ) && get_option( 'mbi_generation_enforced', false ) ) {
			$serial_generation = sanitize_text_field( (string) ( $serial_before['generation'] ?? '' ) );
			$current_generation = $this->current_generation();
			if ( $serial_generation && ! hash_equals( $current_generation, $serial_generation ) ) {
				$batch_id = sanitize_text_field( (string) ( $serial_before['id'] ?? '' ) );
				if ( $batch_id ) {
					$this->db->clear_pending_for_batch( $batch_id );
				}
				$serial_before['active'] = false;
				$serial_before['current_url'] = '';
				$serial_before['current_manga_id'] = 0;
				$serial_before['released_stale_at'] = current_time( 'mysql' );
				$serial_before['release_reason'] = 'generation_mismatch';
				update_option( 'mbi_serial_batch', $serial_before, false );
			}
		}

		$counts = $this->db->counts();
		$result = array(
			'released_serial' => 0,
			'released_manual' => 0,
			'pending'         => (int) ( $counts['pending'] ?? 0 ),
			'processing'      => (int) ( $counts['processing'] ?? 0 ),
			'failed'          => (int) ( $counts['failed'] ?? 0 ),
		);

		// Nunca altere estado enquanto houver trabalho realmente executável.
		if ( $result['pending'] > 0 || $result['processing'] > 0 ) {
			return $result;
		}

		$serial = $this->serial_state();
		if ( ! empty( $serial['active'] ) && ! empty( $serial['id'] ) ) {
			$work = $this->db->batch_work_counts( (string) $serial['id'] );
			if ( (int) ( $work['pending'] ?? 0 ) < 1 && (int) ( $work['processing'] ?? 0 ) < 1 ) {
				$serial['active'] = false;
				$serial['current_url'] = '';
				$serial['current_manga_id'] = 0;
				$serial['finished_at'] = (string) ( $serial['finished_at'] ?? current_time( 'mysql' ) );
				$serial['released_stale_at'] = current_time( 'mysql' );
				update_option( 'mbi_serial_batch', $serial, false );
				$result['released_serial'] = 1;
			}
		}

		$manual = $this->manual_update_progress();
		if ( ! empty( $manual['active'] ) && empty( $manual['preparing'] ) ) {
			$manual['active'] = false;
			$manual['finished_at'] = (string) ( $manual['finished_at'] ?? current_time( 'mysql' ) );
			$manual['released_stale_at'] = current_time( 'mysql' );
			if ( (int) ( $manual['completed'] ?? 0 ) + (int) ( $manual['failed'] ?? 0 ) < (int) ( $manual['total'] ?? 0 ) ) {
				$manual['error'] = 'Estado antigo liberado automaticamente: não havia jobs pendentes ou em processamento.';
			}
			update_option( 'mbi_manual_update_batch', $manual, false );
			$result['released_manual'] = 1;
		}

		if ( $result['released_serial'] || $result['released_manual'] ) {
			delete_transient( 'mbi_worker_dispatching' );
			$this->db->log( 'info', 'Marcador antigo de atividade liberado automaticamente.', $result );
		}

		return $result;
	}

	/**
	 * Autocura do lote serial. Evita o estado impossível "há pendente, mas nenhum worker consegue reivindicá-lo".
	 * Preserva conteúdo já criado e nunca avança para a próxima obra sem a validação normal.
	 */
	public function recover_queue( bool $aggressive = false ): array {
		$result = array( 'active' => false, 'stale_lock_removed' => 0, 'orphan_pending_removed' => 0, 'fresh_jobs_woken' => 0, 'current_job_recreated' => 0 );
		$state = $this->serial_state();
		if ( empty( $state['active'] ) || empty( $state['id'] ) ) {
			return $result;
		}
		$result['active'] = true;
		$batch_id = (string) $state['id'];

		$this->db->release_stale_jobs( $aggressive ? 150 : 180 );
		delete_transient( 'mbi_worker_dispatching' );
		$result['stale_lock_removed'] = $this->clear_stale_worker_lock( $aggressive ? 150 : 180 ) ? 1 : 0;
		$result['orphan_pending_removed'] = $this->db->clear_orphan_serial_pending( $batch_id );
		$result['fresh_jobs_woken'] = $this->db->wake_fresh_pending_for_batch( $batch_id );

		$work = $this->db->batch_work_counts( $batch_id );
		if ( 0 === (int) $work['pending'] && 0 === (int) $work['processing'] ) {
			$this->queue_serial_current( $state );
			$result['current_job_recreated'] = 1;
		}
		return $result;
	}

	/** Ativa/recupera o motor rápido preservando integralmente o avanço da obra atual. */
	public function upgrade_large_import_engine(): array {
		$state = $this->serial_state();
		$result = array( 'active' => false, 'recovered' => 0, 'batch' => '' );
		delete_transient( 'mbi_worker_dispatching' );
		delete_transient( 'mbi_chapter_network_backoff' );
		if ( empty( $state['active'] ) || empty( $state['id'] ) ) {
			return $result;
		}

		$batch_id = (string) $state['id'];
		$result['active']    = true;
		$result['batch']     = $batch_id;
		$result['recovered'] = $this->db->recover_unfinished_chapters_for_batch( $batch_id );
		$result['recovered_manga'] = $this->db->recover_unfinished_manga_for_batch( $batch_id );
		$this->clear_stale_worker_lock( 120 );
		$this->runtime( 'engine-upgraded', 'Motor rápido e adaptativo ativado; retomando somente capítulos ainda não concluídos.', $result );
		if ( ! $this->is_paused() ) {
			wp_schedule_single_event( time() + 2, 'mbi_process_queue' );
			$this->kick_worker();
		}
		return $result;
	}

	private function start_serial_batch( array $items, string $mode, int $target = 0 ): array {
		$existing_state = $this->serial_state();
		if ( ! empty( $existing_state['active'] ) && ! empty( $existing_state['id'] ) ) {
			$this->db->log( 'warning', 'Novo lote ignorado: já existe um lote serial ativo.', array( 'active_batch' => $existing_state['id'], 'requested_mode' => $mode ) );
			return $existing_state;
		}
		$normalized = array();
		$seen = array();
		foreach ( $items as $item ) {
			if ( is_string( $item ) ) {
				$item = array( 'url' => $item );
			}
			$url = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			if ( ! $url || isset( $seen[ $this->source_key( $url ) ] ) ) {
				continue;
			}
			$seen[ $this->source_key( $url ) ] = true;
			$normalized[] = array(
				'url'      => $url,
				'manga_id' => absint( $item['manga_id'] ?? 0 ),
			);
		}
		// A meta é de conclusões, não o tamanho da primeira seleção de candidatas.
		$target = $target > 0 ? $target : count( $normalized );
		if ( ! $normalized || $target < 1 ) {
			return array( 'id' => '', 'active' => false, 'total' => 0, 'target' => 0, 'completed' => 0 );
		}

		$state = array(
			'id'               => wp_generate_uuid4(),
			'generation'       => $this->current_generation(),
			'mode'             => sanitize_key( $mode ),
			'active'           => true,
			'items'            => array_values( $normalized ),
			'cursor'           => 0,
			'total'            => count( $normalized ),
			'target'           => $target,
			'completed'        => 0,
			'current_url'      => '',
			'current_manga_id' => 0,
			'started_at'       => current_time( 'mysql' ),
			'started_ts'       => time(),
		);
		if ( in_array( $mode, array( 'catalog', 'auto_catalog', 'catalog_full' ), true ) ) {
			$catalog = $this->catalog_discovery_status();
			if ( ! empty( $catalog['resume_serial_batch'] ) ) {
				if ( (string) ( $existing_state['id'] ?? '' ) !== (string) $catalog['resume_serial_batch'] || empty( $existing_state['waiting_catalog'] ) || (string) ( $existing_state['generation'] ?? '' ) !== $this->current_generation() ) {
					throw new MBI_Skip_Exception( 'Reposição cancelada ou substituída; lote antigo não foi retomado.' );
				}
				$state = $existing_state;
				if ( 'collect51' === ( $state['workflow'] ?? '' ) ) { $state = $this->append_candidates52( $state, $normalized ); }
				else { $state['items'] = array_merge( (array) $state['items'], $normalized ); }
				$state['total'] = count( $state['items'] );
				$state['active'] = true;
				$state['waiting_catalog'] = false;
				$state['mode'] = $mode;
			}
			$state['catalog_seen'] = (array) ( $catalog['seen'] ?? array() );
			$state['catalog_next_page'] = absint( $catalog['last_page_scanned'] ?? $catalog['page'] ?? 1 ) + 1;
			$state['catalog_page_limit'] = max( 1, absint( $catalog['page_limit'] ?? 1 ) );
			$state['catalog_source_stopped'] = absint( $catalog['consecutive_source_failures'] ?? 0 ) >= 2;
		}
		// New imports have a finite selection. Old running batches keep their state.
		if ( in_array( $mode, array( 'catalog', 'auto_catalog', 'catalog_full', 'manual_url' ), true ) && empty( $catalog['resume_serial_batch'] ) ) {
			$state['workflow'] = 'collect51';
			$state['stream53'] = true;
			$state['quantity_goal52'] = true;
			$state['phase'] = 'collect';
			$state['wave_start'] = 0;
			$state['candidate_backlog'] = array_slice( $state['items'], $target );
			$state['items'] = array_slice( $state['items'], 0, $target );
			$state['total'] = count( $state['items'] );
			$state['capture_done'] = 0;
			$state['captured'] = 0;
			$state['verified'] = 0;
			$state['rows'] = array_fill( 0, $state['total'], array( 'status' => 'selected', 'reason' => '', 'title' => '' ) );
		}
		update_option( 'mbi_serial_batch', $state, false );
		$this->db->log( 'info', 'Lote serial iniciado. Apenas uma obra será processada por vez.', array( 'mode' => $mode, 'target' => $state['target'], 'candidates' => count( $normalized ), 'batch' => $state['id'] ) );
		$this->queue_serial_current( $state );
		return $state;
	}

	/** Reuse URLs already discovered on the page before reading another page. */
	private function append_candidates52( array $s, array $candidates ): array {
		$remaining = max( 0, (int) $s['target'] - (int) ( $s['completed'] ?? 0 ) );
		$tried = array();
		foreach ( (array) $s['items'] as $item ) { $tried[$this->source_key( (string) $item['url'] )] = true; }
		$known = (array) get_option( 'mbi_rejected_candidates52', array() );
		$available = array();
		foreach ( array_merge( (array) ( $s['candidate_backlog'] ?? array() ), $candidates ) as $item ) {
			$url = esc_url_raw( (string) ( $item['url'] ?? '' ) ); $key = $this->source_key( $url );
			if ( ! $url || isset( $tried[$key] ) || (int) ( $known[$key]['until'] ?? 0 ) > time() ) { continue; }
			$tried[$key] = true; $available[] = array( 'url' => $url, 'manga_id' => absint( $item['manga_id'] ?? 0 ) );
		}
		$chosen = array_slice( $available, 0, $remaining );
		$s['candidate_backlog'] = array_slice( $available, $remaining );
		$s['wave_start'] = count( $s['items'] );
		$s['items'] = array_merge( (array) $s['items'], $chosen );
		foreach ( $chosen as $item ) { $s['rows'][] = array( 'status' => 'selected', 'reason' => '', 'title' => '' ); }
		$s['total'] = count( $s['items'] ); $s['cursor'] = $s['wave_start'];
		$s['phase'] = 'collect'; $s['quantity_goal52'] = true; $s['waiting_catalog'] = false;
		$s['active'] = true; $s['current_url'] = ''; $s['current_manga_id'] = 0;
		unset( $s['finished_at'], $s['finish_reason'] );
		return $s;
	}

	/** One compact rejection index, read once per request by the WP option cache. */
	private function remember_rejection52( string $url, string $reason ): void {
		if ( $this->origin_pressure_error( $reason ) ) { return; }
		if ( ! $url || ! ( $this->source_defect_error( $reason ) || preg_match( '/capa|sinopse|dados.*(?:inválidos|suspeitos)|validação anti-corrupção/iu', $reason ) ) ) { return; }
		$known = (array) get_option( 'mbi_rejected_candidates52', array() );
		foreach ( $known as $key => $row ) { if ( (int) ( $row['until'] ?? 0 ) <= time() ) { unset( $known[$key] ); } }
		$known[$this->source_key( $url )] = array( 'until' => time() + ( preg_match( '/HTTP (?:404|410)\b/i', $reason ) ? DAY_IN_SECONDS : HOUR_IN_SECONDS ), 'reason' => mb_substr( sanitize_text_field( $reason ), 0, 240 ) );
		update_option( 'mbi_rejected_candidates52', array_slice( $known, -2000, null, true ), false );
	}

	private function capture_key51( array $payload ): string {
		return 'mbi_capture51_' . md5( (string) $payload['serial_batch'] . '|' . (int) $payload['serial_cursor'] );
	}

	/** Both phases share the worker lease, but have different queue identities. */
	private function pipeline_state51( array $payload ): array {
		$this->assert_current_generation( $payload );
		$s = $this->serial_state();
		if ( $this->is_paused() ) { throw new MBI_Resource_Wait( 'Coleta/validação pausada; etapa preservada.', 5 ); }
		if ( empty( $s['active'] ) || 'collect51' !== ( $s['workflow'] ?? '' ) || ( $s['id'] ?? '' ) !== ( $payload['serial_batch'] ?? '' ) || (int) ( $s['cursor'] ?? -1 ) !== (int) ( $payload['serial_cursor'] ?? -2 ) || ( $s['phase'] ?? '' ) !== ( $payload['pipeline_phase'] ?? '' ) || ( $s['current_url'] ?? '' ) !== ( $payload['url'] ?? '' ) ) {
			throw new MBI_Skip_Exception( 'Etapa do lote cancelada, pausada ou substituída.' );
		}
		return $s;
	}

	private function collect_manga51( array $payload ): void {
		$this->pipeline_state51( $payload );
		$url = $this->required_url( $payload );
		$this->runtime( 'collect', 'Coletando dados e lista de capítulos, sem publicar: ' . $url );
		// Structural pagination checks prevent accepting missing/repeated chapters.
		// Editorial fields, covers and page images are checked only in phase two.
		$data = $this->complete_import_inventory( $url, false, true );
		$s = $this->pipeline_state51( $payload );
		set_transient( $this->capture_key51( $payload ), array( 'generation' => $this->current_generation(), 'url' => $url, 'at' => time(), 'data' => $data ), 7 * DAY_IN_SECONDS );
		$n = (int) $s['cursor'];
		$s['rows'][$n] = array( 'status' => 'collected', 'reason' => '', 'title' => mb_substr( wp_strip_all_tags( (string) ( $data['title'] ?? '' ) ), 0, 160 ), 'chapters' => count( (array) ( $data['chapter_urls'] ?? array() ) ) );
		$s['captured']++;
		$s['consecutive_network_failures52'] = 0;
		$this->advance_capture51( $s );
	}

	private function advance_capture51( array $s ): void {
		if ( ! empty( $s['stream53'] ) ) {
			$s['capture_done']++;
			$s['current_url'] = ''; $s['current_manga_id'] = 0;
			if ( 'collected' === ( $s['rows'][$s['cursor']]['status'] ?? '' ) ) {
				$s['phase'] = 'validate'; // Publish this work before collecting the next.
			} else {
				$s['verified']++; $s['cursor']++; $s['phase'] = 'collect';
				if ( $s['cursor'] >= count( $s['items'] ) ) { $this->finish_serial_batch( $s ); return; }
			}
			update_option( 'mbi_serial_batch', $s, false );
			$this->queue_serial_current( $s ); return;
		}
		$s['capture_done']++;
		$s['cursor']++;
		$s['current_url'] = '';
		$s['current_manga_id'] = 0;
		if ( $s['cursor'] >= count( $s['items'] ) ) {
			$s['phase'] = 'validate';
			$s['cursor'] = absint( $s['wave_start'] ?? 0 );
			$s['skipped'] = 0;
			$this->db->log( 'info', 'Coleta desta seleção encerrada. Iniciando validação; só publicações contam para a meta.', array( 'batch' => $s['id'], 'captured' => $s['captured'], 'selected' => count( $s['items'] ) ) );
		}
		update_option( 'mbi_serial_batch', $s, false );
		$this->queue_serial_current( $s );
	}

	private function staged_inventory51( array $payload ): array {
		$this->pipeline_state51( $payload );
		$url = $this->required_url( $payload );
		$snapshot = get_transient( $this->capture_key51( $payload ) );
		if ( ! is_array( $snapshot ) || ( $snapshot['generation'] ?? '' ) !== $this->current_generation() || ( $snapshot['url'] ?? '' ) !== $url || ( $snapshot['at'] ?? 0 ) < time() - HOUR_IN_SECONDS ) {
			// A long pause cannot authorize publication from an expired snapshot.
			return $this->complete_import_inventory( $url );
		}
		$data = $this->normalize_manga_fields( (array) $snapshot['data'] );
		if ( ! $this->validate_manga_data( $data, false ) ) { $data = $this->preflight_manga_cover( $url, $data ); }
		unset( $data['source_hash'] );
		$data['source_hash'] = md5( wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$this->pipeline_state51( $payload );
		return $data;
	}

	private function queue_serial_current( array $state ): void {
		if ( empty( $state['active'] ) || empty( $state['id'] ) ) {
			return;
		}
		if ( 'collect51' === ( $state['workflow'] ?? '' ) && 'validate' === ( $state['phase'] ?? '' ) ) {
			// Capture failures are already decisions; do not create another job for them.
			while ( isset( $state['items'][ $state['cursor'] ] ) && 'pending' === ( $state['rows'][ $state['cursor'] ]['status'] ?? '' ) ) {
				$state['cursor']++;
				$state['verified']++;
			}
			update_option( 'mbi_serial_batch', $state, false );
		}
		$cursor = absint( $state['cursor'] ?? 0 );
		$items  = (array) ( $state['items'] ?? array() );
		if ( ! isset( $items[ $cursor ] ) ) {
			$this->finish_serial_batch( $state );
			return;
		}
		$item = (array) $items[ $cursor ];
		$url  = esc_url_raw( (string) ( $item['url'] ?? '' ) );
		if ( ! $url ) {
			$this->advance_serial_batch( (string) $state['id'], 0, false );
			return;
		}

		$state['current_url']      = $url;
		$state['current_manga_id'] = absint( $item['manga_id'] ?? 0 );
		if ( 'collect51' === ( $state['workflow'] ?? '' ) && 'validate' === ( $state['phase'] ?? '' ) ) { $state['rows'][$cursor]['status'] = 'validating'; }
		update_option( 'mbi_serial_batch', $state, false );
		$mode = sanitize_key( (string) ( $state['mode'] ?? 'starter' ) );
		$this->db->queue(
			'manga',
			array(
				'url'           => $url,
				'generation'    => (string) ( $state['generation'] ?? $this->current_generation() ),
				'serial_batch'  => (string) $state['id'],
				'serial_cursor' => $cursor,
				'pipeline_phase' => 'collect51' === ( $state['workflow'] ?? '' ) ? (string) $state['phase'] : '',
				'catalog_import' => in_array( $mode, array( 'catalog', 'catalog_full', 'auto_catalog' ), true ),
				'validation'     => in_array( $mode, array( 'validation', 'validation_amount' ), true ),
				'repair'         => in_array( $mode, array( 'validation', 'validation_amount' ), true ),
				'update_existing'=> in_array( $mode, array( 'updates', 'history_backfill' ), true ),
				'sync'           => 'updates' === $mode,
				'full'           => in_array( $mode, array( 'catalog', 'catalog_full', 'auto_catalog', 'validation', 'validation_amount', 'manual_url', 'history_backfill' ), true ),
				'manga_id_hint' => absint( $item['manga_id'] ?? 0 ),
				'dedupe'        => 'serial|' . $state['id'] . '|' . ( isset( $state['phase'] ) ? $state['phase'] . '|' : '' ) . $cursor . '|' . $url,
			),
			true
		);
		$this->db->log( 'info', 'Processando uma obra por vez.', array( 'batch' => $state['id'], 'position' => $cursor + 1, 'target' => $state['target'], 'url' => $url ) );
	}

	private function bind_serial_manga( string $batch_id, string $url, int $manga_id ): void {
		if ( ! $batch_id || ! $manga_id ) {
			return;
		}
		$state = $this->serial_state();
		if ( empty( $state['active'] ) || (string) ( $state['id'] ?? '' ) !== $batch_id || (string) ( $state['current_url'] ?? '' ) !== $url ) {
			return;
		}
		$state['current_manga_id'] = $manga_id;
		update_option( 'mbi_serial_batch', $state, false );
		update_post_meta( $manga_id, '_mbi_serial_batch_id', $batch_id );
	}

	private function advance_serial_batch( string $batch_id, int $manga_id, bool $completed ): void {
		$state = $this->serial_state();
		if ( empty( $state['active'] ) || ! $batch_id || (string) ( $state['id'] ?? '' ) !== $batch_id ) {
			return;
		}
		$current_manga_id = absint( $state['current_manga_id'] ?? 0 );
		if ( $manga_id && $current_manga_id && $manga_id !== $current_manga_id ) {
			return;
		}
		if ( 'collect51' === ( $state['workflow'] ?? '' ) ) {
			$index = absint( $state['cursor'] ?? 0 );
			if ( ! $completed ) {
				$state['rows'][$index]['status'] = 'pending';
				$state['rows'][$index]['reason'] = (string) ( get_post_meta( $manga_id, '_mbi_finalization_block', true ) ?: ( $state['last_skipped']['error'] ?? 'A obra não passou pela validação; consulte o log.' ) );
				$this->remember_rejection52( (string) ( $state['items'][$index]['url'] ?? '' ), $state['rows'][$index]['reason'] );
			} else {
				$state['rows'][$index]['status'] = 'published'; $state['rows'][$index]['reason'] = '';
				if ( ! isset( $state['first_publication_seconds'] ) ) { $state['first_publication_seconds'] = max( 0, time() - (int) ( $state['started_ts'] ?? time() ) ); }
			}
			if ( 'collect' === ( $state['phase'] ?? '' ) ) {
				$this->advance_capture51( $state );
				return;
			}
			$state['verified'] = absint( $state['verified'] ?? 0 ) + 1;
		}
		if ( $completed ) {
			$state['completed'] = absint( $state['completed'] ?? 0 ) + 1;
			$state['consecutive_source_failures'] = 0;
			$state['consecutive_network_failures52'] = 0;
		}
		$state['cursor']           = absint( $state['cursor'] ?? 0 ) + 1;
		if ( ! empty( $state['stream53'] ) ) { $state['phase'] = 'collect'; }
		$state['skipped']          = max( absint( $state['skipped'] ?? 0 ), $state['cursor'] - absint( $state['completed'] ?? 0 ) );
		$state['current_url']      = '';
		$state['current_manga_id'] = 0;
		if ( absint( $state['completed'] ?? 0 ) >= absint( $state['target'] ?? 0 ) || absint( $state['cursor'] ) >= count( (array) ( $state['items'] ?? array() ) ) ) {
			$this->finish_serial_batch( $state );
			return;
		}

		// Uma atualização pendente pode entrar entre duas obras sem misturar conteúdo incompleto.
		if ( 'updates' !== (string) ( $state['mode'] ?? '' ) && get_option( 'mbi_updates_due', false ) ) {
			$state['active'] = false;
			update_option( 'mbi_suspended_serial_batch', $state, false );
			update_option( 'mbi_serial_batch', $state, false );
			delete_option( 'mbi_updates_due' );
			$this->db->log( 'info', 'Lote pausado entre obras para priorizar capítulos novos das obras já publicadas.', array( 'mode' => $state['mode'] ?? '', 'cursor' => $state['cursor'] ?? 0 ) );
			$this->enqueue_updates_scan( true );
			return;
		}

		update_option( 'mbi_serial_batch', $state, false );
		$this->queue_serial_current( $state );
	}

	private function finish_serial_batch( array $state ): void {
		if ( $this->refill_quantity_batch( $state ) ) {
			return;
		}
		$batch_id                  = (string) ( $state['id'] ?? '' );
		$state['active']           = false;
		$state['finished_at']      = current_time( 'mysql' );
		$state['current_url']      = '';
		$state['current_manga_id'] = 0;
		$state['waiting_catalog'] = false;
		$state['finish_reason'] = absint( $state['completed'] ?? 0 ) >= absint( $state['target'] ?? 0 ) ? 'target_reached' : ( ! empty( $state['catalog_source_stopped'] ) ? 'source_unavailable' : 'candidates_exhausted' );
		if ( 'collect51' === ( $state['workflow'] ?? '' ) ) { $state['phase'] = 'done'; }
		update_option( 'mbi_serial_batch', $state, false );
		$removed = $this->db->clear_pending_for_batch( $batch_id );
		$this->db->log( 'target_reached' === $state['finish_reason'] ? 'info' : 'warning', 'Lote serial finalizado.', array( 'mode' => $state['mode'] ?? '', 'completed' => $state['completed'] ?? 0, 'target' => $state['target'] ?? 0, 'remaining' => max( 0, absint( $state['target'] ?? 0 ) - absint( $state['completed'] ?? 0 ) ), 'reason' => $state['finish_reason'], 'stale_pending_removed' => $removed ) );

		if ( 'updates' === (string) ( $state['mode'] ?? '' ) && $this->resume_suspended_batch() ) {
			return;
		}
		$pending_catalog_amount = absint( get_option( 'mbi_pending_catalog_amount', 0 ) );
		if ( $pending_catalog_amount > 0 ) {
			delete_option( 'mbi_pending_catalog_amount' );
			$this->enqueue_catalog_import( $pending_catalog_amount, 'catalog' );
			return;
		}

		// Atualizações têm prioridade após um lote de catálogo/reparo terminar.
		if ( 'updates' !== (string) ( $state['mode'] ?? '' ) && get_option( 'mbi_updates_due', false ) ) {
			delete_option( 'mbi_updates_due' );
			$this->enqueue_updates_scan( true );
		}
	}

	/** A finite catalogue cursor and a remembered selection fulfil publication quotas. */
	private function refill_quantity_batch( array $state ): bool {
		$mode = (string) ( $state['mode'] ?? '' );
		$remaining = max( 0, (int) ( $state['target'] ?? 0 ) - (int) ( $state['completed'] ?? 0 ) );
		if ( ! $remaining || ! in_array( $mode, array( 'catalog', 'auto_catalog', 'validation_amount' ), true ) || ! empty( $state['catalog_source_stopped'] ) ) { return false; }
		if ( 'collect51' === ( $state['workflow'] ?? '' ) && empty( $state['quantity_goal52'] ) ) {
			$state['quantity_goal52'] = true;
			$state['catalog_next_page'] = max( 1, (int) ( $state['catalog_next_page'] ?? 2 ) - 1 );
		}
		if ( 'collect51' === ( $state['workflow'] ?? '' ) && ! empty( $state['candidate_backlog'] ) ) {
			$next = $this->append_candidates52( $state, array() );
			if ( count( $next['items'] ) > count( $state['items'] ) ) {
				update_option( 'mbi_serial_batch', $next, false );
				$this->queue_serial_current( $next );
				$this->runtime( 'replacement', sprintf( '%d/%d publicadas. Coletando substitutas já encontradas, sem reler o catálogo.', (int) $next['completed'], (int) $next['target'] ) );
				return true;
			}
			$state['candidate_backlog'] = array();
		}
		$page = max( 1, absint( $state['catalog_next_page'] ?? 1 ) );
		$page_limit = max( 1, absint( $state['catalog_page_limit'] ?? $this->settings()['max_catalog_pages'] ) );
		if ( $page > $page_limit ) { return false; }
		$seen = (array) ( $state['catalog_seen'] ?? array() );
		foreach ( (array) $state['items'] as $item ) { $seen[$this->source_key( (string) $item['url'] )] = true; }
		$catalog = array( 'id' => wp_generate_uuid4(), 'active' => true, 'mode' => 'auto_catalog' === $mode ? $mode : 'catalog',
			'limit' => $remaining, 'candidate_limit' => $remaining, 'page' => $page, 'page_limit' => $page_limit, 'items' => array(), 'seen' => $seen,
			'generation' => $this->current_generation(), 'resume_serial_batch' => (string) $state['id'], 'serial_batch' => (string) $state['id'],
			'started_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) );
		$state['active'] = false; $state['waiting_catalog'] = true;
		$state['current_url'] = ''; $state['current_manga_id'] = 0;
		unset( $state['finished_at'], $state['finish_reason'] );
		update_option( 'mbi_serial_batch', $state, false ); update_option( 'mbi_catalog_discovery', $catalog, false );
		$this->queue_catalog_discovery_page( $catalog );
		$this->runtime( 'catalog-refill', sprintf( '%d/%d publicadas. Buscando substitutas na página %d de até %d.', (int) $state['completed'], (int) $state['target'], $page, $page_limit ) );
		return true;
	}

	/** Explicitly resume a 1.7.51 request without retrying its rejected work. */
	public function resume_quantity_goal52(): array {
		$s = $this->serial_state(); $counts = $this->db->counts();
		if ( ! empty( $s['active'] ) || ! empty( $s['waiting_catalog'] ) || ! empty( $this->catalog_discovery_status()['active'] ) || (int) ( $counts['pending'] ?? 0 ) + (int) ( $counts['processing'] ?? 0 ) > 0 ) { return array( 'queued' => false, 'message' => 'Existe trabalho ativo. Aguarde ou pause antes de completar outro pedido.' ); }
		if ( empty( $s['id'] ) || 'collect51' !== ( $s['workflow'] ?? '' ) || ! in_array( $s['mode'] ?? '', array( 'catalog', 'auto_catalog' ), true ) || (int) ( $s['completed'] ?? 0 ) >= (int) ( $s['target'] ?? 0 ) || ( $s['generation'] ?? '' ) !== $this->current_generation() ) { return array( 'queued' => false, 'message' => 'Nenhum pedido de quantidade pendente compatível. Inicie um novo pedido.' ); }
		foreach ( (array) $s['items'] as $n => $item ) { if ( 'pending' === ( $s['rows'][$n]['status'] ?? '' ) ) { $this->remember_rejection52( (string) $item['url'], (string) ( $s['rows'][$n]['reason'] ?? '' ) ); } }
		// v1.7.51 discarded the remaining URLs of the last catalogue page. Read
		// that one page again once, excluding all previously selected URLs.
		if ( empty( $s['quantity_goal52'] ) ) { $s['catalog_next_page'] = max( 1, (int) ( $s['catalog_next_page'] ?? 2 ) - 1 ); }
		elseif ( 'source_unavailable' === ( $s['finish_reason'] ?? '' ) && isset( $s['catalog_resume_page52'] ) ) { $s['catalog_next_page'] = max( 1, (int) $s['catalog_resume_page52'] ); }
		$s['quantity_goal52'] = true; $s['catalog_source_stopped'] = false; $s['consecutive_network_failures52'] = 0;
		$s['catalog_page_limit'] = max( 1, (int) ( $s['catalog_page_limit'] ?? $this->settings()['max_catalog_pages'] ) );
		$s['phase'] = 'validate'; $s['cursor'] = count( $s['items'] );
		update_option( 'mbi_serial_batch', $s, false );
		if ( ! $this->refill_quantity_batch( $s ) ) { $s['phase'] = 'done'; $s['finish_reason'] = 'candidates_exhausted'; update_option( 'mbi_serial_batch', $s, false ); return array( 'queued' => false, 'message' => 'Não restam páginas ou candidatas no limite configurado. O pedido continua não atendido.' ); }
		update_option( 'mbi_queue_paused', 0, false ); $this->kick_worker();
		return array( 'queued' => true, 'message' => 'Pedido retomado: só publicações contam; obras recusadas não serão repetidas.' );
	}

	/** Encerra uma reposição sem candidatas sem transformar falta de resultado em sucesso. */
	private function finish_empty_catalog_refill( array $catalog ): void {
		$state = $this->serial_state();
		if ( empty( $catalog['resume_serial_batch'] ) ) {
			if ( ! empty( $state['active'] ) ) { return; }
			$state = array( 'id' => wp_generate_uuid4(), 'generation' => $this->current_generation(), 'mode' => $catalog['mode'] ?? 'catalog', 'items' => array(), 'target' => absint( $catalog['limit'] ?? 0 ), 'completed' => 0, 'cursor' => 0 );
		} elseif ( (string) ( $state['id'] ?? '' ) !== (string) $catalog['resume_serial_batch'] || empty( $state['waiting_catalog'] ) ) {
			return;
		}
		$state['catalog_page_limit'] = max( 1, absint( $catalog['page_limit'] ?? 1 ) );
		$state['catalog_next_page'] = $state['catalog_page_limit'] + 1;
		$state['catalog_source_stopped'] = ! empty( $catalog['cancel_reason'] ) || absint( $catalog['consecutive_source_failures'] ?? 0 ) >= 2;
		if ( $state['catalog_source_stopped'] ) { $state['catalog_resume_page52'] = max( 1, (int) ( $catalog['last_page_scanned'] ?? $catalog['page'] ?? 1 ) ); }
		$this->finish_serial_batch( $state );
	}

	/**
	 * Lista rascunhos utilizáveis em uma ordem que gera resultado cedo: obras que
	 * já possuem menos capítulos conhecidos antes das gigantes e itens em revisão
	 * por último. Todos permanecem candidatos para que uma falha não impeça atingir
	 * a quantidade de sucessos pedida pelo administrador.
	 */
	private function incomplete_manga_candidates(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'mbi_manga',
				'post_status'    => array( 'draft', 'private', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$items = array();
		foreach ( (array) $ids as $manga_id ) {
			$manga_id = absint( $manga_id );
			$url = esc_url_raw( (string) get_post_meta( $manga_id, '_mbi_source_url', true ) );
			if ( ! $url ) {
				continue;
			}
			$items[] = array(
				'url'      => $url,
				'manga_id' => $manga_id,
				'chapters' => absint( get_post_meta( $manga_id, '_mbi_expected_chapters', true ) ),
				'review'   => 'review' === (string) get_post_meta( $manga_id, '_mbi_validation_status', true ) ? 1 : 0,
			);
		}
		usort(
			$items,
			static function ( array $a, array $b ): int {
				if ( $a['review'] !== $b['review'] ) {
					return $a['review'] <=> $b['review'];
				}
				$a_chapters = $a['chapters'] > 0 ? $a['chapters'] : PHP_INT_MAX;
				$b_chapters = $b['chapters'] > 0 ? $b['chapters'] : PHP_INT_MAX;
				return $a_chapters === $b_chapters ? $a['manga_id'] <=> $b['manga_id'] : $a_chapters <=> $b_chapters;
			}
		);
		return array_map(
			static fn( array $item ): array => array( 'url' => $item['url'], 'manga_id' => $item['manga_id'] ),
			$items
		);
	}


	private function resume_suspended_batch(): bool {
		$state = get_option( 'mbi_suspended_serial_batch', array() );
		if ( ! is_array( $state ) || empty( $state['id'] ) ) {
			return false;
		}
		delete_option( 'mbi_suspended_serial_batch' );
		$state['active'] = true;
		$state['current_url'] = '';
		$state['current_manga_id'] = 0;
		update_option( 'mbi_serial_batch', $state, false );
		$this->db->log( 'info', 'Lote anterior retomado após a verificação prioritária de atualizações.', array( 'mode' => $state['mode'] ?? '', 'cursor' => $state['cursor'] ?? 0 ) );
		$this->queue_serial_current( $state );
		return true;
	}

	public function enqueue_validate_drafts(): array {
		// Reparos sempre começam com um motor limpo. Isso impede que jobs/locks de
		// versões ou lotes anteriores contaminem a validação atual.
		$reset = $this->hard_reset_and_restart_drafts();
		return array(
			'removed' => (int) ( $reset['removed_jobs'] ?? 0 ),
			'queued'  => (int) ( $reset['queued'] ?? 0 ),
			'skipped' => (int) ( $reset['skipped'] ?? 0 ),
			'batch'   => (string) ( $reset['batch'] ?? '' ),
		);
	}

	public function catalog_discovery_status(): array {
		$state = get_option( 'mbi_catalog_discovery', array() );
		$defaults = array(
			'id' => '', 'active' => false, 'mode' => 'catalog', 'limit' => 0,
			'page' => 1, 'page_limit' => 1, 'items' => array(), 'seen' => array(),
			'candidate_limit' => 0, 'skipped_pages' => array(), 'consecutive_source_failures' => 0,
			'page_backoffs' => array(), 'generation' => '',
			'started_at' => '', 'updated_at' => '', 'finished_at' => '', 'cancelled_at' => '', 'cancel_reason' => '',
		);
		return wp_parse_args( is_array( $state ) ? $state : array(), $defaults );
	}

	private function queue_catalog_discovery_page( array $state ): int {
		$base = trailingslashit( $this->settings()['source_url'] );
		$page = max( 1, absint( $state['page'] ?? 1 ) );
		$url = 1 === $page ? $base . 'manga/' : $base . 'manga/page/' . $page . '/';
		return $this->db->queue(
			'starter',
			array(
				'url' => $url,
				'limit' => absint( $state['limit'] ?? 10 ),
				'mode' => sanitize_key( (string) ( $state['mode'] ?? 'catalog' ) ),
				'catalog_scan_id' => sanitize_text_field( (string) ( $state['id'] ?? '' ) ),
				'catalog_page' => $page,
				'generation' => $this->current_generation(),
				'dedupe' => 'catalog-discovery|' . (string) ( $state['id'] ?? '' ) . '|' . $page,
			),
			true
		);
	}

	/**
	 * Recupera a única unidade de trabalho da descoberta quando um cancelamento,
	 * timeout ou limpeza externa deixa o estado ativo sem um starter executável.
	 * Não reinicia a busca nem altera a página/candidatos já encontrados.
	 */
	public function recover_orphan_catalog_discovery(): array {
		$result = array( 'active' => false, 'recovered' => false, 'stale' => false, 'job_id' => 0, 'page' => 0 );
		$state = $this->catalog_discovery_status();
		if ( empty( $state['active'] ) || empty( $state['id'] ) ) {
			return $result;
		}
		$result['active'] = true;
		$result['page'] = max( 1, absint( $state['page'] ?? 1 ) );
		// "Parar tudo" já girava a geração na v1.6.28, porém deixava este
		// option ativo. Não podemos ressuscitar um processo que o administrador
		// acabou de encerrar: uma descoberta de geração antiga é finalizada.
		if ( get_option( 'mbi_generation_enforced', false ) ) {
			$state_generation = sanitize_text_field( (string) ( $state['generation'] ?? '' ) );
			$current_generation = $this->current_generation();
			if ( $state_generation && ! hash_equals( $current_generation, $state_generation ) ) {
				$state['active'] = false;
				$state['cancelled_at'] = current_time( 'mysql' );
				$state['cancel_reason'] = 'Processo encerrado pelo administrador; geração antiga descartada.';
				update_option( 'mbi_catalog_discovery', $state, false );
				$result['active'] = false;
				$result['stale'] = true;
				$this->runtime( 'catalog-stopped', 'A busca antiga foi encerrada definitivamente; o motor está no estágio zero.' );
				$this->db->log( 'info', 'Descoberta antiga não foi recriada após PARAR TUDO E ZERAR.', array( 'page' => $result['page'] ) );
				return $result;
			}
		}
		if ( $this->is_paused() || $this->is_serial_active() || $this->db->active_starter_count() > 0 ) {
			return $result;
		}
		if ( get_transient( 'mbi_catalog_recovery_lock' ) ) {
			return $result;
		}
		set_transient( 'mbi_catalog_recovery_lock', 1, 15 );
		$job_id = $this->queue_catalog_discovery_page( $state );
		if ( $job_id > 0 ) {
			$result['recovered'] = true;
			$result['job_id'] = $job_id;
			$state['updated_at'] = current_time( 'mysql' );
			$state['recovered_at'] = current_time( 'mysql' );
			update_option( 'mbi_catalog_discovery', $state, false );
			$this->runtime( 'catalog-recovered', sprintf( 'Busca retomada automaticamente na página %d; nenhuma obra foi reiniciada.', $result['page'] ), array( 'scan' => $state['id'], 'page' => $result['page'], 'job_id' => $job_id ) );
			$this->db->log( 'warning', 'Watchdog recriou o starter ausente da descoberta do catálogo.', $result );
		}
		return $result;
	}

	private function begin_catalog_discovery( int $limit, string $mode ): int {
		if ( $this->is_serial_active() ) {
			$this->db->log( 'warning', 'Carga de catálogo não iniciada: já existe um lote serial em andamento.' );
			return 0;
		}
		$current = $this->catalog_discovery_status();
		if ( ! empty( $current['active'] ) || $this->db->active_starter_count() > 0 ) {
			$this->db->log( 'warning', 'Carga de catálogo não iniciada: já existe uma descoberta de catálogo em andamento.' );
			return 0;
		}
		$settings = $this->settings();
		$limit = max( 1, min( 500, $limit ) );
		$mode = in_array( $mode, array( 'catalog', 'auto_catalog', 'catalog_full' ), true ) ? $mode : 'catalog';
		$candidate_limit = 'catalog_full' === $mode ? 0 : $limit;
		$state = array(
			'id' => wp_generate_uuid4(), 'active' => true, 'mode' => $mode, 'limit' => $limit,
			'page' => 1, 'page_limit' => max( 1, min( 500, (int) $settings['max_catalog_pages'] ) ),
			'items' => array(), 'seen' => array(), 'candidate_limit' => $candidate_limit,
			'skipped_pages' => array(), 'consecutive_source_failures' => 0, 'page_backoffs' => array(),
			'generation' => $this->current_generation(),
			'started_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		);
		update_option( 'mbi_catalog_discovery', $state, false );
		$id = $this->queue_catalog_discovery_page( $state );
		$this->runtime( 'catalog-discovery', 'Descoberta de catálogo preparada. Lendo no máximo uma página da origem por rodada.', array( 'scan' => $state['id'], 'page' => 1 ) );
		$this->db->log( 'info', 'Carga de catálogo solicitada em modo incremental e não bloqueante.', array( 'limit' => $limit, 'mode' => $mode, 'scan' => $state['id'] ) );
		$this->kick_worker();
		return $id;
	}

	public function cancel_catalog_discovery( string $reason = 'Carga de catálogo cancelada pelo administrador.' ): array {
		$state = $this->catalog_discovery_status();
		$was_active = ! empty( $state['active'] );
		if ( $was_active ) {
			$state['active'] = false;
			$state['cancelled_at'] = current_time( 'mysql' );
			$state['cancel_reason'] = sanitize_text_field( $reason );
			$state['updated_at'] = current_time( 'mysql' );
			update_option( 'mbi_catalog_discovery', $state, false );
		}
		$cancelled = $this->db->cancel_starter_jobs( $reason );
		$this->db->log( 'info', 'Descoberta de catálogo interrompida sem tocar em obras/capítulos já gravados.', array( 'cancelled_jobs' => $cancelled, 'reason' => $reason ) );
		return array( 'cancelled_jobs' => $cancelled, 'was_active' => $was_active );
	}

	/** Nova carga explícita: não acrescenta os rascunhos antigos ao pedido. */
	public function enqueue_new_catalog_import( int $limit = 1 ): int {
		$counts = $this->db->counts();
		if ( $this->is_serial_active() || ! empty( $this->catalog_discovery_status()['active'] ) || (int) $counts['pending'] > 0 || (int) $counts['processing'] > 0 ) {
			return 0;
		}
		$id = $this->begin_catalog_discovery( max( 1, min( 500, $limit ) ), 'catalog' );
		if ( $id ) {
			update_option( 'mbi_queue_paused', 0, false );
			$this->kick_worker();
		}
		return $id;
	}

	public function enqueue_catalog_import( int $limit = 10, string $mode = 'catalog' ): int {
		// A quantity request never changes a running batch or imports old drafts.
		if ( 'catalog_full' !== $mode ) { return $this->enqueue_new_catalog_import( $limit ); }
		$counts = $this->db->counts();
		if ( $this->is_serial_active() || ! empty( $this->catalog_discovery_status()['active'] ) || (int) $counts['pending'] > 0 || (int) $counts['processing'] > 0 ) { return 0; }
		return $this->begin_catalog_discovery( max( 1, min( 500, $limit ) ), $mode );
	}

	/** Converte com segurança uma busca antiga em conclusão prioritária de rascunhos. */
	public function migrate_catalog_to_draft_first(): array {
		$catalog = $this->catalog_discovery_status();
		$result = array( 'converted' => false, 'requested' => 0, 'queued' => 0 );
		if ( empty( $catalog['active'] ) || 'catalog' !== (string) ( $catalog['mode'] ?? '' ) ) {
			return $result;
		}
		$requested = max( 1, min( 500, absint( $catalog['limit'] ?? 1 ) ) );
		$result['requested'] = $requested;
		$this->cancel_catalog_discovery( 'Busca antiga convertida pela v1.7.0 para o fluxo rascunhos primeiro.' );
		delete_transient( 'mbi_network_pressure' );
		delete_transient( 'mbi_chapter_network_backoff' );
		$source_url = (string) $this->settings()['source_url'];
		$source_host = $this->source_host( $source_url );
		if ( $source_host ) {
			$key = $this->source_circuit_key( $source_host );
			$circuit = get_transient( $key );
			// Retry-After/429 continua sendo respeitado; esperas antigas de 5xx são
			// limpas para que o novo ritmo preventivo possa fazer uma tentativa limpa.
			if ( ! is_array( $circuit ) || ! preg_match( '/HTTP\s*429\b/i', (string) ( $circuit['last_error'] ?? '' ) ) ) {
				delete_transient( $key );
			}
		}
		$result['queued'] = $this->enqueue_catalog_import( $requested, 'catalog' );
		$result['converted'] = $result['queued'] > 0;
		return $result;
	}

	/** Inicia um lote serial de uma única obra informada diretamente pelo administrador. */
	public function enqueue_manual_manga_url( string $raw_url ): array {
		$raw_url = trim( $raw_url );
		$url     = esc_url_raw( $raw_url );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return array( 'queued' => false, 'error' => 'Informe uma URL HTTPS válida da página do mangá.' );
		}
		try {
			$this->assert_allowed_source_url( $url );
		} catch ( Throwable $exception ) {
			return array( 'queued' => false, 'error' => $exception->getMessage() );
		}

		$url  = $this->canonical_source_url( $url );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! preg_match( '#^/manga/[^/]+/$#iu', $path ) ) {
			return array( 'queued' => false, 'error' => 'Use a URL da obra no formato /manga/nome-da-obra/. URLs de capítulo, catálogo ou pesquisa não são aceitas.' );
		}
		$this->release_stale_activity_markers();
		if ( $this->is_serial_active() ) {
			return array( 'queued' => false, 'error' => 'Já existe uma importação ou reparo de obra em andamento. Aguarde a obra atual terminar.' );
		}
		if ( ! empty( $this->manual_update_progress()['active'] ) ) {
			return array( 'queued' => false, 'error' => 'Há uma publicação de novos capítulos em andamento. Finalize-a antes de importar uma obra por URL.' );
		}

		// v1.6.27: a descoberta de catálogo é uma tarefa de baixa prioridade. Uma
		// importação explícita por URL sempre vence e cancela somente os starters,
		// preservando tudo que já foi gravado. A detecção de novidades é somente leitura
		// e pode continuar coexistindo.
		$catalog = $this->catalog_discovery_status();
		if ( ! empty( $catalog['active'] ) || $this->db->active_starter_count() > 0 ) {
			$this->cancel_catalog_discovery( 'Carga de catálogo interrompida automaticamente para priorizar a importação manual por URL.' );
		}
		$busy = $this->db->active_non_starter_counts();
		if ( (int) $busy['pending'] > 0 || (int) $busy['processing'] > 0 ) {
			return array( 'queued' => false, 'error' => 'Há outro trabalho de conteúdo em andamento. Aguarde a obra/publicação atual terminar.' );
		}

		// Um sinal antigo de "pronto" não comprova que o histórico da origem foi
		// importado até o primeiro capítulo. Uma solicitação manual sempre verifica
		// a origem novamente e reutiliza os capítulos já completos.
		$existing_id = $this->find_by_source_url( 'mbi_manga', $url );
		delete_transient( 'mbi_broken_work_' . md5( $url ) );

		update_option( 'mbi_queue_paused', 0, false );
		$state = $this->start_serial_batch(
			array( array( 'url' => $url, 'manga_id' => $existing_id ) ),
			'manual_url',
			1
		);
		if ( empty( $state['active'] ) || empty( $state['id'] ) ) {
			return array( 'queued' => false, 'error' => 'Não foi possível criar o processo para essa URL.' );
		}
		$this->runtime( 'manual-url', 'Importação manual preparada: ' . $url, array( 'url' => $url, 'existing_id' => $existing_id ) );
		$this->db->log( 'info', 'Importação manual por URL iniciada.', array( 'url' => $url, 'existing_id' => $existing_id, 'batch' => $state['id'] ) );
		wp_schedule_single_event( time() + 1, 'mbi_process_queue' );
		$this->kick_worker();

		return array( 'queued' => true, 'already_complete' => false, 'existing' => (bool) $existing_id, 'manga_id' => $existing_id, 'url' => $url, 'batch' => (string) $state['id'], 'error' => '' );
	}

	/** Reconfere, por ordem, o histórico das obras publicadas sem apagar capítulos. */
	public function enqueue_history_backfill(): array {
		$this->release_stale_activity_markers();
		if ( $this->is_serial_active() || ! empty( $this->manual_update_progress()['active'] ) ) {
			return array( 'queued' => false, 'error' => 'Existe outra importação/publicação em andamento. Aguarde ou pare esse processo antes da revisão.' );
		}
		$busy = $this->db->active_non_starter_counts();
		if ( (int) $busy['pending'] > 0 || (int) $busy['processing'] > 0 ) {
			return array( 'queued' => false, 'error' => 'A fila de conteúdo ainda está ocupada.' );
		}
		$ids = get_posts( array(
			'post_type' => 'mbi_manga', 'post_status' => 'publish', 'posts_per_page' => -1,
			'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true,
		) );
		$items = array();
		$seen  = array();
		foreach ( $ids as $id ) {
			$url = esc_url_raw( (string) get_post_meta( (int) $id, '_mbi_source_url', true ) );
			if ( ! $url || ! wp_http_validate_url( $url ) ) {
				continue;
			}
			try {
				$this->assert_allowed_source_url( $url );
			} catch ( Throwable $exception ) {
				continue;
			}
			$key = $this->source_key( $url );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$items[] = array( 'url' => $this->canonical_source_url( $url ), 'manga_id' => (int) $id );
		}
		if ( ! $items ) {
			return array( 'queued' => false, 'error' => 'Nenhuma obra publicada com URL de origem autorizada foi encontrada.' );
		}
		$catalog = $this->catalog_discovery_status();
		if ( ! empty( $catalog['active'] ) || $this->db->active_starter_count() > 0 ) {
			$this->cancel_catalog_discovery( 'Busca de novos mangás interrompida para priorizar a revisão dos capítulos já publicados.' );
		}
		update_option( 'mbi_queue_paused', 0, false );
		$state = $this->start_serial_batch( $items, 'history_backfill', count( $items ) );
		if ( empty( $state['active'] ) || empty( $state['id'] ) ) {
			return array( 'queued' => false, 'error' => 'Não foi possível iniciar a revisão.' );
		}
		wp_schedule_single_event( time() + 1, 'mbi_process_queue' );
		$this->kick_worker();
		return array( 'queued' => true, 'count' => count( $items ), 'batch' => (string) $state['id'] );
	}

	public function enqueue_full_import(): int {
		return $this->begin_catalog_discovery( 500, 'catalog_full' );
	}

	/** Compatibilidade: agora significa adicionar novas obras completas ao catálogo. */
	public function enqueue_starter_import( int $limit = 30 ): int {
		return $this->enqueue_catalog_import( $limit, 'catalog' );
	}

	public function enqueue_auto_catalog_fill(): void {
		// Desativado na v1.6.0: nenhuma obra é criada sem ação explícita do administrador.
		return;
	}

	public function enqueue_updates_scan( bool $forced = false ): int {
		// Mantido apenas para compatibilidade de chamadas antigas. Detectar nunca atualiza/publica.
		return 0;
	}

	/** Compatibilidade com o hook diário antigo. */
	public function enqueue_daily_sync(): void {
		return;
	}

	/** Compatibilidade com versões anteriores. */
	public function reduce_to_validation_batch(): array {
		return $this->enqueue_validate_drafts();
	}

	public function process_queue(): array {
		$empty = array( 'claimed' => 0, 'completed' => 0, 'retrying' => 0, 'failed' => 0, 'errors' => array() );
		if ( $this->is_paused() ) { return $empty; }
		$wait = $this->worker_wait_seconds();
		if ( $wait > 0 ) { $this->schedule_worker_wakeup( $wait ); return $empty; }
		if ( ! MBI_Resource_Guard::begin( 'publicação', false ) ) { $this->schedule_worker_wakeup( max( 3, MBI_Resource_Guard::wait_seconds() ) ); return array( 'claimed' => 0, 'completed' => 0, 'retrying' => 0, 'failed' => 0, 'errors' => array() ); }
		global $wpdb; $round_started = microtime( true ); $queries_before = (int) ( $wpdb->num_queries ?? 0 );
		$summary = array();
		try { $summary = $this->process_queue_bounded(); return $summary; }
		finally {
			if ( ! empty( $summary['claimed'] ) ) { update_option( 'mbi_publication_not_before', time() + 2, false ); }
			MBI_Resource_Guard::end();
			if ( ! empty( $summary['claimed'] ) ) {
				update_option( 'mbi_worker_metrics46', array_merge( array( 'at' => time(), 'seconds' => round( microtime( true ) - $round_started, 3 ), 'queries' => max( 0, (int) ( $wpdb->num_queries ?? 0 ) - $queries_before ), 'peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 1 ), 'claimed' => (int) $summary['claimed'], 'completed' => (int) $summary['completed'] ), MBI_Resource_Guard::network_usage51() ), false );
			}
			if ( ! $this->is_paused() && ( $this->db->has_due_jobs() || ( ! empty( $summary['claimed'] ) && $this->db->next_pending_at() > 0 ) ) ) { $this->kick_worker( true ); }
		}
	}

	private function process_queue_bounded(): array {
		$this->cleanup_catalog_cutover_guard();
		$empty = array( 'claimed' => 0, 'completed' => 0, 'retrying' => 0, 'failed' => 0, 'errors' => array() );
		if ( $this->is_paused() ) {
			$compatibility = (array) get_option( 'mbi_source_compatibility_error', array() );
			$this->runtime( $compatibility ? 'source-compatibility' : 'paused', $compatibility ? 'Importação pausada por incompatibilidade no carregador da origem. Tarefa preservada. Motivo: ' . (string) ( $compatibility['error'] ?? '' ) : 'Processador pausado.' );
			return $empty;
		}
		$wait = $this->worker_wait_seconds();
		if ( $wait > 0 ) {
			$this->schedule_worker_wakeup( $wait );
			return $empty;
		}
		if ( ! $this->acquire_worker_lock() ) {
			// Não apaga a atividade do worker real quando o painel consulta a fila.
			return $empty;
		}

		// Estado ativo + zero starters era a causa do loader preso em 27% com a
		// fila vazia. O watchdog repõe somente a página que faltou.
		$this->recover_orphan_catalog_discovery();

		$state_before = $this->serial_state();
		if ( ! empty( $state_before['active'] ) && ! empty( $state_before['id'] ) ) {
			$batch_before = (string) $state_before['id'];
			$this->db->release_stale_jobs( 180 );
			$this->db->clear_orphan_serial_pending( $batch_before );
			$this->db->wake_fresh_pending_for_batch( $batch_before );
			$work_before = $this->db->batch_work_counts( $batch_before );
			if ( 0 === (int) $work_before['pending'] && 0 === (int) $work_before['processing'] ) {
				if ( absint( $state_before['current_manga_id'] ?? 0 ) ) {
					$this->finish_idle_serial_manga( $state_before );
				} else {
					$this->queue_serial_current( $state_before );
				}
			}
		}

		$settings = $this->settings();
		$profile  = $this->speed_profile();
		$limit    = 8; // Work is small even when a legacy profile says Ultra.
		$php_limit = (int) ini_get( 'max_execution_time' );
		$budget   = max( 1, MBI_Resource_Guard::remaining() );
		$parallel = 1;
		$chapter_parallel = 1; // Complete/checkpoint one chapter before claiming another.
		$manga_parallel = 1;
		$started  = microtime( true );
		$summary  = $empty;

		try {
			while ( $summary['claimed'] < $limit && microtime( true ) - $started < $budget && MBI_Resource_Guard::remaining() >= 3 && MBI_Resource_Guard::requests_remaining() > 0 && MBI_Resource_Guard::has_memory() ) {
				// Reserva uma leitura HTTP inteira antes de reivindicar o próximo grupo.
				if ( ( $summary['claimed'] > 0 && MBI_Resource_Guard::remaining() < 5 ) || MBI_Resource_Guard::wait_seconds() > 0 ) {
					break;
				}
				if ( $this->is_paused() ) {
					break;
				}
				$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
				if ( $memory_limit > 0 && memory_get_usage( true ) > $memory_limit * 0.75 ) {
					set_transient( 'mbi_memory_yield_until', time() + 15, 15 );
					$this->runtime( 'memory-yield', 'Rodada encerrada para liberar memória; a fila será retomada em outro processo.' );
					break;
				}
				$this->refresh_worker_lock();
				if ( ! $this->owns_worker_lock() ) {
					$this->runtime( 'reset-stop', 'Worker antigo interrompido porque o processo foi reiniciado.' );
					break;
				}
				$state    = $this->serial_state();
				$batch_id = ! empty( $state['active'] ) ? (string) ( $state['id'] ?? '' ) : '';

				// Se a origem do lote atual entrou em 522/timeout, não continue reivindicando
				// dezenas de jobs que certamente falharão. O lote permanece intacto e retoma
				// sozinho quando o cooldown expirar.
				$current_url = esc_url_raw( (string) ( $state['current_url'] ?? '' ) );
				$source_wait = $current_url ? $this->source_circuit_remaining( $current_url ) : 0;
				if ( $source_wait > 0 ) {
					$this->runtime(
						'source-cooldown',
						sprintf( 'Origem temporariamente indisponível. Retomada automática em cerca de %d min, sem consumir novas conexões.', max( 1, (int) ceil( $source_wait / 60 ) ) ),
						array( 'host' => $this->source_host( $current_url ), 'remaining' => $source_wait, 'batch' => $batch_id )
					);
					break;
				}

				$job      = $this->db->claim_next( $batch_id );
				if ( ! $job ) {
					$this->runtime( 'waiting', 'Nenhum job reivindicável neste instante; aguardando a próxima rodada.', array( 'batch' => $batch_id ) );
					break;
				}
				$this->runtime( 'processing-' . sanitize_key( (string) $job->job_type ), 'Executando ' . $job->job_type . ': ' . (string) ( $job->payload['url'] ?? '' ), array( 'job_id' => (int) $job->id, 'batch' => $batch_id ) );

				if ( 'image' === $job->job_type && $parallel > 1 ) {
					$jobs = array( $job );
					$remaining = min( $parallel - 1, $limit - $summary['claimed'] - 1 );
					if ( $remaining > 0 ) {
						$jobs = array_merge( $jobs, $this->db->claim_additional_images( $batch_id, $remaining ) );
					}
					$summary['claimed'] += count( $jobs );
					$this->process_image_group( $jobs, $settings, $summary );
					continue;
				}

				$current_manga_parallel = $this->safe_manga_parallelism( $manga_parallel );
				if ( 'manga' === $job->job_type && $current_manga_parallel > 1 ) {
					$jobs = array( $job );
					$remaining = min( $current_manga_parallel - 1, $limit - $summary['claimed'] - 1 );
					if ( $remaining > 0 ) {
						$jobs = array_merge( $jobs, $this->db->claim_additional_mangas( $batch_id, $remaining ) );
					}
					$summary['claimed'] += count( $jobs );
					$this->process_manga_group( $jobs, $settings, $summary );
					continue;
				}

				$current_chapter_parallel = $this->safe_chapter_parallelism( $chapter_parallel );
				if ( 'chapter' === $job->job_type && $current_chapter_parallel > 1 ) {
					$jobs = array( $job );
					$remaining = min( $current_chapter_parallel - 1, $limit - $summary['claimed'] - 1 );
					if ( $remaining > 0 ) {
						$jobs = array_merge( $jobs, $this->db->claim_additional_chapters( $batch_id, $remaining ) );
					}
					$summary['claimed'] += count( $jobs );
					$this->process_chapter_group( $jobs, $settings, $summary );
					continue;
				}

				$summary['claimed']++;
				MBI_Resource_Guard::track( $job );
				try { $this->process_single_regular_job( $job, $settings, $summary ); }
				finally { MBI_Resource_Guard::track( null ); $this->parser->release(); $this->prefetched_html = array(); }
				// Descoberta de catálogo é deliberadamente uma página por request.
				// Isso impede o starter de monopolizar o PHP por minutos e deixa ações
				// manuais disponíveis entre as páginas.
				if ( 'starter' === (string) $job->job_type ) {
					break;
				}
			}
		} finally {
			$this->release_worker_lock();
		}

		$state    = $this->serial_state();
		$batch_id = ! empty( $state['active'] ) ? (string) ( $state['id'] ?? '' ) : '';
		if ( ! $this->is_paused() ) {
			$wait = $this->worker_wait_seconds();
			if ( $wait > 0 ) {
				$this->schedule_worker_wakeup( $wait );
			} elseif ( $this->db->has_due_jobs( $batch_id ) ) {
				$this->kick_worker();
			}
		}
		return $summary;
	}

	private function acquire_worker_lock(): bool {
		$now = time();
		$token = wp_generate_uuid4();
		$value = array( 'token' => $token, 'time' => $now );
		if ( add_option( 'mbi_worker_lock', $value, '', false ) ) {
			$this->worker_lock_token = $token;
			return true;
		}

		$existing = get_option( 'mbi_worker_lock', 0 );
		$locked_at = is_array( $existing ) ? (int) ( $existing['time'] ?? 0 ) : (int) $existing;
		if ( ! $locked_at || $locked_at < $now - 180 ) {
			delete_option( 'mbi_worker_lock' );
			if ( add_option( 'mbi_worker_lock', $value, '', false ) ) {
				$this->worker_lock_token = $token;
				return true;
			}
		}
		return false;
	}

	private function refresh_worker_lock(): void {
		wp_cache_delete( 'mbi_worker_lock', 'options' );
		if ( ! $this->worker_lock_token ) {
			return;
		}
		$existing = get_option( 'mbi_worker_lock', array() );
		if ( is_array( $existing ) && hash_equals( $this->worker_lock_token, (string) ( $existing['token'] ?? '' ) ) ) {
			update_option( 'mbi_worker_lock', array( 'token' => $this->worker_lock_token, 'time' => time() ), false );
		}
	}


	private function owns_worker_lock(): bool {
		wp_cache_delete( 'mbi_worker_lock', 'options' );
		if ( ! $this->worker_lock_token ) {
			return false;
		}
		$existing = get_option( 'mbi_worker_lock', array() );
		return is_array( $existing ) && ! empty( $existing['token'] ) && hash_equals( $this->worker_lock_token, (string) $existing['token'] );
	}

	private function clear_stale_worker_lock( int $age_seconds = 180 ): bool {
		$existing = get_option( 'mbi_worker_lock', 0 );
		if ( ! $existing ) {
			return false;
		}
		$locked_at = is_array( $existing ) ? (int) ( $existing['time'] ?? 0 ) : (int) $existing;
		if ( ! $locked_at || $locked_at < time() - max( 120, $age_seconds ) ) {
			delete_option( 'mbi_worker_lock' );
			return true;
		}
		return false;
	}

	private function release_worker_lock(): void {
		wp_cache_delete( 'mbi_worker_lock', 'options' );
		if ( ! $this->worker_lock_token ) {
			return;
		}
		$existing = get_option( 'mbi_worker_lock', array() );
		if ( is_array( $existing ) && hash_equals( $this->worker_lock_token, (string) ( $existing['token'] ?? '' ) ) ) {
			delete_option( 'mbi_worker_lock' );
		}
		$this->worker_lock_token = '';
	}

	/** Retorna a espera real, usada tanto pelo painel como pelo despachante. */
	public function worker_wait_seconds(): int {
		$state = $this->serial_state();
		$batch = ! empty( $state['active'] ) ? (string) ( $state['id'] ?? '' ) : '';
		$url = $batch ? (string) ( $state['current_url'] ?? '' ) : '';
		$catalog = $this->catalog_discovery_status();
		if ( ! $url && ! empty( $catalog['active'] ) ) {
			$url = (string) $this->settings()['source_url'];
		}
		$source_wait = $url ? $this->source_circuit_remaining( $url ) : 0;
		$focus = (array) get_option( 'mbi_validation_focus', array() );
		$focus_wait = ( $focus['until'] ?? 0 ) > time() && ( $focus['batch'] ?? '' ) === $batch && ( $focus['generation'] ?? '' ) === (string) get_option( 'mbi_process_generation', '' ) ? max( 0, (int) ( $focus['after'] ?? 0 ) - time() ) : 0;
		$due = $this->db->next_pending_at( $batch );
		return max( max( 0, (int) get_option( 'mbi_publication_not_before', 0 ) - time() ), MBI_Resource_Guard::wait_seconds(), $source_wait, $focus_wait, $due > 0 ? $due - time() : 0, (int) get_transient( 'mbi_memory_yield_until' ) - time(), 0 );
	}

	private function schedule_worker_wakeup( int $delay ): void {
		// Never remove a live event just to move it earlier: recurring cron is fallback.
		if ( wp_next_scheduled( 'mbi_queue_wakeup' ) ) { return; }
		$result = wp_schedule_single_event( time() + max( 2, $delay ), 'mbi_queue_wakeup', array(), true );
		if ( ( is_wp_error( $result ) || false === $result ) && ! get_transient( 'mbi_wakeup_save_warning' ) ) {
			set_transient( 'mbi_wakeup_save_warning', 1, MINUTE_IN_SECONDS );
			$this->db->log( 'warning', 'Falha ao salvar despertar da fila; cron recorrente permanece como alternativa.', array( 'error' => is_wp_error( $result ) ? $result->get_error_code() : 'schedule_failed' ) );
		}
	}

	public function kick_worker( bool $short_resume = false ): void {
		$settings = $this->settings();
		if ( $this->is_paused() || empty( $settings['continuous_worker'] ) ) {
			return;
		}
		$wait = $this->worker_wait_seconds();
		if ( $wait > 0 && ( ! $short_resume || $wait > 3 ) ) {
			$this->schedule_worker_wakeup( $wait );
			return;
		}
		if ( MBI_Resource_Guard::status()['lane'] ) { $this->schedule_worker_wakeup( 3 ); return; }
		if ( get_transient( 'mbi_worker_dispatching' ) ) {
			return;
		}
		set_transient( 'mbi_worker_dispatching', 1, 5 );
		$token = (string) get_option( 'mbi_worker_token', '' );
		if ( ! $token ) {
			$token = wp_generate_password( 48, false, false );
			update_option( 'mbi_worker_token', $token, false );
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.5,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array( 'action' => 'mbi_worker_tick', 'token' => $token, 'short_resume' => $short_resume ? 1 : 0 ),
			)
		);
	}

	public function worker_tick(): void {
		$provided = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$expected = (string) get_option( 'mbi_worker_token', '' );
		if ( ! $expected || ! hash_equals( $expected, $provided ) ) {
			status_header( 403 );
			exit;
		}
		// Only an authenticated continuation may bridge a short checkpoint gap.
		// Re-read all pause/cooldown/ownership gates in process_queue after the wait.
		if ( ! empty( $_POST['short_resume'] ) && ! $this->is_paused() ) {
			$wait = $this->worker_wait_seconds();
			if ( $wait > 0 && $wait <= 3 ) { usleep( $wait * 1000000 ); }
		}
		delete_transient( 'mbi_worker_dispatching' );
		$this->process_queue();
		status_header( 204 );
		exit;
	}

	public function is_paused(): bool {
		wp_cache_delete( 'mbi_queue_paused', 'options' );
		return (bool) get_option( 'mbi_queue_paused', false );
	}

	public function pause(): void {
		update_option( 'mbi_queue_paused', 1, false );
		$this->db->log( 'info', 'Importação pausada manualmente.' );
	}

	public function resume(): void {
		delete_option( 'mbi_source_compatibility_error' );
		update_option( 'mbi_queue_paused', 0, false );
		$this->db->log( 'info', 'Importação retomada manualmente.' );
	}

	/** Força somente a próxima retentativa do lote atual a ficar disponível agora. */
	public function force_next_retry_now(): array {
		$state = $this->serial_state();
		$batch_id = ! empty( $state['active'] ) ? sanitize_text_field( (string) ( $state['id'] ?? '' ) ) : '';
		$woken = $this->db->force_next_retry_now( $batch_id );
		if ( $woken > 0 ) {
			$this->runtime( 'retry-forced', 'Retentativa liberada para execução imediata.', array( 'batch' => $batch_id ) );
			wp_schedule_single_event( time() + 1, 'mbi_process_queue' );
			$this->kick_worker();
		}
		return array( 'woken' => $woken, 'batch' => $batch_id );
	}

	public function cancel_pending(): int {
		$count = $this->db->cancel_pending_jobs();
		$this->cancel_catalog_discovery( 'Busca de catálogo encerrada junto com o cancelamento das pendências.' );
		wp_clear_scheduled_hook( 'mbi_queue_wakeup' );
		delete_transient( 'mbi_catalog_recovery_lock' );
		$state = $this->serial_state();
		if ( $state ) {
			$state['active'] = false;
			$state['cancelled_at'] = current_time( 'mysql' );
			update_option( 'mbi_serial_batch', $state, false );
		}
		delete_option( 'mbi_suspended_serial_batch' );
		delete_option( 'mbi_updates_due' );
		delete_option( 'mbi_pending_catalog_amount' );
		$this->db->log( 'info', 'Pendências e lote serial atual cancelados manualmente.', array( 'removed_jobs' => $count ) );
		return $count;
	}

	/** Pula somente a obra atual, preserva conteúdo e continua o lote serial. */
	public function skip_current_serial_manga(): array {
		$state = $this->serial_state();
		if ( empty( $state['active'] ) || empty( $state['id'] ) ) {
			return array( 'skipped' => false, 'removed_jobs' => 0, 'error' => 'Nenhuma obra está sendo processada neste momento.' );
		}
		$batch_id = sanitize_text_field( (string) $state['id'] );
		$manga_id = absint( $state['current_manga_id'] ?? 0 );
		$url = esc_url_raw( (string) ( $state['current_url'] ?? '' ) );
		$removed = $this->db->purge_active_content_jobs_for_batch( $batch_id );
		if ( $manga_id > 0 ) {
			if ( 'publish' !== get_post_status( $manga_id ) ) {
				update_post_meta( $manga_id, '_mbi_ready', 0 );
			}
			update_post_meta( $manga_id, '_mbi_validation_status', 'review' );
			update_post_meta( $manga_id, '_mbi_last_import_error', 'Obra pulada manualmente pelo administrador.' );
			update_post_meta( $manga_id, '_mbi_validation_checked_at', current_time( 'mysql' ) );
		}
		$host = $this->source_host( $url );
		if ( $host ) {
			delete_transient( $this->source_circuit_key( $host ) );
		}
		delete_transient( 'mbi_network_pressure' );
		delete_transient( 'mbi_chapter_network_backoff' );
		$this->db->log( 'warning', 'Obra atual pulada manualmente; avançando sem apagar conteúdo.', array( 'batch' => $batch_id, 'manga_id' => $manga_id, 'url' => $url, 'removed_jobs' => $removed ) );
		$state['last_skipped'] = array( 'url' => $url, 'error' => 'Obra pulada manualmente pelo administrador.', 'at' => current_time( 'mysql' ) );
		update_option( 'mbi_serial_batch', $state, false );
		$this->advance_serial_batch( $batch_id, $manga_id, false );
		$this->runtime( 'manga-skipped', 'Obra atual enviada para revisão; iniciando a próxima candidata.', array( 'manga_id' => $manga_id, 'url' => $url ) );
		wp_schedule_single_event( time() + 1, 'mbi_process_queue' );
		$this->kick_worker();
		return array( 'skipped' => true, 'removed_jobs' => $removed, 'manga_id' => $manga_id, 'url' => $url, 'error' => '' );
	}

	/**
	 * Interrompe qualquer motor do plugin e devolve a fila ao estágio zero.
	 * Conteúdo já gravado (obras, capítulos, termos e metadados) nunca é removido.
	 */
	public function stop_all_and_reset(): array {
		update_option( 'mbi_media_daily_enabled', 0, false ); wp_clear_scheduled_hook( 'mbi_media_daily_scan' );
		foreach ( array( 'mbi_root_reconcile', 'mbi_source_audit' ) as $key ) { $state = (array) get_option( $key, array() ); $state['active'] = false; $state['id'] = wp_generate_uuid4(); update_option( $key, $state, false ); }
		$daily = (array) get_option( 'mbi_media_daily', array() );
		$daily['active'] = false; $daily['id'] = wp_generate_uuid4(); $daily['message'] = 'Parado pelo controle geral.';
		update_option( 'mbi_media_daily', $daily, false );
		delete_option( 'mbi_media_daily_deferred' );
		wp_clear_scheduled_hook( 'mbi_media_daily_continue' );
		$cover_refresh = (array) get_option( 'mbi_cover_refresh', array() );
		$cover_refresh['active'] = false; $cover_refresh['paused'] = false; $cover_refresh['stage'] = 'stopped';
		$cover_refresh['id'] = wp_generate_uuid4();
		update_option( 'mbi_cover_refresh', $cover_refresh, false );
		$diagnostic = (array) get_option( 'mbi_global_diagnostic', array() );
		$diagnostic['active'] = false;
		$diagnostic['paused'] = false;
		$diagnostic['stage'] = 'stopped';
		update_option( 'mbi_global_diagnostic', $diagnostic, false );
		$media_state = (array) get_option( 'mbi_media_repair', array() );
		$media_state['active'] = false;
		update_option( 'mbi_media_repair', $media_state, false );
		update_option( 'mbi_queue_paused', 1, false );
		$generation = $this->rotate_generation();
		$removed    = $this->db->purge_all_jobs();

		delete_option( 'mbi_serial_batch' );
		delete_option( 'mbi_suspended_serial_batch' );
		delete_option( 'mbi_updates_due' );
		delete_option( 'mbi_manual_update_batch' );
		delete_option( 'mbi_update_scan' );
		delete_option( 'mbi_detected_updates' );
		delete_option( 'mbi_runtime_status' );
		delete_option( 'mbi_worker_lock' );
		delete_option( 'mbi_legacy_media_cleanup' );
		delete_option( 'mbi_proxy_migration' );
		delete_option( 'mbi_rankmath_backfill_page' );
		delete_option( 'mbi_catalog_discovery' );
		delete_option( 'mbi_catalog_cutover_guard_until' );
		delete_transient( 'mbi_worker_dispatching' );
		delete_transient( 'mbi_update_scan_lock' );
		delete_transient( 'mbi_catalog_recovery_lock' );
		delete_transient( 'mbi_network_pressure' );
		delete_transient( 'mbi_chapter_network_backoff' );
		delete_transient( 'mbi_update_network_backoff' );
		delete_transient( 'mbi_memory_yield_until' );
		$source_host = $this->source_host( (string) $this->settings()['source_url'] );
		if ( $source_host ) {
			delete_transient( $this->source_circuit_key( $source_host ) );
		}
		wp_clear_scheduled_hook( 'mbi_queue_wakeup' );
		wp_clear_scheduled_hook( 'mbi_cleanup_legacy_media' );
		wp_clear_scheduled_hook( 'mbi_proxy_migration' );
		wp_clear_scheduled_hook( 'mbi_prepare_manual_updates' );
		wp_clear_scheduled_hook( 'mbi_rankmath_backfill' );
		wp_clear_scheduled_hook( 'mbi_daily_sync' );
		wp_clear_scheduled_hook( 'mbi_updates_sync' );
		wp_clear_scheduled_hook( 'mbi_auto_catalog_fill' );
		delete_post_meta_by_key( '_mbi_update_in_progress' );

		// A fila fica vazia e desbloqueada para aceitar uma nova ação manual.
		update_option( 'mbi_queue_paused', 0, false );
		$this->db->log(
			'warning',
			'Parada total executada: motor, locks e tarefas foram zerados; o conteúdo publicado foi preservado.',
			array( 'removed_jobs' => $removed, 'generation' => $generation )
		);

		return array( 'removed_jobs' => $removed, 'generation' => $generation );
	}

	public function detected_updates(): array {
		$updates = get_option( 'mbi_detected_updates', array() );
		return is_array( $updates ) ? array_values( $updates ) : array();
	}

	/** Estado público da varredura manual, usado pelo painel em tempo real. */
	public function update_scan_progress(): array {
		$state = get_option( 'mbi_update_scan', array() );
		$state = is_array( $state ) ? $state : array();
		$state = wp_parse_args(
			$state,
			array(
				'id'              => '',
				'active'          => false,
				'phase'           => 'catalog',
				'total'           => 0,
				'checked'         => 0,
				'with_updates'    => 0,
				'without_updates' => 0,
				'partial'         => 0,
				'failed'          => 0,
				'found_chapters'  => 0,
				'current_manga'   => '',
				'items'           => array(),
				'error'           => '',
				'catalog_pages_scanned' => 0,
				'catalog_checked' => 0,
				'catalog_total'   => 0,
				'scan_mode'       => 'fast_recent_incremental',
				'paused_until'    => 0,
			)
		);
		$state['items']   = is_array( $state['items'] ) ? array_values( $state['items'] ) : array();
		$state['total']   = max( 0, (int) $state['total'] );
		$state['checked'] = min( $state['total'], max( 0, (int) $state['checked'] ) );
		$phase = sanitize_key( (string) ( $state['phase'] ?? 'catalog' ) );

		$pause_remaining = max( 0, (int) ( $state['paused_until'] ?? 0 ) - time() );
		if ( ! empty( $state['active'] ) && ( $state['budget_wait_until'] ?? 0 ) > time() ) {
			$state['status'] = 'INTERVALO ENTRE RODADAS';
			$state['title'] = 'Detecção preservada para a próxima rodada';
			$state['message'] = 'A próxima leitura terá sua janela completa; nenhuma candidata foi marcada com falha.';
			$state['visual'] = 'running';
		} elseif ( ! empty( $state['active'] ) && $pause_remaining > 0 ) {
			$state['percent'] = 'catalog' === $phase ? min( 15, (int) ( $state['percent'] ?? 0 ) ) : min( 99, (int) ( $state['percent'] ?? 0 ) );
			$state['status']  = 'ORIGEM EM ESPERA';
			$state['title']   = 'Pausa automática para não sobrecarregar a fonte';
			$state['message'] = sprintf( 'A origem respondeu com erro de disponibilidade. Nova tentativa automática em cerca de %d min; nenhum novo request será enviado até lá.', max( 1, (int) ceil( $pause_remaining / 60 ) ) );
			$state['visual']  = 'warning';
		} elseif ( ! empty( $state['active'] ) && 'catalog' === $phase ) {
			$catalog_total   = max( 1, (int) ( $state['catalog_total'] ?? 1 ) );
			$catalog_checked = min( $catalog_total, max( 0, (int) ( $state['catalog_checked'] ?? 0 ) ) );
			$state['percent'] = min( 15, (int) floor( ( $catalog_checked / $catalog_total ) * 15 ) );
			$state['status']  = 'LENDO NOVIDADES';
			$state['title']   = 'Mapeando a janela recente';
			$state['message'] = sprintf(
				'%d de até %d páginas recentes consultadas.%s',
				$catalog_checked,
				$catalog_total,
				! empty( $state['coexist_mode'] ) ? ' Importação em andamento: a detecção está usando modo de baixo impacto.' : ' Modo rápido ativo sem varrer o catálogo inteiro.'
			);
			$state['visual']  = 'running';
		} elseif ( ! empty( $state['active'] ) ) {
			$manga_percent = $state['total'] > 0 ? min( 100, (int) floor( ( $state['checked'] / $state['total'] ) * 100 ) ) : 0;
			$state['percent'] = min( 99, 15 + (int) floor( $manga_percent * 0.84 ) );
			$state['status']  = 'VERIFICANDO';
			$state['title']   = 'Detecção rápida em lotes';
			$state['message'] = sprintf(
				'%d de %d obras recentes verificadas.%s',
				$state['checked'],
				$state['total'],
				! empty( $state['coexist_mode'] ) ? ' A importação principal continua em paralelo; a detecção limita a própria concorrência.' : ' O motor consulta somente a janela recente.'
			);
			$state['visual']  = 'running';
		} elseif ( ! empty( $state['error'] ) && $state['total'] < 1 ) {
			$state['percent'] = 0;
			$state['status']  = 'NÃO CONCLUÍDO';
			$state['title']   = 'A detecção não pôde continuar';
			$state['message'] = sanitize_text_field( (string) $state['error'] );
			$state['visual']  = 'warning';
		} elseif ( $state['total'] > 0 && ( $state['failed'] > 0 || (int) ( $state['partial'] ?? 0 ) > 0 ) ) {
			$state['percent'] = 100;
			$state['status']  = 'CONCLUÍDO COM ALERTAS';
			$state['title']   = 'Verificação finalizada';
			$state['message'] = sprintf( '%d obras examinadas: %d falha(s) e %d lista(s) parciais. Listas parciais não comprovam ausência de novidades; capítulos existentes foram preservados.', $state['checked'], (int) $state['failed'], (int) ( $state['partial'] ?? 0 ) );
			$state['visual']  = 'warning';
		} elseif ( $state['total'] > 0 ) {
			$state['percent'] = 100;
			$state['status']  = 'CONCLUÍDO';
			$state['title']   = 'Janela recente verificada';
			$state['message'] = sprintf( '%d obras recentes verificadas; %d com novidades e %d sem novos capítulos. Não foi necessário varrer o catálogo local inteiro.', $state['checked'], (int) $state['with_updates'], (int) $state['without_updates'] );
			$state['visual']  = 'completed';
		} else {
			$state['percent'] = 0;
			$state['status']  = 'AGUARDANDO';
			$state['title']   = 'Nenhuma verificação em andamento';
			$state['message'] = 'Clique em “Detectar novos capítulos agora”. O modo rápido cruza somente a janela recente da origem com o catálogo local.';
			$state['visual']  = 'idle';
		}

		return $state;
	}

	/**
	 * Retorna as origens de catálogo configuradas sem duplicar variantes www.
	 * O custo da detecção passa a depender das páginas recentes, não do total de mangás locais.
	 */
	private function update_source_bases(): array {
		$settings = $this->settings();
		$hosts = preg_split( '/[\r\n,]+/', (string) ( $settings['allowed_hosts'] ?? '' ) );
		$primary_host = strtolower( (string) wp_parse_url( (string) ( $settings['source_url'] ?? '' ), PHP_URL_HOST ) );
		$ordered = array();
		if ( $primary_host ) {
			$ordered[] = $primary_host;
		}
		foreach ( (array) $hosts as $host ) {
			$host = strtolower( trim( (string) $host ) );
			if ( $host ) {
				$ordered[] = $host;
			}
		}

		$bases = array();
		foreach ( $ordered as $host ) {
			$host = preg_replace( '/^www\./i', '', $host );
			if ( ! $host || ! preg_match( '/^[a-z0-9.-]+$/i', $host ) ) {
				continue;
			}
			$bases[ $host ] = 'https://' . $host;
		}
		return array_values( $bases );
	}

	/** Localiza em poucas consultas somente as obras que apareceram na janela recente da origem. */
	private function manga_update_index_for_urls( array $urls ): array {
		global $wpdb;
		$urls = array_values( array_unique( array_filter( array_map( fn( $url ) => $this->canonical_source_url( (string) $url ), $urls ) ) ) );
		if ( ! $urls ) {
			return array();
		}

		$url_by_key = array();
		foreach ( $urls as $url ) {
			$url_by_key[ $this->source_key( $url ) ] = $url;
		}
		$index = array();
		$ids   = array();
		$keys  = array_keys( $url_by_key );

		foreach ( array_chunk( $keys, 250 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql = $wpdb->prepare(
				"SELECT p.ID, p.post_title, pm.meta_value source_key
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_mbi_source_key'
				 WHERE p.post_type = 'mbi_manga' AND p.post_status = 'publish'
				   AND pm.meta_value IN ({$placeholders})
				 ORDER BY p.ID ASC",
				...$chunk
			);
			foreach ( (array) $wpdb->get_results( $sql ) as $row ) {
				$key = (string) $row->source_key;
				if ( ! isset( $url_by_key[ $key ] ) || isset( $index[ $url_by_key[ $key ] ] ) ) {
					continue;
				}
				$url = $url_by_key[ $key ];
				$index[ $url ] = array(
					'manga_id'        => (int) $row->ID,
					'manga_name'      => sanitize_text_field( (string) $row->post_title ),
					'manga_url'       => $url,
					'status'          => 'pending',
					'new_count'       => 0,
					'chapter_numbers' => array(),
					'checked_at'      => '',
					'error'           => '',
				);
				$ids[] = (int) $row->ID;
			}
		}

		// Compatibilidade com obras antigas que ainda não têm _mbi_source_key.
		$missing = array_values( array_filter( $urls, static fn( $url ) => ! isset( $index[ $url ] ) ) );
		if ( $missing ) {
			$variants = array();
			foreach ( $missing as $url ) {
				$variants[ $url ] = $url;
				$parts = wp_parse_url( $url );
				if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
					$host = (string) $parts['host'];
					$www  = 'www.' . preg_replace( '/^www\./i', '', $host );
					$variants[ str_replace( '://' . $host . '/', '://' . $www . '/', $url ) ] = $url;
				}
			}
			foreach ( array_chunk( array_keys( $variants ), 200 ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				$sql = $wpdb->prepare(
					"SELECT p.ID, p.post_title, pm.meta_value source_url
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_mbi_source_url'
					 WHERE p.post_type = 'mbi_manga' AND p.post_status = 'publish'
					   AND pm.meta_value IN ({$placeholders})
					 ORDER BY p.ID ASC",
					...$chunk
				);
				foreach ( (array) $wpdb->get_results( $sql ) as $row ) {
					$canonical = $this->canonical_source_url( (string) $row->source_url );
					if ( ! $canonical || ! in_array( $canonical, $missing, true ) || isset( $index[ $canonical ] ) ) {
						continue;
					}
					$index[ $canonical ] = array(
						'manga_id'        => (int) $row->ID,
						'manga_name'      => sanitize_text_field( (string) $row->post_title ),
						'manga_url'       => $canonical,
						'status'          => 'pending',
						'new_count'       => 0,
						'chapter_numbers' => array(),
						'checked_at'      => '',
						'error'           => '',
					);
					$ids[] = (int) $row->ID;
					update_post_meta( (int) $row->ID, '_mbi_source_key', $this->source_key( $canonical ) );
				}
			}
		}

		if ( $ids ) {
			update_meta_cache( 'post', array_values( array_unique( $ids ) ) );
			foreach ( $index as $url => $item ) {
				$original = (string) get_post_meta( (int) $item['manga_id'], '_mbi_original_title', true );
				if ( $original ) {
					$index[ $url ]['manga_name'] = sanitize_text_field( $original );
				}
			}
		}
		return $index;
	}

	/** Quantidade segura de páginas de obra consultadas simultaneamente na detecção. */

	private function update_scan_batch_size(): int {
		return 1; /* Detection shares the same resource lane as publication. */
	}

	private function legacy_update_scan_batch_size(): int {
		if ( get_transient( 'mbi_network_pressure' ) || get_transient( 'mbi_update_network_backoff' ) ) {
			return 1;
		}
		$counts = $this->db->counts();
		$import_busy = (int) ( $counts['pending'] ?? 0 ) > 0 || (int) ( $counts['processing'] ?? 0 ) > 0;
		if ( $import_busy ) {
			// A detecção pode coexistir com importações, mas usa só uma obra por rodada.
			return 1;
		}
		$speed = sanitize_key( (string) ( $this->settings()['processing_speed'] ?? 'ultra' ) );
		$size = array( 'normal' => 1, 'fast' => 2, 'turbo' => 3, 'ultra' => 3 )[ $speed ] ?? 3;
		return max( 1, min( 3, $size ) );
	}

	/** Busca um pequeno lote de páginas de obra em paralelo, sem abrir imagens nem o Worker. */

	private function parallel_fetch_update_htmls( array $items ): array {
		$results = array();
		if ( count( $items ) < 2 || ! function_exists( 'curl_multi_init' ) ) {
			foreach ( $items as $index => $item ) {
				try {
					$url = esc_url_raw( (string) ( $item['manga_url'] ?? '' ) );
					$results[ (int) $index ] = array( 'ok' => true, 'html' => $this->fetch_html( $url ) );
				} catch ( MBI_Resource_Wait $exception ) {
					$results[ (int) $index ] = array( 'ok' => false, 'error' => $exception->getMessage(), 'wait' => $exception->delay );
				} catch ( Throwable $exception ) {
					$results[ (int) $index ] = array( 'ok' => false, 'error' => $exception->getMessage() );
				}
			}
			return $results;
		}

		$multi = curl_multi_init();
		if ( defined( 'CURLMOPT_MAX_TOTAL_CONNECTIONS' ) ) {
			curl_multi_setopt( $multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 2 );
		}
		if ( defined( 'CURLMOPT_MAX_HOST_CONNECTIONS' ) ) {
			curl_multi_setopt( $multi, CURLMOPT_MAX_HOST_CONNECTIONS, 2 );
		}
		if ( defined( 'CURLMOPT_PIPELINING' ) && defined( 'CURLPIPE_MULTIPLEX' ) ) {
			curl_multi_setopt( $multi, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX );
		}

		$share = null;
		if ( function_exists( 'curl_share_init' ) && defined( 'CURLOPT_SHARE' ) && defined( 'CURLSHOPT_SHARE' ) && defined( 'CURL_LOCK_DATA_DNS' ) ) {
			$share = curl_share_init();
			if ( $share ) {
				curl_share_setopt( $share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS );
				if ( defined( 'CURL_LOCK_DATA_SSL_SESSION' ) ) {
					@curl_share_setopt( $share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION );
				}
			}
		}

		$handles = array();
		foreach ( $items as $index => $item ) {
			$url = esc_url_raw( (string) ( $item['manga_url'] ?? '' ) );
			try {
				$this->assert_allowed_source_url( $url );
			} catch ( Throwable $exception ) {
				$results[ (int) $index ] = array( 'ok' => false, 'error' => $exception->getMessage() );
				continue;
			}
			$remaining = $this->source_circuit_remaining( $url );
			if ( $remaining > 0 ) {
				$results[ (int) $index ] = array( 'ok' => false, 'error' => 'SOURCE_COOLDOWN ' . $remaining . 's' );
				continue;
			}

			$ch = curl_init( $url );
			$options = array(
				CURLOPT_RETURNTRANSFER    => true,
				CURLOPT_FOLLOWLOCATION    => true,
				CURLOPT_MAXREDIRS         => 4,
				CURLOPT_CONNECTTIMEOUT    => 7,
				CURLOPT_TIMEOUT           => 22,
				CURLOPT_ENCODING          => '',
				CURLOPT_HTTPHEADER        => array(
					'User-Agent: ' . $this->user_agent(),
					'Accept: text/html,application/xhtml+xml',
					'Connection: keep-alive',
				),
				CURLOPT_SSL_VERIFYPEER    => true,
				CURLOPT_SSL_VERIFYHOST    => 2,
				CURLOPT_DNS_CACHE_TIMEOUT => 600,
				CURLOPT_FORBID_REUSE      => false,
				CURLOPT_FRESH_CONNECT     => false,
			);
			if ( $share ) {
				$options[ CURLOPT_SHARE ] = $share;
			}
			if ( defined( 'CURLOPT_TCP_KEEPALIVE' ) ) {
				$options[ CURLOPT_TCP_KEEPALIVE ] = 1;
			}
			if ( defined( 'CURLOPT_PIPEWAIT' ) ) {
				$options[ CURLOPT_PIPEWAIT ] = true;
			}
			if ( defined( 'CURL_HTTP_VERSION_2TLS' ) ) {
				$options[ CURLOPT_HTTP_VERSION ] = CURL_HTTP_VERSION_2TLS;
			}
			curl_setopt_array( $ch, $options );
			curl_multi_add_handle( $multi, $ch );
			$handles[ (int) $index ] = $ch;
		}

		$running = null;
		do {
			$status = curl_multi_exec( $multi, $running );
			if ( CURLM_OK !== $status ) {
				break;
			}
			if ( $running ) {
				if ( function_exists( 'curl_multi_poll' ) ) {
					curl_multi_poll( $multi, 0.35 );
				} else {
					$selected = curl_multi_select( $multi, 0.35 );
					if ( -1 === $selected ) {
						usleep( 20000 );
					}
				}
			}
		} while ( $running );

		$network_failures = 0;
		$resolver_pressure = false;
		foreach ( $handles as $index => $ch ) {
			$body  = (string) curl_multi_getcontent( $ch );
			$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$error = (string) curl_error( $ch );

			if ( 200 === $code && strlen( $body ) >= 500 && ! $error ) {
				$this->clear_source_circuit( (string) ( $items[ $index ]['manga_url'] ?? '' ) );
				$results[ (int) $index ] = array( 'ok' => true, 'html' => $body );
			} else {
				$error_text = $error ?: ( 'A fonte respondeu com HTTP ' . $code . '.' );
				if ( $this->resolver_pressure_error( $error_text ) ) {
					$resolver_pressure = true;
				}
				$network_failures++;
				$results[ (int) $index ] = array( 'ok' => false, 'error' => $error_text );
			}

			curl_multi_remove_handle( $multi, $ch );
			curl_close( $ch );
		}
		curl_multi_close( $multi );
		if ( $share && function_exists( 'curl_share_close' ) ) {
			curl_share_close( $share );
		}

		if ( $resolver_pressure ) {
			set_transient( 'mbi_network_pressure', 1, 10 * MINUTE_IN_SECONDS );
			set_transient( 'mbi_update_network_backoff', 1, 10 * MINUTE_IN_SECONDS );
		} elseif ( $network_failures >= max( 2, (int) ceil( count( $items ) / 2 ) ) ) {
			set_transient( 'mbi_update_network_backoff', 1, 3 * MINUTE_IN_SECONDS );
		}
		return $results;
	}

	/** URLs de capítulos já conhecidas, usando primeiro o índice salvo na própria obra. */
	private function known_chapter_keys_for_update( int $manga_id ): array {
		global $wpdb;
		// The root's inventory includes chapters merely discovered or queued. It is
		// not evidence of publication. Read lightweight URL rows without caching
		// thousands of complete image arrays in PHP.
		$known = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT u.meta_value FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} u ON u.post_id=p.ID AND u.meta_key='_mbi_source_url'
			 WHERE p.post_type='mbi_chapter' AND p.post_status='publish' AND p.post_parent=%d
			 AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} ready WHERE ready.post_id=p.ID AND ready.meta_key='_mbi_chapter_complete' AND ready.meta_value='1')
			 AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} withdrawn WHERE withdrawn.post_id=p.ID AND withdrawn.meta_key IN ('_mbi_origin_withdrawn','_mbi_root_absent'))",
			$manga_id
		) );
		$keys = array();
		foreach ( $known as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( $url ) {
				$keys[ $this->source_key( $url ) ] = true;
			}
		}

		// v1.6.24: capítulos que existem na listagem da origem, mas ficaram sem páginas,
		// entram em cooldown por 7 dias. Assim a detecção diária não recria a mesma falha
		// indefinidamente, mas o item volta a ser testável caso a origem seja corrigida.
		$unavailable = get_post_meta( $manga_id, '_mbi_unavailable_chapter_urls', true );
		$unavailable = is_array( $unavailable ) ? $unavailable : array();
		$cutoff = time() - ( 7 * DAY_IN_SECONDS );
		$changed = false;
		foreach ( $unavailable as $unavailable_key => $row ) {
			$row = is_array( $row ) ? $row : array( 'url' => (string) $row, 'checked_at' => time() );
			$url = esc_url_raw( (string) ( $row['url'] ?? '' ) );
			$checked_at = (int) ( $row['checked_at'] ?? 0 );
			if ( ! $url || ( $checked_at > 0 && $checked_at < $cutoff ) ) {
				unset( $unavailable[ $unavailable_key ] );
				$changed = true;
				continue;
			}
			$keys[ $this->source_key( $url ) ] = true;
		}
		if ( $changed ) {
			update_post_meta( $manga_id, '_mbi_unavailable_chapter_urls', $unavailable );
		}
		return $keys;
	}

	/**
	 * v1.6.18: prepara somente a janela recente das origens. Com milhares de mangás,
	 * obras que não aparecem nas páginas recentes não geram HTTP nem consultas por capítulo.
	 */
	public function start_update_scan(): array {
		if ( get_transient( 'mbi_update_scan_lock' ) ) {
			return array( 'error' => 'A verificação anterior ainda está finalizando uma rodada. Aguarde alguns segundos e tente novamente.' );
		}

		// A detecção é somente leitura e agora pode coexistir com uma importação grande.
		// Quando a fila está ocupada, update_scan_batch_size() reduz a detecção a uma obra
		// por rodada para não competir por CPU/DNS com o importador.
		$this->release_stale_activity_markers();
		$queue = $this->db->counts();
		$coexist_mode = (int) ( $queue['pending'] ?? 0 ) > 0 || (int) ( $queue['processing'] ?? 0 ) > 0;

		$settings = $this->settings();
		$pages    = max( 1, min( 30, (int) ( $settings['update_pages'] ?? 5 ) ) );
		$bases    = $this->update_source_bases();
		if ( ! $bases ) {
			return array( 'error' => 'Nenhuma origem autorizada está configurada para a detecção.' );
		}

		$sources = array();
		foreach ( $bases as $base ) {
			$sources[] = array( 'base' => esc_url_raw( $base ), 'page' => 1, 'done' => false, 'failed' => false );
		}
		$state = array(
			'id'              => wp_generate_uuid4(),
			'active'          => true,
			'phase'           => 'catalog',
			'total'           => 0,
			'checked'         => 0,
			'with_updates'    => 0,
			'without_updates' => 0,
			'partial'         => 0,
			'failed'          => 0,
			'found_chapters'  => 0,
			'current_manga'   => '',
			'items'           => array(),
			'started_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
			'error'           => '',
			'sources'         => $sources,
			'page_limit'      => $pages,
			'recent_urls'     => array(),
			'catalog_pages_scanned' => 0,
			'catalog_checked' => 0,
			'catalog_total'   => count( $sources ) * $pages,
			'source_failures' => 0,
			'scan_mode'       => 'fast_recent_incremental',
			'coexist_mode'    => $coexist_mode,
		);
		update_option( 'mbi_detected_updates', array(), false );
		update_option( 'mbi_update_scan', $state, false );
		if ( ! wp_next_scheduled( 'mbi_update_scan_continue' ) ) {
			wp_schedule_single_event( time() + 60, 'mbi_update_scan_continue' );
		}
		$this->db->log( 'info', 'Detecção incremental iniciada. O clique inicial apenas cria o estado; as páginas recentes serão lidas em pequenos lotes e podem coexistir com a importação.', array( 'sources' => count( $sources ), 'page_limit' => $pages, 'parallel_batch' => $this->update_scan_batch_size(), 'coexist_mode' => $coexist_mode ) );
		return $this->update_scan_progress();
	}


	/** Processa um lote pequeno de obras recentes por chamada, com no máximo seis conexões. */
	public function process_update_scan_tick(): array {
		if ( ! MBI_Resource_Guard::begin( 'detecção', true ) ) { return $this->update_scan_progress(); }
		try { return $this->process_update_scan_tick_bounded(  ); } finally { MBI_Resource_Guard::end(); }
	}

	/**
	 * Continues an existing, explicitly started detection through WP-Cron.
	 * No new scan starts here. One bounded tick per event, with no dependency
	 * on the admin browser; the regular importer keeps priority when busy.
	 */
	public function update_scan_cron_continue(): void {
		$state = get_option( 'mbi_update_scan', array() );
		if ( ! is_array( $state ) || empty( $state['active'] ) ) { return; }
		$next_wait = max( 0, (int) ( $state['paused_until'] ?? 0 ) - time() );
		$guard = MBI_Resource_Guard::status();
		if ( ! empty( $guard['lane'] ) || (int) ( $guard['wait'] ?? 0 ) > 0 ) {
			$next_wait = max( $next_wait, 60 );
		} else {
			$this->process_update_scan_tick();
			$state = get_option( 'mbi_update_scan', array() );
		}
		if ( is_array( $state ) && ! empty( $state['active'] ) && ! wp_next_scheduled( 'mbi_update_scan_continue' ) ) {
			wp_schedule_single_event( time() + max( 60, min( 1800, $next_wait ) ), 'mbi_update_scan_continue' );
		}
	}

	private function defer_update_scan_budget( array $state, int $delay ): array {
		foreach ( (array) ( $state['items'] ?? array() ) as $n => $item ) {
			if ( ( $item['status'] ?? '' ) === 'processing' ) { $state['items'][$n]['status'] = 'pending'; }
		}
		$state['budget_wait_until'] = time() + max( 2, $delay );
		$state['updated_at'] = current_time( 'mysql' );
		update_option( 'mbi_update_scan', $state, false );
		delete_transient( 'mbi_update_scan_lock' );
		return $this->update_scan_progress();
	}

	private function process_update_scan_tick_bounded(): array {
		$state = get_option( 'mbi_update_scan', array() );
		if ( ! is_array( $state ) || empty( $state['active'] ) ) {
			return $this->update_scan_progress();
		}
		if ( get_transient( 'mbi_update_scan_lock' ) ) {
			return $this->update_scan_progress();
		}
		set_transient( 'mbi_update_scan_lock', (string) ( $state['id'] ?? 'scan' ), 45 );

		if ( ( $state['budget_wait_until'] ?? 0 ) > time() ) { delete_transient( 'mbi_update_scan_lock' ); return $this->update_scan_progress(); }
		$paused_until = (int) ( $state['paused_until'] ?? 0 );
		if ( $paused_until > time() ) {
			delete_transient( 'mbi_update_scan_lock' );
			return $this->update_scan_progress();
		}
		if ( $paused_until ) {
			$state['paused_until'] = 0;
			$state['error'] = '';
			update_option( 'mbi_update_scan', $state, false );
		}

		// v1.6.19: descoberta da janela recente é incremental. O clique inicial não faz HTTP externo.
		if ( 'catalog' === sanitize_key( (string) ( $state['phase'] ?? '' ) ) ) {
			$sources = is_array( $state['sources'] ?? null ) ? array_values( $state['sources'] ) : array();
			$page_limit = max( 1, min( 30, (int) ( $state['page_limit'] ?? 5 ) ) );
			$batch = array();
			$catalog_parallel = 1;
			foreach ( $sources as $source_index => $source ) {
				if ( ! empty( $source['done'] ) ) {
					continue;
				}
				$page = max( 1, (int) ( $source['page'] ?? 1 ) );
				$base = esc_url_raw( (string) ( $source['base'] ?? '' ) );
				if ( ! $base || $page > $page_limit ) {
					$sources[ $source_index ]['done'] = true;
					continue;
				}
				$url = 1 === $page ? trailingslashit( $base ) . 'manga/' : trailingslashit( $base ) . 'manga/page/' . $page . '/';
				$batch[ $source_index ] = array( 'manga_url' => $url, 'catalog_page' => $page, 'base' => $base );
				if ( count( $batch ) >= $catalog_parallel ) {
					break;
				}
			}

			if ( $batch ) {
				$prefetched = $this->parallel_fetch_update_htmls( $batch );
				$recent_map = array_fill_keys( array_filter( array_map( 'esc_url_raw', (array) ( $state['recent_urls'] ?? array() ) ) ), true );
				foreach ( $batch as $source_index => $request ) {
					$result = (array) ( $prefetched[ (int) $source_index ] ?? array() );
					if ( ! empty( $result['wait'] ) ) { return $this->defer_update_scan_budget( $state, (int) $result['wait'] ); }
					if ( empty( $result['ok'] ) ) {
						$error = sanitize_text_field( (string) ( $result['error'] ?? 'A origem não respondeu.' ) );
						if ( $this->origin_pressure_error( $error ) ) {
							$remaining = $this->source_circuit_remaining( (string) $request['manga_url'] );
							$delay = $remaining > 0 ? $remaining : $this->open_source_circuit( (string) $request['manga_url'], $error );
							$state['paused_until'] = time() + $delay;
							$state['error'] = $error;
							$state['current_manga'] = '';
							$state['recent_urls'] = array_keys( $recent_map );
							$state['sources'] = $sources;
							$state['updated_at'] = current_time( 'mysql' );
							update_option( 'mbi_update_scan', $state, false );
							$this->db->log( 'warning', 'Detecção pausada automaticamente porque a origem está indisponível.', array( 'url' => $request['manga_url'], 'error' => $error, 'retry_in' => $delay ) );
							delete_transient( 'mbi_update_scan_lock' );
							return $this->update_scan_progress();
						}
						$state['catalog_checked'] = (int) ( $state['catalog_checked'] ?? 0 ) + 1;
						$sources[ $source_index ]['done'] = true;
						$sources[ $source_index ]['failed'] = true;
						$state['source_failures'] = (int) ( $state['source_failures'] ?? 0 ) + 1;
						$this->db->log( 'warning', 'Falha ao ler página recente na detecção incremental.', array( 'url' => $request['manga_url'], 'error' => $error ) );
						continue;
					}
					$state['catalog_checked'] = (int) ( $state['catalog_checked'] ?? 0 ) + 1;
					try {
						$this->parser->load( (string) $result['html'], (string) $request['manga_url'] );
						$catalog = $this->parser->parse_catalog();
						$urls = (array) ( $catalog['manga_urls'] ?? array() );
						$state['catalog_pages_scanned'] = (int) ( $state['catalog_pages_scanned'] ?? 0 ) + 1;
						if ( ! $urls ) {
							$sources[ $source_index ]['done'] = true;
							continue;
						}
						foreach ( $urls as $source_url ) {
							$source_url = $this->canonical_source_url( (string) $source_url );
							if ( $source_url ) {
								$recent_map[ $source_url ] = true;
							}
						}
						$next = (int) $request['catalog_page'] + 1;
						if ( $next > $page_limit ) {
							$sources[ $source_index ]['done'] = true;
						} else {
							$sources[ $source_index ]['page'] = $next;
						}
					} catch ( Throwable $exception ) {
						$sources[ $source_index ]['done'] = true;
						$sources[ $source_index ]['failed'] = true;
						$state['source_failures'] = (int) ( $state['source_failures'] ?? 0 ) + 1;
						$this->db->log( 'warning', 'Falha ao interpretar página recente na detecção incremental.', array( 'url' => $request['manga_url'], 'error' => $exception->getMessage() ) );
					}
				}
				$state['recent_urls'] = array_keys( $recent_map );
			}

			$state['sources'] = $sources;
			$state['updated_at'] = current_time( 'mysql' );
			$all_done = true;
			foreach ( $sources as $source ) {
				if ( empty( $source['done'] ) ) { $all_done = false; break; }
			}
			if ( ! $all_done ) {
				update_option( 'mbi_update_scan', $state, false );
				delete_transient( 'mbi_update_scan_lock' );
				return $this->update_scan_progress();
			}

			$recent_urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) ( $state['recent_urls'] ?? array() ) ) ) ) );
			if ( ! $recent_urls ) {
				$state['active'] = false;
				$state['error'] = 'Nenhuma obra foi encontrada nas páginas recentes das origens. Confira os domínios autorizados e tente novamente.';
				$state['finished_at'] = current_time( 'mysql' );
				update_option( 'mbi_update_scan', $state, false );
				delete_transient( 'mbi_update_scan_lock' );
				return $this->update_scan_progress();
			}
			$local_index = $this->manga_update_index_for_urls( $recent_urls );
			$items = array();
			foreach ( $recent_urls as $url ) {
				if ( isset( $local_index[ $url ] ) ) { $items[] = $local_index[ $url ]; }
			}
			if ( ! $items ) {
				$state['active'] = false;
				$state['error'] = 'As páginas recentes foram lidas, mas nenhuma dessas obras pertence ao catálogo publicado local.';
				$state['finished_at'] = current_time( 'mysql' );
				update_option( 'mbi_update_scan', $state, false );
				delete_transient( 'mbi_update_scan_lock' );
				return $this->update_scan_progress();
			}
			$state['phase'] = 'mangas';
			$state['items'] = array_values( $items );
			$state['total'] = count( $items );
			$state['checked'] = 0;
			$state['current_manga'] = '';
			unset( $state['recent_urls'] );
			update_option( 'mbi_update_scan', $state, false );
			$this->db->log( 'info', 'Janela recente mapeada; iniciando verificação somente das obras locais encontradas.', array( 'candidate_mangas' => count( $items ), 'recent_urls' => count( $recent_urls ), 'catalog_pages' => (int) ( $state['catalog_pages_scanned'] ?? 0 ) ) );
			delete_transient( 'mbi_update_scan_lock' );
			return $this->update_scan_progress();
		}

		foreach ( (array) ( $state['items'] ?? array() ) as $position => $item ) {
			if ( 'processing' === (string) ( $item['status'] ?? '' ) ) {
				$state['items'][ $position ]['status'] = 'pending';
			}
		}

		$indexes = array();
		$batch_size = $this->update_scan_batch_size();
		foreach ( (array) ( $state['items'] ?? array() ) as $position => $item ) {
			if ( 'pending' !== (string) ( $item['status'] ?? '' ) ) {
				continue;
			}
			$indexes[] = (int) $position;
			if ( count( $indexes ) >= $batch_size ) {
				break;
			}
		}

		if ( ! $indexes ) {
			$state['active']        = false;
			$state['current_manga'] = '';
			$state['finished_at']   = current_time( 'mysql' );
			update_option( 'mbi_update_scan', $state, false );
			update_option( 'mbi_last_updates_scan', current_time( 'mysql' ), false );
			delete_transient( 'mbi_update_scan_lock' );
			return $this->update_scan_progress();
		}

		$batch = array();
		foreach ( $indexes as $index ) {
			$state['items'][ $index ]['status'] = 'processing';
			$batch[ $index ] = (array) $state['items'][ $index ];
		}
		$first_name = (string) ( reset( $batch )['manga_name'] ?? '' );
		$state['current_manga'] = count( $batch ) > 1 ? sprintf( '%s +%d', $first_name, count( $batch ) - 1 ) : $first_name;
		$state['updated_at'] = current_time( 'mysql' );
		update_option( 'mbi_update_scan', $state, false );

		$prefetched = $this->parallel_fetch_update_htmls( $batch );
		$updates = get_option( 'mbi_detected_updates', array() );
		$updates = is_array( $updates ) ? $updates : array();

		foreach ( $batch as $index => $item ) {
			$manga_id  = absint( $item['manga_id'] ?? 0 );
			$manga_url = esc_url_raw( (string) ( $item['manga_url'] ?? '' ) );
			try {
				$result = (array) ( $prefetched[ (int) $index ] ?? array() );
				if ( ! empty( $result['wait'] ) ) { return $this->defer_update_scan_budget( $state, (int) $result['wait'] ); }
				if ( empty( $result['ok'] ) ) {
					throw new RuntimeException( sanitize_text_field( (string) ( $result['error'] ?? 'A origem não respondeu.' ) ) );
				}
				$this->parser->load( (string) $result['html'], $manga_url );
				// A detecção não é reconciliação destrutiva: aceita uma lista parcial,
				// mas não a considera prova de que não existem outros capítulos.
				$snapshot = $this->parser->update_chapter_snapshot();
				$chapter_urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $snapshot['chapter_urls'] ) ) ) );
				if ( ! $chapter_urls && (int) get_post_meta( $manga_id, '_mbi_expected_chapters', true ) > 0 ) {
					throw new RuntimeException( 'A origem respondeu, mas nenhum capítulo foi reconhecido.' );
				}

				$known_keys = $this->known_chapter_keys_for_update( $manga_id );
				foreach ( $updates as $key => $row ) {
					if ( $manga_id === absint( $row['manga_id'] ?? 0 ) ) {
						unset( $updates[ $key ] );
					}
				}

				$chapter_numbers = array();
				$detected_at = current_time( 'mysql' );
				$detected_ts = time();
				foreach ( $chapter_urls as $chapter_order => $chapter_url ) {
					if ( isset( $known_keys[ $this->source_key( $chapter_url ) ] ) ) {
						continue;
					}
					$key = md5( $manga_id . '|' . $this->source_key( $chapter_url ) );
					$number = $this->chapter_number_from_url( $chapter_url );
					$chapter_numbers[] = $number;
					$updates[ $key ] = array(
						'key'            => $key,
						'manga_id'       => $manga_id,
						'manga_name'     => sanitize_text_field( (string) $item['manga_name'] ),
						'manga_url'      => $manga_url,
						'chapter_url'    => $chapter_url,
						'chapter_number' => $number,
						'detected_at'    => $detected_at,
						'detected_ts'    => $detected_ts,
						'release_order'  => ( (int) $index * 100000 ) + (int) $chapter_order,
					);
				}

				$count = count( $chapter_numbers );
				$partial = ! empty( $snapshot['partial'] );
				$reported = (int) $snapshot['reported_count'];
				$listed = (int) $snapshot['listed_count'];
				$state['items'][ $index ]['status'] = $partial ? ( $count > 0 ? 'partial_updates' : 'partial' ) : ( $count > 0 ? 'has_updates' : 'clean' );
				$state['items'][ $index ]['note'] = $partial
					? sprintf( 'Lista parcial: %d links visíveis; %s. Catálogo preservado. Novidades apenas entre os links encontrados.', $listed, $reported > 0 ? sprintf( '%d capítulos informados pela origem', $reported ) : 'sem contagem confirmada' )
					: '';
				$state['items'][ $index ]['reported_chapters'] = $reported;
				$state['items'][ $index ]['listed_chapters'] = $listed;
				if ( $partial ) {
					$state['partial'] = (int) ( $state['partial'] ?? 0 ) + 1;
					$this->db->log( 'info', 'Detecção parcial não destrutiva: lista paginada ou incompleta.', array( 'url' => $manga_url, 'manga_id' => $manga_id, 'reported' => $reported, 'listed' => $listed, 'new_candidates' => $count ) );
				}
				$state['items'][ $index ]['new_count']       = $count;
				$state['items'][ $index ]['chapter_numbers'] = $chapter_numbers;
				$state['items'][ $index ]['checked_at']      = current_time( 'mysql' );
				$state['items'][ $index ]['error']           = '';
				$state['with_updates']    = (int) $state['with_updates'] + ( $count > 0 ? 1 : 0 );
				$state['without_updates'] = (int) $state['without_updates'] + ( $count > 0 || $partial ? 0 : 1 );
				$state['found_chapters']  = (int) $state['found_chapters'] + $count;
			} catch ( Throwable $exception ) {
				$error = sanitize_text_field( $exception->getMessage() );
				if ( $this->origin_pressure_error( $error ) ) {
					$remaining = $this->source_circuit_remaining( $manga_url );
					$delay = $remaining > 0 ? $remaining : $this->open_source_circuit( $manga_url, $error );
					foreach ( $indexes as $pending_index ) {
						if ( 'processing' === (string) ( $state['items'][ $pending_index ]['status'] ?? '' ) ) {
							$state['items'][ $pending_index ]['status'] = 'pending';
						}
					}
					$state['paused_until'] = time() + $delay;
					$state['error'] = $error;
					$state['current_manga'] = '';
					$state['updated_at'] = current_time( 'mysql' );
					update_option( 'mbi_detected_updates', $updates, false );
					update_option( 'mbi_update_scan', $state, false );
					$this->db->log( 'warning', 'Detecção de novos capítulos pausada pela indisponibilidade da origem.', array( 'url' => $manga_url, 'manga_id' => $manga_id, 'error' => $error, 'retry_in' => $delay ) );
					delete_transient( 'mbi_update_scan_lock' );
					return $this->update_scan_progress();
				}
				$state['items'][ $index ]['status']     = 'error';
				$state['items'][ $index ]['checked_at'] = current_time( 'mysql' );
				$state['items'][ $index ]['error']      = $error;
				$state['items'][ $index ]['note']       = '';
				$state['failed'] = (int) $state['failed'] + 1;
				$state['error']  = $error;
				$this->db->log( 'error', 'Falha ao verificar atualização de obra no lote rápido.', array( 'url' => $manga_url, 'manga_id' => $manga_id, 'error' => $error ) );
			}
			$state['checked'] = (int) $state['checked'] + 1;
		}

		uasort( $updates, static fn( array $a, array $b ): int => (int) ( $a['release_order'] ?? PHP_INT_MAX ) <=> (int) ( $b['release_order'] ?? PHP_INT_MAX ) );
		update_option( 'mbi_detected_updates', $updates, false );
		$state['updated_at'] = current_time( 'mysql' );
		$state['current_manga'] = '';
		if ( (int) $state['checked'] >= (int) $state['total'] ) {
			$state['active']      = false;
			$state['finished_at'] = current_time( 'mysql' );
			update_option( 'mbi_last_updates_scan', current_time( 'mysql' ), false );
			$this->db->log( 'info', 'Detecção rápida concluída.', array( 'mangas' => (int) $state['checked'], 'with_updates' => (int) $state['with_updates'], 'chapters' => (int) $state['found_chapters'], 'failures' => (int) $state['failed'], 'catalog_pages' => (int) ( $state['catalog_pages_scanned'] ?? 0 ) ) );
		}
		update_option( 'mbi_update_scan', $state, false );
		delete_transient( 'mbi_update_scan_lock' );
		return $this->update_scan_progress();
	}

	/**
	 * Faz somente a leitura das páginas recentes da origem. Nenhum post é criado ou alterado.
	 */
	public function detect_updates_now(): array {
		$state = $this->start_update_scan();
		if ( ! empty( $state['error'] ) ) {
			throw new RuntimeException( (string) $state['error'] );
		}
		// Compatibilidade síncrona: cobre descoberta incremental + lotes de obras.
		$guard = 500;
		while ( ! empty( $state['active'] ) && $guard-- > 0 ) {
			$state = $this->process_update_scan_tick();
		}
		return array(
			'pages'   => 0,
			'mangas'  => (int) ( $state['checked'] ?? 0 ),
			'pending' => count( $this->detected_updates() ),
			'new'     => (int) ( $state['found_chapters'] ?? 0 ),
		);
	}

	/**
	 * v1.6.23: o clique em "Atualizar capítulos" apenas agenda a preparação.
	 * Nenhuma varredura por post_meta nem milhares de INSERTs rodam dentro do admin-post.php.
	 */
	public function enqueue_detected_updates( array $keys = array(), int $manga_id = 0 ): array {
		$this->release_stale_activity_markers();
		// Publicar capítulos detectados é uma ação explícita e tem prioridade sobre
		// a busca de novas obras do catálogo. Cancela somente starters; conteúdo já
		// importado e a lista de novidades detectadas são preservados.
		$catalog = $this->catalog_discovery_status();
		if ( ! empty( $catalog['active'] ) || $this->db->active_starter_count() > 0 ) {
			$this->cancel_catalog_discovery( 'Busca de catálogo interrompida automaticamente para priorizar a publicação manual de novos capítulos.' );
		}
		$current = $this->manual_update_progress();
		if ( ! empty( $current['active'] ) ) {
			return array( 'queued' => 0, 'error' => 'Já existe uma atualização manual de capítulos em andamento.' );
		}

		$updates = get_option( 'mbi_detected_updates', array() );
		$updates = is_array( $updates ) ? $updates : array();
		$key_map = array_fill_keys( array_filter( array_map( 'sanitize_key', $keys ) ), true );
		$mode = $key_map ? 'keys' : ( $manga_id ? 'manga' : 'all' );
		$selected_total = 0;
		if ( 'all' === $mode ) {
			$selected_total = count( $updates );
		} elseif ( 'keys' === $mode ) {
			foreach ( array_keys( $key_map ) as $key ) {
				if ( isset( $updates[ $key ] ) ) {
					$selected_total++;
				}
			}
		} else {
			foreach ( $updates as $row ) {
				if ( $manga_id === absint( $row['manga_id'] ?? 0 ) ) {
					$selected_total++;
				}
			}
		}
		if ( $selected_total < 1 ) {
			return array( 'queued' => 0, 'error' => 'Nenhum capítulo pendente válido foi selecionado.' );
		}

		$batch_id = wp_generate_uuid4();
		$state = array(
			'id' => $batch_id,
			'active' => true,
			'preparing' => true,
			'total' => $selected_total,
			'target_total' => $selected_total,
			'queued' => 0,
			'completed' => 0,
			'failed' => 0,
			'prepare_cursor' => 0,
			'prepare_scanned' => 0,
			'prepare_skipped' => 0,
			'selection_mode' => $mode,
			'selection_manga_id' => $manga_id,
			'selection_keys' => array_keys( $key_map ),
			'current_manga' => '',
			'current_chapter' => '',
			'started_at' => current_time( 'mysql' ),
			'error' => '',
		);
		update_option( 'mbi_queue_paused', 0, false );
		update_option( 'mbi_manual_update_batch', $state, false );
		delete_transient( 'mbi_manual_prepare_lock' );
		if ( ! wp_next_scheduled( 'mbi_prepare_manual_updates' ) ) {
			wp_schedule_single_event( time() + 1, 'mbi_prepare_manual_updates' );
		}
		$this->db->log( 'info', 'Atualização manual agendada em modo assíncrono.', array( 'batch' => $batch_id, 'selected' => $selected_total, 'mode' => $mode ) );
		return array( 'queued' => $selected_total, 'batch' => $batch_id, 'error' => '', 'preparing' => true );
	}

	/** Prepara no máximo um pequeno lote por request e usa INSERT em bloco. */
	public function prepare_manual_update_tick( int $queue_limit = 80, int $scan_limit = 350 ): array {
		if ( ! MBI_Resource_Guard::begin( 'preparação', false ) ) { return $this->manual_update_progress(); }
		try { return $this->prepare_manual_update_tick_bounded( $queue_limit, $scan_limit ); } finally { MBI_Resource_Guard::end(); }
	}

	private function prepare_manual_update_tick_bounded( int $queue_limit = 80, int $scan_limit = 350 ): array {
		$state = get_option( 'mbi_manual_update_batch', array() );
		$state = is_array( $state ) ? $state : array();
		if ( empty( $state['active'] ) || empty( $state['preparing'] ) || empty( $state['id'] ) ) {
			return $this->manual_update_progress();
		}
		if ( get_transient( 'mbi_manual_prepare_lock' ) ) {
			return $this->manual_update_progress();
		}
		set_transient( 'mbi_manual_prepare_lock', 1, 20 );

		$queue_limit = max( 1, min( 10, $queue_limit ) );
		$scan_limit  = max( $queue_limit, min( 60, $scan_limit ) );
		$updates = get_option( 'mbi_detected_updates', array() );
		$updates = is_array( $updates ) ? $updates : array();
		$all_keys = array_keys( $updates );
		$cursor = max( 0, (int) ( $state['prepare_cursor'] ?? 0 ) );
		$scanned = 0;
		$payloads = array();
		$additions = array();
		$known_cache = array();
		$valid_manga = array();
		$selected_keys_map = array_fill_keys( array_map( 'sanitize_key', (array) ( $state['selection_keys'] ?? array() ) ), true );

		try {
			while ( $cursor < count( $all_keys ) && $scanned < $scan_limit && count( $payloads ) < $queue_limit ) {
				$key = sanitize_key( (string) $all_keys[ $cursor ] );
				$cursor++;
				$scanned++;
				$row = isset( $updates[ $key ] ) && is_array( $updates[ $key ] ) ? $updates[ $key ] : array();
				$mode = sanitize_key( (string) ( $state['selection_mode'] ?? 'all' ) );
				if ( 'keys' === $mode && ! isset( $selected_keys_map[ $key ] ) ) {
					continue;
				}
				if ( 'manga' === $mode && absint( $state['selection_manga_id'] ?? 0 ) !== absint( $row['manga_id'] ?? 0 ) ) {
					continue;
				}

				$manga_id = absint( $row['manga_id'] ?? 0 );
				$url = esc_url_raw( (string) ( $row['chapter_url'] ?? '' ) );
				if ( ! $manga_id || ! $url ) {
					$state['prepare_skipped'] = (int) ( $state['prepare_skipped'] ?? 0 ) + 1;
					continue;
				}
				if ( ! array_key_exists( $manga_id, $valid_manga ) ) {
					$valid_manga[ $manga_id ] = 'publish' === get_post_status( $manga_id );
				}
				if ( ! $valid_manga[ $manga_id ] ) {
					$state['prepare_skipped'] = (int) ( $state['prepare_skipped'] ?? 0 ) + 1;
					continue;
				}
				if ( ! isset( $known_cache[ $manga_id ] ) ) {
					$known_cache[ $manga_id ] = $this->known_chapter_keys_for_update( $manga_id );
				}
				$source_key = $this->source_key( $url );
				if ( isset( $known_cache[ $manga_id ][ $source_key ] ) ) {
					$state['prepare_skipped'] = (int) ( $state['prepare_skipped'] ?? 0 ) + 1;
					continue;
				}
				$known_cache[ $manga_id ][ $source_key ] = true;
				$additions[ $manga_id ][] = $url;
				$payloads[] = array(
					'url' => $url,
					'generation' => $this->current_generation(),
					'manga_id' => $manga_id,
					'manga_title' => sanitize_text_field( (string) ( $row['manga_name'] ?? '' ) ),
					'manual_update_batch' => (string) $state['id'],
					'manual_update_key' => $key,
					'manual_update' => 1,
					'chapter_number_hint' => sanitize_text_field( (string) ( $row['chapter_number'] ?? '' ) ),
					'dedupe' => 'manual-update|' . (string) $state['id'] . '|' . $key,
				);
			}

			foreach ( $additions as $manga_id => $urls ) {
				$known = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) get_post_meta( (int) $manga_id, '_mbi_source_chapter_urls', true ) ) ) ) );
				foreach ( $urls as $url ) {
					if ( ! in_array( $url, $known, true ) ) {
						$known[] = $url;
					}
				}
				update_post_meta( (int) $manga_id, '_mbi_source_chapter_urls', $known );
				update_post_meta( (int) $manga_id, '_mbi_expected_chapters', count( $known ) );
			}

			$queued_now = $payloads ? $this->db->queue_many( 'chapter', $payloads, true ) : 0;
			$state['queued'] = (int) ( $state['queued'] ?? 0 ) + $queued_now;
			$state['prepare_cursor'] = $cursor;
			$state['prepare_scanned'] = (int) ( $state['prepare_scanned'] ?? 0 ) + $scanned;
			$state['updated_at'] = current_time( 'mysql' );
			if ( $cursor >= count( $all_keys ) ) {
				$state['preparing'] = false;
				$state['total'] = max( 0, (int) $state['queued'] );
				if ( $state['total'] < 1 ) {
					$state['active'] = false;
					$state['finished_at'] = current_time( 'mysql' );
					$state['error'] = 'Nenhum capítulo novo precisou ser colocado na fila.';
				}
			}
			update_option( 'mbi_manual_update_batch', $state, false );
			if ( $queued_now > 0 ) {
				$this->kick_worker();
			}
			if ( ! empty( $state['preparing'] ) && ! wp_next_scheduled( 'mbi_prepare_manual_updates' ) ) {
				wp_schedule_single_event( time() + 2, 'mbi_prepare_manual_updates' );
			}
		} finally {
			delete_transient( 'mbi_manual_prepare_lock' );
		}
		return $this->manual_update_progress();
	}

	public function prepare_manual_update_cron(): void {
		$state = $this->prepare_manual_update_tick( 30, 160 );
		if ( ! empty( $state['active'] ) && ! empty( $state['preparing'] ) && ! wp_next_scheduled( 'mbi_prepare_manual_updates' ) ) {
			wp_schedule_single_event( time() + max( 5, (int) ( MBI_Resource_Guard::status()['wait'] ?? 0 ) ), 'mbi_prepare_manual_updates' );
		}
	}

	private function finalize_manual_update_selection( array $state ): void {
		if ( ! empty( $state['selection_cleaned'] ) || empty( $state['id'] ) ) {
			return;
		}
		$completed_keys = $this->db->manual_batch_update_keys( (string) $state['id'], 'completed' );
		if ( ! $completed_keys ) {
			return;
		}
		$updates = get_option( 'mbi_detected_updates', array() );
		$updates = is_array( $updates ) ? $updates : array();
		foreach ( $completed_keys as $key ) {
			unset( $updates[ sanitize_key( $key ) ] );
		}
		update_option( 'mbi_detected_updates', $updates, false );
	}

	public function manual_update_progress(): array {
		$state = get_option( 'mbi_manual_update_batch', array() );
		$state = is_array( $state ) ? $state : array();
		$state = wp_parse_args(
			$state,
			array(
				'id' => '', 'active' => false, 'preparing' => false, 'total' => 0, 'target_total' => 0,
				'queued' => 0, 'completed' => 0, 'failed' => 0, 'prepare_cursor' => 0, 'prepare_scanned' => 0,
				'prepare_skipped' => 0, 'selection_mode' => 'all', 'selection_manga_id' => 0, 'selection_keys' => array(),
				'current_manga' => '', 'current_chapter' => '', 'error' => '',
			)
		);
		if ( ! empty( $state['id'] ) ) {
			$counts = $this->db->manual_batch_counts( (string) $state['id'] );
			$state['completed'] = (int) $counts['completed'];
			$state['failed'] = (int) $counts['failed'];
			$state['queued'] = array_sum( array_map( 'intval', $counts ) );
			if ( empty( $state['preparing'] ) ) {
				$state['total'] = max( (int) $state['total'], (int) $state['queued'] );
				if ( ! empty( $state['active'] ) && 0 === (int) $counts['pending'] && 0 === (int) $counts['processing'] ) {
					$state['active'] = false;
					$state['finished_at'] = (string) ( $state['finished_at'] ?? current_time( 'mysql' ) );
					$this->finalize_manual_update_selection( $state );
					$state['selection_cleaned'] = 1;
					update_option( 'mbi_manual_update_batch', $state, false );
				}
			}
		}
		return $state;
	}

	/**
	 * v1.7.40: recover short-lived network failures without waiting for an admin click.
	 * Called by the existing one-minute queue cron; no automatic discovery or new
	 * publication is initiated. It touches only the current, user-selected batch.
	 *
	 * Hard bounds: 4 rows per tick, <=2 rescue rounds/job (enforced by MBI_DB),
	 * >=3 minute gap between recovery scans. Structural failures are excluded.
	 */
	public function auto_recover_manual_network_failures(): void {
		if ( $this->is_paused() ) { return; }
		$state = get_option( 'mbi_manual_update_batch', array() );
		if ( ! is_array( $state ) || empty( $state['id'] ) || ! empty( $state['preparing'] ) ) { return; }
		$batch = sanitize_text_field( (string) $state['id'] );
		$next = (array) get_option( 'mbi_manual_auto_retry_gate', array() );
		if ( (string) ( $next['batch'] ?? '' ) === $batch && time() < (int) ( $next['after'] ?? 0 ) ) { return; }
		// Saved before SQL to prevent duplicate cron invocations claiming the same window.
		update_option( 'mbi_manual_auto_retry_gate', array( 'batch' => $batch, 'after' => time() + 180 ), false );
		$result = $this->db->retry_manual_network_failures( $batch, 4 );
		if ( (int) ( $result['requeued'] ?? 0 ) < 1 ) { return; }
		// A completed batch can be reopened only for its own previously selected jobs.
		$state['active'] = true;
		$state['preparing'] = false;
		$state['updated_at'] = current_time( 'mysql' );
		$state['automatic_recoveries'] = (int) ( $state['automatic_recoveries'] ?? 0 ) + (int) $result['requeued'];
		$state['error'] = '';
		unset( $state['finished_at'] );
		update_option( 'mbi_manual_update_batch', $state, false );
		$this->db->log( 'info', 'Recuperação automática limitada de falhas temporárias em capítulos novos.', array(
			'batch' => $batch,
			'requeued' => (int) $result['requeued'],
			'eligible' => (int) ( $result['eligible'] ?? 0 ),
			'cap' => 2,
		) );
	}

	private function touch_manual_update( array $payload ): void {
		$batch_id = sanitize_text_field( (string) ( $payload['manual_update_batch'] ?? '' ) );
		if ( ! $batch_id ) {
			return;
		}
		$state = get_option( 'mbi_manual_update_batch', array() );
		$state = is_array( $state ) ? $state : array();
		if ( $batch_id !== (string) ( $state['id'] ?? '' ) ) {
			return;
		}
		$manga = sanitize_text_field( (string) ( $payload['manga_title'] ?? '' ) );
		$chapter = sanitize_text_field( (string) ( $payload['chapter_number_hint'] ?? '' ) );
		$now = time();
		if ( $manga === (string) ( $state['current_manga'] ?? '' ) && $now - (int) ( $state['touch_ts'] ?? 0 ) < 1 ) {
			return;
		}
		$state['current_manga'] = $manga;
		$state['current_chapter'] = $chapter;
		$state['touch_ts'] = $now;
		$state['updated_at'] = current_time( 'mysql' );
		update_option( 'mbi_manual_update_batch', $state, false );
	}

	private function complete_manual_update( array $payload ): void {
		// v1.6.23: contadores vêm da própria fila indexada. Evita regravar um array gigante a cada capítulo.
		$this->touch_manual_update( $payload );
	}

	private function fail_manual_update( array $payload, string $error ): void {
		$batch_id = sanitize_text_field( (string) ( $payload['manual_update_batch'] ?? '' ) );
		if ( ! $batch_id ) {
			return;
		}
		$state = get_option( 'mbi_manual_update_batch', array() );
		$state = is_array( $state ) ? $state : array();
		if ( $batch_id !== (string) ( $state['id'] ?? '' ) ) {
			return;
		}
		$state['error'] = sanitize_text_field( $error );
		$state['updated_at'] = current_time( 'mysql' );
		update_option( 'mbi_manual_update_batch', $state, false );
	}

	/** Recupera um lote manual que tenha sido parcialmente criado antes de um timeout do admin-post. */
	public function recover_orphan_manual_update_batch(): array {
		$current = get_option( 'mbi_manual_update_batch', array() );
		$current = is_array( $current ) ? $current : array();
		if ( ! empty( $current['active'] ) && ! empty( $current['id'] ) ) {
			return $current;
		}
		$orphan = $this->db->latest_active_manual_batch();
		if ( empty( $orphan['id'] ) ) {
			return $current;
		}
		$counts = (array) ( $orphan['counts'] ?? array() );
		$total = array_sum( array_map( 'intval', $counts ) );
		$state = array(
			'id' => sanitize_text_field( (string) $orphan['id'] ),
			'active' => (int) ( $counts['pending'] ?? 0 ) + (int) ( $counts['processing'] ?? 0 ) > 0,
			'preparing' => false,
			'total' => $total,
			'target_total' => $total,
			'queued' => $total,
			'completed' => (int) ( $counts['completed'] ?? 0 ),
			'failed' => (int) ( $counts['failed'] ?? 0 ),
			'selection_mode' => 'orphan',
			'selection_keys' => array(),
			'current_manga' => '',
			'current_chapter' => '',
			'started_at' => current_time( 'mysql' ),
			'recovered_after_timeout' => 1,
			'error' => '',
		);
		update_option( 'mbi_manual_update_batch', $state, false );
		$this->db->log( 'info', 'Lote manual órfão recuperado após timeout anterior.', array( 'batch' => $state['id'], 'total' => $total, 'counts' => $counts ) );
		if ( ! empty( $state['active'] ) ) {
			$this->kick_worker();
		}
		return $state;
	}

	private function chapter_number_from_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		// Accept the numeric component only, not a suffix such as "30-ptbr".
		if ( preg_match( '/(?:capitulo|chapter|caso|case)[-_]([0-9]+(?:[.,-][0-9]+)?)(?=[-_\/]|$)/iu', $path, $match ) ) {
			return MBI_Parser::recovery_number( str_replace( '-', '.', $match[1] ) ) ?: 'novo';
		}
		if ( preg_match( '#^/(?:capitulo|chapter)/[^/]+[-_]([0-9]{1,5}(?:[.,][0-9]{1,3})?)/?$#iu', $path, $match ) ) {
			return MBI_Parser::recovery_number( $match[1] ) ?: 'novo';
		}
		return 'novo';
	}


	/** Normaliza o número para comparar URLs antigas e novas do mesmo capítulo. */
	private function normalize_chapter_number( string $number ): string {
		$number = trim( str_replace( ',', '.', $number ) );
		$number = preg_replace( '/[^0-9.]+/', '', $number );
		if ( '' === $number ) {
			return '';
		}
		if ( false !== strpos( $number, '.' ) && preg_match( '/^\d+\.\d+$/', $number ) ) {
			$number = rtrim( rtrim( $number, '0' ), '.' );
		}
		return $number;
	}

	/** Localiza o capítulo local mesmo quando a URL de origem já mudou. */
	private function local_chapter_for_repair( int $manga_id, string $url, string $number ): int {
		$chapter_id = $this->find_by_source_url( 'mbi_chapter', $url );
		if ( $chapter_id && wp_get_post_parent_id( $chapter_id ) === $manga_id ) {
			return (int) $chapter_id;
		}
		if ( ! $number ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'mbi_chapter',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => $manga_id,
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_mbi_chapter_number',
				'meta_value'     => $number,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Corrige automaticamente URLs antigas durante reparos. A origem é relida uma vez
	 * e o capítulo é associado pelo número. Em 404/410 definitivo, o capítulo é
	 * retirado apenas da lista esperada e fica marcado para auditoria, sem loop.
	 */
	private function recover_repair_chapter_reference( array $payload, string $failed_url, string $error ): array {
		$out = array( 'action' => 'none', 'url' => $failed_url, 'payload' => $payload, 'message' => '' );
		// Do not make an incomplete work look complete by silently subtracting
		// a broken source chapter from the expected list. The serial batch skips
		// that work and preserves both its expectation and existing publications.
		if ( ! empty( $payload['serial_batch'] ) && $this->source_defect_error( $error ) ) {
			throw new RuntimeException( 'Defeito na origem: a obra será ignorada sem reduzir seu histórico. ' . $error );
		}
		if ( empty( $payload['repair'] ) && empty( $payload['manual_update'] ) ) {
			return $out;
		}
		$manga_id = absint( $payload['manga_id'] ?? 0 );
		if ( ! $manga_id || 'mbi_manga' !== get_post_type( $manga_id ) ) {
			return $out;
		}

		$local_id = $this->find_by_source_url( 'mbi_chapter', $failed_url );
		$number   = $local_id ? (string) get_post_meta( $local_id, '_mbi_chapter_number', true ) : '';
		$number   = $number ?: $this->chapter_number_from_url( $failed_url );
		$normalized_number = $this->normalize_chapter_number( $number );
		if ( ! $normalized_number || 'novo' === $number ) {
			return $out;
		}

		$manga_url = $this->canonical_source_url( (string) get_post_meta( $manga_id, '_mbi_source_url', true ) );
		if ( ! $manga_url ) {
			return $out;
		}
		try {
			$html = $this->fetch_html( $manga_url );
			$this->parser->load( $html, $manga_url );
			$data = $this->parser->parse_manga( $manga_url );
		} catch ( Throwable $exception ) {
			$this->db->log( 'warning', 'Não foi possível reler a obra para reparar a URL do capítulo.', array( 'manga_id' => $manga_id, 'chapter_url' => $failed_url, 'error' => $exception->getMessage() ) );
			return $out;
		}

		$current_urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) ( $data['chapter_urls'] ?? array() ) ) ) ) );
		$replacement = '';
		foreach ( $current_urls as $candidate ) {
			$candidate_number = $this->normalize_chapter_number( $this->chapter_number_from_url( $candidate ) );
			if ( $candidate_number && $candidate_number === $normalized_number && $candidate !== $failed_url ) {
				$replacement = $candidate;
				break;
			}
		}

		if ( $replacement ) {
			// Association is only a proposal. The verifier commits source/media together.
			$chapter_id = $this->local_chapter_for_repair( $manga_id, $failed_url, $number );
			if ( $chapter_id ) { $payload['chapter_id'] = $chapter_id; }

			$payload['url'] = $replacement;
			$out = array( 'action' => 'remapped', 'url' => $replacement, 'payload' => $payload, 'message' => 'URL antiga remapeada para a URL atual da origem.' );
			$this->db->log( 'info', 'URL de capítulo reparada automaticamente.', array( 'manga_id' => $manga_id, 'chapter' => $number, 'old_url' => $failed_url, 'new_url' => $replacement, 'previous_error' => $error ) );
			return $out;
		}

		// v1.6.24: um capítulo que respondeu normalmente, foi lido repetidamente e ainda
		// não contém nenhuma página não deve virar falha eterna. A releitura da página da
		// obra acima serve como confirmação/remapeamento. Se não existe URL alternativa,
		// tratamos esse item como temporariamente indisponível na origem.
		$empty_chapter = (bool) preg_match( '/Nenhuma página de imagem foi encontrada no capítulo/iu', $error );
		if ( $empty_chapter ) {
			$known = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) get_post_meta( $manga_id, '_mbi_source_chapter_urls', true ) ) ) ) );
			$known = array_values( array_filter( $known, static fn( string $known_url ): bool => $known_url !== $failed_url ) );
			update_post_meta( $manga_id, '_mbi_source_chapter_urls', $known );
			update_post_meta( $manga_id, '_mbi_expected_chapters', count( $known ) );

			$unavailable = get_post_meta( $manga_id, '_mbi_unavailable_chapter_urls', true );
			$unavailable = is_array( $unavailable ) ? $unavailable : array();
			$unavailable[ $this->source_key( $failed_url ) ] = array(
				'url' => $failed_url,
				'checked_at' => time(),
				'reason' => 'empty_chapter',
			);
			update_post_meta( $manga_id, '_mbi_unavailable_chapter_urls', $unavailable );

			$chapter_id = $this->local_chapter_for_repair( $manga_id, $failed_url, $number );
			if ( $chapter_id ) {
				update_post_meta( $chapter_id, '_mbi_source_unavailable', 1 );
				update_post_meta( $chapter_id, '_mbi_source_unavailable_reason', 'A origem respondeu, mas não entregou páginas de imagem após leituras confirmatórias.' );
				update_post_meta( $chapter_id, '_mbi_source_unavailable_checked_at', current_time( 'mysql' ) );
			}

			$out = array( 'action' => 'unavailable', 'url' => $failed_url, 'payload' => $payload, 'message' => 'Capítulo sem páginas na origem após confirmação; retirado da expectativa e colocado em quarentena temporária de atualização.' );
			$this->db->log( 'warning', 'Capítulo sem páginas confirmado e retirado da expectativa para evitar loop.', array( 'manga_id' => $manga_id, 'chapter' => $number, 'url' => $failed_url, 'error' => $error ) );
			return $out;
		}

		// Só 404/410 confirmam indisponibilidade quando o problema não é capítulo vazio.
		// HTTP 0 e erros de rede continuam retentáveis.
		if ( ! preg_match( '/HTTP\s*(?:404|410)\b/iu', $error ) ) {
			return $out;
		}

		$known = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) get_post_meta( $manga_id, '_mbi_source_chapter_urls', true ) ) ) ) );
		$known = array_values( array_filter( $known, static fn( string $known_url ): bool => $known_url !== $failed_url ) );
		update_post_meta( $manga_id, '_mbi_source_chapter_urls', $known );
		update_post_meta( $manga_id, '_mbi_expected_chapters', count( $known ) );

		$chapter_id = $this->local_chapter_for_repair( $manga_id, $failed_url, $number );
		if ( $chapter_id ) {
			update_post_meta( $chapter_id, '_mbi_source_unavailable', 1 );
			update_post_meta( $chapter_id, '_mbi_source_unavailable_reason', sanitize_text_field( $error ) );
			update_post_meta( $chapter_id, '_mbi_source_unavailable_checked_at', current_time( 'mysql' ) );
		}

		$out = array( 'action' => 'unavailable', 'url' => $failed_url, 'payload' => $payload, 'message' => 'Capítulo indisponível na origem; removido da lista esperada para evitar loop.' );
		$this->db->log( 'warning', 'Capítulo marcado como indisponível na origem durante o reparo.', array( 'manga_id' => $manga_id, 'chapter' => $number, 'url' => $failed_url, 'error' => $error ) );
		return $out;
	}

	private function process_manga_group( array $jobs, array $settings, array &$summary ): void {
		if ( count( $jobs ) < 2 || ! function_exists( 'curl_multi_init' ) ) {
			foreach ( $jobs as $job ) {
				$this->process_single_regular_job( $job, $settings, $summary );
			}
			return;
		}

		$prefetched = $this->parallel_fetch_chapter_html( $jobs );
		$network_failures = 0;
		foreach ( $jobs as $job ) {
			$this->refresh_worker_lock();
			$url = esc_url_raw( (string) ( $job->payload['url'] ?? '' ) );
			if ( $url && ! empty( $prefetched[ (int) $job->id ]['ok'] ) ) {
				$this->prefetched_html[ $url ] = (string) $prefetched[ (int) $job->id ]['html'];
				$this->process_single_regular_job( $job, $settings, $summary );
				continue;
			}

			$error = sanitize_text_field( (string) ( $prefetched[ (int) $job->id ]['error'] ?? 'Falha de rede ao buscar a obra.' ) );
			if ( $this->resolver_pressure_error( $error ) ) {
				set_transient( 'mbi_network_pressure', 1, 10 * MINUTE_IN_SECONDS );
				set_transient( 'mbi_chapter_network_backoff', 1, 10 * MINUTE_IN_SECONDS );
				// A tentativa paralela falhou por falta de thread DNS. Refaz somente este item
				// de forma serial, sem condenar todo o lote e sem abrir outra rajada de sockets.
				usleep( 120000 );
				$this->process_single_regular_job( $job, $settings, $summary );
				continue;
			}
			if ( preg_match( '/HTTP\s*(?:0|301|302|303|307|308)\b/iu', $error ) ) {
				$this->process_single_regular_job( $job, $settings, $summary );
				continue;
			}
			if ( $this->origin_pressure_error( $error ) ) {
				// Falha temporária preserva a tarefa atual, com tentativas limitadas.
				if ( ! empty( $job->payload['serial_batch'] ) ) {
					$this->retry_serial_network_job( $job, $error, 30, $summary );
					$network_failures++;
					continue;
				}
				$delay = $this->defer_source_job( $job, $error, (int) ( $prefetched[ (int) $job->id ]['retry_after'] ?? 0 ) );
				$network_failures++;
				$summary['retrying']++;
				$summary['errors'][] = $error;
				$this->runtime( 'source-cooldown', sprintf( 'Origem em cooldown por %d min após %s.', max( 1, (int) ceil( $delay / 60 ) ), $error ), array( 'url' => $url ) );
				continue;
			}
			// Capítulo malformado não pode bloquear todas as obras seguintes.
			if ( ! empty( $job->payload['serial_batch'] ) ) {
				$this->db->complete( (int) $job->id );
				$this->release_serial_after_terminal_failure( $job, $error );
				$summary['failed']++;
				$summary['errors'][] = $error;
				continue;
			}
			$outcome = $this->db->fail( $job, $error, max( 1, (int) $settings['max_attempts'] ) );
			$summary[ $outcome ]++;
			$summary['errors'][] = $error;
		}

		if ( $network_failures >= 2 ) {
			set_transient( 'mbi_network_pressure', 1, 10 * MINUTE_IN_SECONDS );
			set_transient( 'mbi_chapter_network_backoff', 1, 10 * MINUTE_IN_SECONDS );
		}
	}


	private function process_chapter_group( array $jobs, array $settings, array &$summary ): void {
		if ( count( $jobs ) < 2 || ! function_exists( 'curl_multi_init' ) ) {
			foreach ( $jobs as $job ) {
				$this->process_single_regular_job( $job, $settings, $summary );
			}
			return;
		}

		$prefetched = $this->parallel_fetch_chapter_html( $jobs );
		$network_failures = 0;
		foreach ( $jobs as $job ) {
			$this->refresh_worker_lock();
			$url = esc_url_raw( (string) ( $job->payload['url'] ?? '' ) );
			if ( $url && ! empty( $prefetched[ (int) $job->id ]['ok'] ) ) {
				$this->prefetched_html[ $url ] = (string) $prefetched[ (int) $job->id ]['html'];
				$this->process_single_regular_job( $job, $settings, $summary );
				continue;
			}

			$error = sanitize_text_field( (string) ( $prefetched[ (int) $job->id ]['error'] ?? 'Falha de rede ao buscar o capítulo.' ) );

			// O caminho legado de grupos usa a mesma política limitada de retentativa.
			if ( ! empty( $job->payload['serial_batch'] ) && $this->origin_pressure_error( $error ) ) {
				$this->retry_serial_network_job( $job, $error, 30, $summary );
				$network_failures++;
				continue;
			}

			if ( $this->resolver_pressure_error( $error ) ) {
				set_transient( 'mbi_network_pressure', 1, 10 * MINUTE_IN_SECONDS );
				set_transient( 'mbi_chapter_network_backoff', 1, 10 * MINUTE_IN_SECONDS );
				usleep( 120000 );
				$this->process_single_regular_job( $job, $settings, $summary );
				continue;
			}

			// v1.6.6: durante reparos, um 404 não é repetido cegamente. A página atual
			// da obra é relida para descobrir se o mesmo capítulo mudou de URL.
			$recovery = $this->recover_repair_chapter_reference( (array) $job->payload, $url, $error );
			if ( 'remapped' === (string) ( $recovery['action'] ?? '' ) ) {
				$job->payload = (array) $recovery['payload'];
				$this->db->update_job_payload( (int) $job->id, (array) $job->payload );
				$this->process_single_regular_job( $job, $settings, $summary );
				continue;
			}
			if ( 'unavailable' === (string) ( $recovery['action'] ?? '' ) ) {
				$this->db->complete( (int) $job->id );
				$this->after_regular_job_completed( $job );
				$summary['completed']++;
				continue;
			}

			// HTTP 0/redirect pode ser uma limitação do curl_multi/HTTP2. Fazemos uma única
			// tentativa síncrona controlada; demais erros de rede continuam sem duplicar requests.
			if ( preg_match( '/HTTP\s*(?:0|301|302|303|307|308)\b/iu', $error ) ) {
				$this->process_single_regular_job( $job, $settings, $summary );
				continue;
			}

			if ( $this->origin_pressure_error( $error ) ) {
				$delay = $this->defer_source_job( $job, $error, (int) ( $prefetched[ (int) $job->id ]['retry_after'] ?? 0 ) );
				$network_failures++;
				$summary['retrying']++;
				$summary['errors'][] = $error;
				$this->runtime( 'source-cooldown', sprintf( 'Origem em cooldown por %d min após %s.', max( 1, (int) ceil( $delay / 60 ) ), $error ), array( 'url' => $url ) );
				continue;
			}
			if ( ! empty( $job->payload['serial_batch'] ) ) {
				$this->db->complete( (int) $job->id );
				$this->release_serial_after_terminal_failure( $job, $error );
				$summary['failed']++;
				$summary['errors'][] = $error;
				continue;
			}
			$outcome = $this->db->fail( $job, $error, max( 1, (int) $settings['max_attempts'] ) );
			if ( 'failed' === $outcome ) {
				$this->fail_manual_update( (array) $job->payload, $error );
			}
			$summary[ $outcome ]++;
			$summary['errors'][] = $error;
		}
		if ( $network_failures >= max( 2, (int) ceil( count( $jobs ) / 3 ) ) ) {
			set_transient( 'mbi_network_pressure', 1, 10 * MINUTE_IN_SECONDS );
			set_transient( 'mbi_chapter_network_backoff', 1, 10 * MINUTE_IN_SECONDS );
			$this->runtime( 'network-backoff', 'Pressão de rede detectada. O motor seguirá temporariamente em modo serial para continuar avançando sem sobrecarregar o servidor.', array( 'failures' => $network_failures, 'group' => count( $jobs ) ) );
		}
	}

	private function retry_manual_network_job( object $job, string $error, int $delay, array &$summary ): void {
		$outcome = $this->db->fail( $job, $error, 4 );
		if ( $outcome === 'retrying' ) { $this->db->defer( $job, max( $delay, MBI_Resource_Guard::wait_seconds() ), $error ); }
		else { $this->fail_manual_update( (array) $job->payload, $error ); }
		$summary[$outcome]++; $summary['errors'][] = $error;
		$this->runtime( 'source-cooldown', $outcome === 'failed' ? 'Capítulo enviado para revisão após 4 falhas; demais itens continuam.' : 'Rede indisponível; aguardando antes de repetir o capítulo.', array( 'job_id' => (int) $job->id, 'error' => $error ) );
	}

	private function process_single_regular_job( object $job, array $settings, array &$summary ): void {
		try {
			$this->run_job( $job );
			$this->db->complete( (int) $job->id );
			$this->after_regular_job_completed( $job );
			$summary['completed']++;
		} catch ( MBI_Skip_Exception $exception ) {
			$this->db->complete( (int) $job->id );
			$this->db->log( 'warning', 'Item ignorado sem nova tentativa: ' . $exception->getMessage(), $job->payload );
			if ( ! empty( $job->payload['serial_batch'] ) ) {
				if ( ! empty( $job->payload['pipeline_phase'] ) || $this->source_defect_error( $exception->getMessage() ) ) {
					$this->release_serial_after_terminal_failure( $job, $exception->getMessage() );
				} else {
					$this->advance_serial_batch( (string) $job->payload['serial_batch'], 0, false );
				}
			}
			$summary['completed']++;
		} catch ( MBI_Resource_Wait $exception ) {
			$resource_yield = $exception->delay <= 3 && ! $this->is_paused() && MBI_Resource_Guard::wait_seconds() === 0 && MBI_Resource_Guard::has_memory() && preg_match( '/^(?:Validando páginas|Rodada limitada para liberar recursos|Reunindo lista paginada|Conferindo lista final|Lista da origem mudou)/u', $exception->getMessage() );
			if ( $resource_yield ) { $this->db->defer_budget( $job, $exception->delay, $exception->getMessage(), $job->job_type === 'chapter' ); }
			else { $this->db->defer( $job, $exception->delay, $exception->getMessage() ); }
			if ( ! $this->is_paused() ) { $this->schedule_worker_wakeup( $exception->delay ); }
			$summary['retrying']++;
			$this->runtime( 'resource-wait', $exception->getMessage(), array( 'job_id' => (int) $job->id ) );
			return;
		} catch ( MBI_Source_Compatibility_Exception $exception ) {
			// A parser incompatibility affects the source contract, not each candidate.
			// Keep this exact claim and cursor; a future layout change cannot drain the lot.
			$error = $exception->getMessage();
			$context = array( 'url' => (string) ( $job->payload['url'] ?? '' ), 'job_id' => (int) $job->id, 'error' => $error );
			update_option( 'mbi_source_compatibility_error', $context, false );
			update_option( 'mbi_queue_paused', 1, false );
			$this->db->defer( $job, 30, $error );
			$this->db->log( 'error', 'Importação pausada por incompatibilidade no carregador da origem. Tarefa, lote e publicações preservados.', $context );
			$this->runtime( 'source-compatibility', 'Importação pausada: não foi possível interpretar o carregador de capítulos. Atualize o importador antes de retomar. Motivo: ' . $error, $context );
			$summary['retrying']++; $summary['errors'][] = $error;
			return;
		} catch ( MBI_Source_Backoff_Exception $exception ) {
			if ( ! empty( $job->payload['manual_update'] ) && ! str_contains( $exception->getMessage(), 'SOURCE_COOLDOWN' ) ) {
				$this->retry_manual_network_job( $job, $exception->getMessage(), $exception->delay, $summary ); return;
			}
			if ( ! empty( $job->payload['serial_batch'] ) ) {
				$this->retry_serial_network_job( $job, $exception->getMessage(), $exception->delay, $summary );
				return;
			}
			$this->db->defer( $job, $exception->delay, $exception->getMessage() );
			$summary['retrying']++;
			$summary['errors'][] = $exception->getMessage();
			$this->runtime(
				'source-cooldown',
				sprintf( 'Origem %s em espera por cerca de %d min. Nenhuma nova conexão será aberta nesse intervalo.', $exception->source_host, max( 1, (int) ceil( $exception->delay / 60 ) ) ),
				array( 'job_id' => (int) $job->id, 'host' => $exception->source_host, 'delay' => $exception->delay )
			);
			return;
		} catch ( Throwable $exception ) {
			$error = $exception->getMessage();
			// DNS/thread failure belongs to our hosting, not to the work. Preserve
			// the current item, reduce network pressure and bound automatic retries.
			if ( $this->resolver_pressure_error( $error ) ) {
				set_transient( 'mbi_network_pressure', 1, 10 * MINUTE_IN_SECONDS );
				set_transient( 'mbi_chapter_network_backoff', 1, 10 * MINUTE_IN_SECONDS );
				if ( (int) $job->attempts < 2 ) {
					$outcome = $this->db->fail( $job, $error, 3 );
					$summary[ $outcome ]++;
				} else {
					update_option( 'mbi_queue_paused', 1, false );
					$this->db->defer( $job, 60, $error );
					$summary['retrying']++;
					$this->db->log( 'error', 'Processamento pausado: o resolvedor DNS da hospedagem falhou três vezes. O conteúdo e a tarefa foram preservados.', array( 'url' => $job->payload['url'] ?? '', 'job_id' => $job->id, 'error' => $error ) );
				}
				$this->runtime( 'hosting-dns-pressure', $this->is_paused() ? 'Importação pausada por falha DNS da hospedagem. Baixe o diagnóstico global.' : 'Falha DNS na hospedagem. Nova tentativa limitada e sequencial agendada.', array( 'error' => $error, 'job_id' => $job->id ) );
				$summary['errors'][] = $error;
				return;
			}

			// 404/410 na página da obra é definitivo e específico daquele URL: não
			// desperdiça cinco tentativas nem paralisa as próximas candidatas.
			$permanent_manga_error = 'manga' === (string) $job->job_type
				&& ! empty( $job->payload['serial_batch'] )
				&& (bool) preg_match( '/HTTP\s*(?:404|410)\b/iu', $error );
			if ( $permanent_manga_error ) {
				$this->db->complete( (int) $job->id );
				$this->release_serial_after_terminal_failure( $job, $error );
				$summary['failed']++;
				$summary['errors'][] = $error;
				return;
			}

			if ( $this->origin_pressure_error( $error ) ) {
				if ( ! empty( $job->payload['manual_update'] ) ) { $this->retry_manual_network_job( $job, $error, max( 30, MBI_Resource_Guard::wait_seconds() ), $summary ); return; }
				if ( ! empty( $job->payload['serial_batch'] ) ) {
					$this->retry_serial_network_job( $job, $error, max( 30, MBI_Resource_Guard::wait_seconds() ), $summary );
					return;
				}
				$delay = $this->defer_source_job( $job, $error );
				$summary['retrying']++;
				$summary['errors'][] = $error;
				$this->runtime( 'source-cooldown', sprintf( 'Origem temporariamente indisponível; retomada em cerca de %d min.', max( 1, (int) ceil( $delay / 60 ) ) ), array( 'job_id' => (int) $job->id, 'url' => (string) ( $job->payload['url'] ?? '' ) ) );
				return;
			}

			// Capítulos antigos que continuam existindo na listagem da obra, mas cuja página
			// não contém nenhuma imagem, causavam um loop caro: 5+ releituras, falha terminal,
			// reabertura da mesma obra e nova fila. Em lote serial/reparo, após uma segunda
			// leitura vazia nós relimos a obra uma vez para remapear o número; se não houver
			// alternativa, o capítulo é marcado indisponível na origem e sai da expectativa.
			$empty_confirmed_chapter = 'chapter' === (string) $job->job_type
				&& ( ! empty( $job->payload['manual_update'] ) || ( ! empty( $job->payload['serial_batch'] ) && ! empty( $job->payload['repair'] ) ) )
				&& (int) $job->attempts >= 1
				&& (bool) preg_match( '/Nenhuma página de imagem foi encontrada no capítulo/iu', $error );
			if ( $empty_confirmed_chapter ) {
				$url = esc_url_raw( (string) ( $job->payload['url'] ?? '' ) );
				$recovery = $this->recover_repair_chapter_reference( (array) $job->payload, $url, $error );
				if ( 'unavailable' === (string) ( $recovery['action'] ?? '' ) ) {
					$this->db->complete( (int) $job->id );
					$this->after_regular_job_completed( $job );
					$summary['completed']++;
					return;
				}
				if ( 'remapped' === (string) ( $recovery['action'] ?? '' ) ) {
					$job->payload = (array) $recovery['payload'];
					$this->db->update_job_payload( (int) $job->id, (array) $job->payload );
					try {
						$this->run_job( $job );
						$this->db->complete( (int) $job->id );
						$this->after_regular_job_completed( $job );
						$summary['completed']++;
						return;
					} catch ( Throwable $remapped_exception ) {
						$error = $remapped_exception->getMessage();
					}
				}
			}

			if ( str_contains( $error, 'VALIDACAO_IMAGENS:' ) && ! $this->origin_pressure_error( $error ) && empty( $job->payload['serial_batch'] ) ) {
				$this->db->fail( $job, $error, 1 ); $this->fail_manual_update( (array) $job->payload, $error );
				$summary['failed']++; $summary['errors'][] = $error; return;
			}

			// No fluxo serial, qualquer erro restante encerra somente a obra atual.
			// Isso elimina ciclos de retry que mantinham a fila inteira parada.
			if ( ! empty( $job->payload['serial_batch'] ) ) {
				$this->db->complete( (int) $job->id );
				$this->release_serial_after_terminal_failure( $job, $error );
				$summary['failed']++;
				$summary['errors'][] = $error;
				return;
			}
			$outcome = $this->db->fail( $job, $error, max( 1, (int) $settings['max_attempts'] ) );
			if ( 'failed' === $outcome ) {
				$this->fail_manual_update( (array) $job->payload, $error );
				$this->release_serial_after_terminal_failure( $job, $error );
			}
			$summary[ $outcome ]++;
			$summary['errors'][] = $error;
		}
	}

	/** Retry the same claim; a cooldown is not another unsuccessful request. */
	private function retry_serial_network_job( object $job, string $error, int $delay, array &$summary ): void {
		$batch_id = (string) ( $job->payload['serial_batch'] ?? '' );
		$state = $this->serial_state();
		if ( empty( $state['active'] ) || (string) ( $state['id'] ?? '' ) !== $batch_id
			|| ( ! empty( $job->payload['manga_id'] ) && ! empty( $state['current_manga_id'] ) && (int) $job->payload['manga_id'] !== (int) $state['current_manga_id'] )
			|| ( isset( $job->payload['serial_cursor'] ) && (int) $job->payload['serial_cursor'] !== (int) ( $state['cursor'] ?? 0 ) ) ) {
			$this->db->complete( (int) $job->id );
			$summary['completed']++;
			return;
		}
		$delay = max( 30, $delay, MBI_Resource_Guard::wait_seconds(), $this->source_circuit_remaining( (string) ( $job->payload['url'] ?? '' ) ) );
		if ( str_contains( $error, 'SOURCE_COOLDOWN' ) ) {
			$this->db->defer( $job, $delay, $error );
			$summary['retrying']++;
		} else {
			$outcome = $this->db->fail( $job, $error, 4 );
			$summary[$outcome]++;
			$summary['errors'][] = $error;
			if ( $outcome === 'failed' ) {
				// Four actual requests failed. Preserve all posts and isolate this work;
				// never truncate the remaining selection or clear Retry-After.
				$this->release_serial_after_terminal_failure( $job, $error );
				return;
			}
			$delay = max( $delay, min( 240, 30 * ( 2 ** min( 3, (int) $job->attempts ) ) ) );
			$this->db->defer( $job, $delay, $error );
		}
		$this->runtime( 'source-cooldown', sprintf( 'Falha temporária: tarefa preservada. Nova tentativa em %d s (%d de 4 tentativas realizadas).', $delay, (int) $job->attempts + ( str_contains( $error, 'SOURCE_COOLDOWN' ) ? 0 : 1 ) ), array( 'job_id' => (int) $job->id, 'url' => $job->payload['url'] ?? '', 'batch' => $batch_id, 'delay' => $delay ) );
	}

	/**
	 * Uma obra inválida fica em rascunho com diagnóstico, mas jamais congela o lote.
	 * Os jobs restantes somente dessa obra são descartados e o motor tenta a próxima
	 * candidata até obter a quantidade de obras completas solicitada.
	 */
	private function release_serial_after_terminal_failure( object $job, string $error ): void {
		$payload = is_array( $job->payload ?? null ) ? $job->payload : array();
		$batch_id = sanitize_text_field( (string) ( $payload['serial_batch'] ?? '' ) );
		if ( ! $batch_id ) {
			return;
		}
		$state = $this->serial_state();
		if ( empty( $state['active'] ) || (string) ( $state['id'] ?? '' ) !== $batch_id ) {
			return;
		}
		if ( ! empty( $payload['pipeline_phase'] ) && ( ( $state['phase'] ?? '' ) !== $payload['pipeline_phase'] || ( $payload['generation'] ?? '' ) !== $this->current_generation() || $this->is_paused() ) ) { return; }
		$manga_id = absint( $payload['manga_id'] ?? $payload['manga_id_hint'] ?? $state['current_manga_id'] ?? 0 );
		$job_cursor = isset( $payload['serial_cursor'] ) ? absint( $payload['serial_cursor'] ) : null;
		if ( null !== $job_cursor && $job_cursor !== absint( $state['cursor'] ?? 0 ) ) {
			return;
		}
		$current_manga_id = absint( $state['current_manga_id'] ?? 0 );
		if ( $manga_id > 0 && $current_manga_id > 0 && $manga_id !== $current_manga_id ) {
			return;
		}
		$state['skipped'] = (int) ( $state['skipped'] ?? 0 ) + 1;
		$state['last_skipped'] = array( 'url' => $state['current_url'] ?? '', 'error' => sanitize_text_field( $error ), 'at' => current_time( 'mysql' ) );
		$state['consecutive_network_failures52'] = $this->origin_pressure_error( $error ) ? (int) ( $state['consecutive_network_failures52'] ?? 0 ) + 1 : 0;
		if ( $state['consecutive_network_failures52'] >= 2 ) { $state['catalog_source_stopped'] = true; }
		update_option( 'mbi_serial_batch', $state, false );
		// A confirmed defect is isolated for 24h. Explicit manual URL requests
		// can retry earlier; the next bulk run does not hammer the same broken work.
		if ( $this->source_defect_error( $error ) && ! empty( $state['current_url'] ) ) {
			set_transient( 'mbi_broken_work_' . md5( $this->canonical_source_url( (string) $state['current_url'] ) ), sanitize_text_field( $error ), DAY_IN_SECONDS );
		}
		if ( $manga_id > 0 ) {
			if ( 'publish' !== get_post_status( $manga_id ) ) {
				update_post_meta( $manga_id, '_mbi_ready', 0 );
			}
			update_post_meta( $manga_id, '_mbi_validation_status', 'review' );
			update_post_meta( $manga_id, '_mbi_last_import_error', sanitize_text_field( $error ) );
			update_post_meta( $manga_id, '_mbi_validation_checked_at', current_time( 'mysql' ) );
			$this->db->abandon_serial_manga_work( $batch_id, $manga_id, (int) $job->id );
		}
		$this->db->log( 'warning', 'Obra ignorada após falha da origem; publicações preservadas e lote avançou para a próxima candidata.', array( 'batch' => $batch_id, 'manga_id' => $manga_id, 'url' => $payload['url'] ?? '', 'error' => $error ) );
		$this->runtime( 'skipping-broken-manga', 'Uma obra não pôde ser concluída e ficou em revisão. Tentando a próxima sem travar o lote.', array( 'manga_id' => $manga_id, 'error' => $error ) );
		$this->advance_serial_batch( $batch_id, $manga_id, false );
	}

	/** A drained serial queue must either finish the work or explain and advance. */
	private function finish_idle_serial_manga( array $state ): void {
		$batch = (string) ( $state['id'] ?? '' );
		$manga_id = absint( $state['current_manga_id'] ?? 0 );
		if ( ! $batch || ! $manga_id || $this->is_paused() || ! $this->owns_worker_lock() ) { return; }
		$work = $this->db->batch_work_counts( $batch );
		if ( ! empty( $work['pending'] ) || ! empty( $work['processing'] ) ) { return; }
		$this->runtime( 'finalizing-manga', 'Fila da obra concluída. Conferindo publicação e avanço.', array( 'manga_id' => $manga_id, 'batch' => $batch ) );
		update_post_meta( $manga_id, '_mbi_serial_batch_id', $batch );
		$this->maybe_publish_ready_manga( $manga_id );
		$fresh = $this->serial_state();
		if ( empty( $fresh['active'] ) || (string) ( $fresh['id'] ?? '' ) !== $batch || (int) ( $fresh['cursor'] ?? 0 ) !== (int) ( $state['cursor'] ?? 0 ) ) { return; }
		if ( '1' === (string) get_post_meta( $manga_id, '_mbi_ready', true ) && ! get_post_meta( $manga_id, '_mbi_finalization_block', true ) ) { $this->advance_serial_batch( $batch, $manga_id, true ); return; }
		$reason = (string) get_post_meta( $manga_id, '_mbi_finalization_block', true );
		if ( ! $reason ) { $reason = 'A fila terminou, mas a obra não passou pela validação final. Revisão necessária.'; }
		update_post_meta( $manga_id, '_mbi_last_import_error', $reason );
		update_post_meta( $manga_id, '_mbi_validation_status', 'review' );
		$fresh['skipped'] = (int) ( $fresh['skipped'] ?? 0 ) + 1;
		$fresh['last_skipped'] = array( 'url' => $fresh['current_url'] ?? '', 'error' => $reason, 'at' => current_time( 'mysql' ) );
		update_option( 'mbi_serial_batch', $fresh, false );
		$this->db->log( 'error', 'Finalização bloqueada: ' . $reason . ' Obra preservada para revisão; lote avançou.', array( 'manga_id' => $manga_id, 'batch' => $batch, 'cursor' => $state['cursor'] ?? 0, 'url' => $state['current_url'] ?? '' ) );
		$this->advance_serial_batch( $batch, $manga_id, false );
	}

	/** Finaliza a obra uma única vez, depois que o último capítulo do lote terminou. */
	private function after_regular_job_completed( object $job ): void {
		if ( 'chapter' !== (string) $job->job_type || empty( $job->payload['serial_batch'] ) ) {
			return;
		}
		$batch_id = sanitize_text_field( (string) $job->payload['serial_batch'] );
		$manga_id = absint( $job->payload['manga_id'] ?? 0 );
		$work = $this->db->batch_manga_work_counts( $batch_id, $manga_id );
		if ( 0 === (int) $work['pending'] && 0 === (int) $work['processing'] && 0 === (int) $work['failed'] ) {
			$this->runtime( 'finalizing-manga', 'Todos os capítulos foram mapeados. Fazendo a validação final da obra.', array( 'manga_id' => $manga_id, 'batch' => $batch_id ) );
			$state = $this->serial_state();
			if ( (string) ( $state['id'] ?? '' ) === $batch_id && absint( $state['current_manga_id'] ?? 0 ) === $manga_id ) { $this->finish_idle_serial_manga( $state ); }
		}
	}


	private function parallel_fetch_chapter_html( array $jobs ): array {
		$multi = curl_multi_init();
		if ( defined( 'CURLMOPT_MAX_TOTAL_CONNECTIONS' ) ) {
			curl_multi_setopt( $multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 3 );
		}
		if ( defined( 'CURLMOPT_MAX_HOST_CONNECTIONS' ) ) {
			curl_multi_setopt( $multi, CURLMOPT_MAX_HOST_CONNECTIONS, 2 );
		}
		if ( defined( 'CURLMOPT_PIPELINING' ) && defined( 'CURLPIPE_MULTIPLEX' ) ) {
			curl_multi_setopt( $multi, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX );
		}

		$share = null;
		if ( function_exists( 'curl_share_init' ) && defined( 'CURLOPT_SHARE' ) && defined( 'CURLSHOPT_SHARE' ) && defined( 'CURL_LOCK_DATA_DNS' ) ) {
			$share = curl_share_init();
			if ( $share ) {
				curl_share_setopt( $share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS );
				if ( defined( 'CURL_LOCK_DATA_SSL_SESSION' ) ) {
					@curl_share_setopt( $share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION );
				}
			}
		}

		$handles = array();
		$results = array();
		$bodies = array();
		$retry_headers = array();
		foreach ( $jobs as $job ) {
			$url = esc_url_raw( (string) ( $job->payload['url'] ?? '' ) );
			try {
				$this->assert_allowed_source_url( $url );
			} catch ( Throwable $exception ) {
				$results[ (int) $job->id ] = array( 'ok' => false, 'error' => $exception->getMessage() );
				continue;
			}
			$remaining = $this->source_circuit_remaining( $url );
			if ( $remaining > 0 ) {
				$results[ (int) $job->id ] = array( 'ok' => false, 'error' => 'SOURCE_COOLDOWN ' . $remaining . 's' );
				continue;
			}

			$ch = curl_init( $url );
			$options = array(
				CURLOPT_RETURNTRANSFER    => true,
				CURLOPT_HTTPHEADER        => array(
					'User-Agent: ' . $this->user_agent(),
					'Accept: text/html,application/xhtml+xml',
					'Connection: keep-alive',
				),
				CURLOPT_CONNECTTIMEOUT    => 8,
				CURLOPT_TIMEOUT           => 15,
				CURLOPT_FOLLOWLOCATION    => false,
				CURLOPT_SSL_VERIFYPEER    => true,
				CURLOPT_SSL_VERIFYHOST    => 2,
				CURLOPT_ENCODING          => '',
				CURLOPT_DNS_CACHE_TIMEOUT => 600,
				CURLOPT_FORBID_REUSE      => false,
				CURLOPT_FRESH_CONNECT     => false,
			);
			$job_id = (int) $job->id;
			$bodies[ $job_id ] = '';
			$retry_headers[ $job_id ] = '';
			$options[ CURLOPT_WRITEFUNCTION ] = static function ( $handle, string $chunk ) use ( &$bodies, $job_id ): int {
				if ( strlen( $bodies[ $job_id ] ) + strlen( $chunk ) > 8 * MB_IN_BYTES ) {
					return 0;
				}
				$bodies[ $job_id ] .= $chunk;
				return strlen( $chunk );
			};
			$options[ CURLOPT_HEADERFUNCTION ] = static function ( $handle, string $header ) use ( &$retry_headers, $job_id ): int {
				if ( 0 === stripos( $header, 'HTTP/' ) ) {
					$retry_headers[ $job_id ] = '';
				} elseif ( 0 === stripos( $header, 'Retry-After:' ) ) {
					$retry_headers[ $job_id ] = trim( substr( $header, 12 ) );
				}
				return strlen( $header );
			};
			if ( $share ) {
				$options[ CURLOPT_SHARE ] = $share;
			}
			if ( defined( 'CURLOPT_TCP_KEEPALIVE' ) ) {
				$options[ CURLOPT_TCP_KEEPALIVE ] = 1;
			}
			if ( defined( 'CURLOPT_PIPEWAIT' ) ) {
				$options[ CURLOPT_PIPEWAIT ] = true;
			}
			if ( defined( 'CURL_HTTP_VERSION_2TLS' ) ) {
				$options[ CURLOPT_HTTP_VERSION ] = CURL_HTTP_VERSION_2TLS;
			}
			if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
				$options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTPS;
			}
			curl_setopt_array( $ch, $options );
			curl_multi_add_handle( $multi, $ch );
			$handles[ (int) $job->id ] = $ch;
		}

		if ( $handles ) {
			$running = null;
			do {
				$status = curl_multi_exec( $multi, $running );
				if ( $running ) {
					if ( function_exists( 'curl_multi_poll' ) ) {
						curl_multi_poll( $multi, 0.35 );
					} else {
						$selected = curl_multi_select( $multi, 0.35 );
						if ( -1 === $selected ) {
							usleep( 20000 );
						}
					}
				}
			} while ( $running && CURLM_OK === $status );
		}

		foreach ( $handles as $job_id => $ch ) {
			$code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$error = (string) curl_error( $ch );
			$html  = (string) ( $bodies[ $job_id ] ?? '' );
			curl_multi_remove_handle( $multi, $ch );
			curl_close( $ch );

			if ( 200 === $code && ! $error && strlen( $html ) >= 500 && strlen( $html ) <= 8 * MB_IN_BYTES ) {
				$results[ $job_id ] = array( 'ok' => true, 'html' => $html );
			} else {
				$results[ $job_id ] = array( 'ok' => false, 'error' => $error ?: 'HTTP ' . $code );
				if ( in_array( $code, array( 429, 503 ), true ) ) {
					$retry_after = $this->retry_after_seconds( (string) ( $retry_headers[ $job_id ] ?? '' ) );
					$results[ $job_id ]['retry_after'] = $retry_after;
				}
			}
		}
		curl_multi_close( $multi );
		if ( $share && function_exists( 'curl_share_close' ) ) {
			curl_share_close( $share );
		}
		return $results;
	}

	private function process_image_group( array $jobs, array $settings, array &$summary ): void {
		// v1.5.1: nenhum job de imagem pode efetuar download. Jobs legados são convertidos
		// para metadados de URL remota pelo mesmo caminho seguro de import_image().
		foreach ( $jobs as $job ) {
			$this->process_single_image_job( $job, $settings, $summary );
		}
	}

	private function process_single_image_job( object $job, array $settings, array &$summary ): void {
		try {
			$this->import_image( $job->payload );
			$this->db->complete( (int) $job->id );
			$summary['completed']++;
		} catch ( Throwable $exception ) {
			$this->fail_image_job( $job, $exception, $settings, $summary );
		}
	}

	private function fail_image_job( object $job, Throwable $exception, array $settings, array &$summary ): void {
		$outcome = $this->db->fail( $job, $exception->getMessage(), max( 1, (int) $settings['max_attempts'] ) );
		$summary[ $outcome ]++;
		$summary['errors'][] = $exception->getMessage();
	}

	private function parallel_download_image_files( array $entries ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$multi   = curl_multi_init();
		$handles = array();
		$results = array();
		foreach ( $entries as $entry ) {
			$job = $entry['job'];
			$url = (string) $entry['url'];
			if ( ! wp_http_validate_url( $url ) ) {
				$results[ (int) $job->id ] = array( 'ok' => false, 'error' => 'URL de imagem insegura ou inválida.' );
				continue;
			}
			$tmp = wp_tempnam( $url );
			$fp  = $tmp ? @fopen( $tmp, 'wb' ) : false;
			if ( ! $tmp || ! $fp ) {
				$results[ (int) $job->id ] = array( 'ok' => false, 'error' => 'Não foi possível criar arquivo temporário.' );
				continue;
			}
			$headers = array( 'User-Agent: ' . $this->user_agent(), 'Accept: image/*' );
			if ( ! empty( $job->payload['referer'] ) ) {
				$headers[] = 'Referer: ' . esc_url_raw( (string) $job->payload['referer'] );
			}
			$ch = curl_init( $url );
			$options = array(
				CURLOPT_FILE            => $fp,
				CURLOPT_HTTPHEADER      => $headers,
				CURLOPT_CONNECTTIMEOUT  => 15,
				CURLOPT_TIMEOUT         => 60,
				CURLOPT_FOLLOWLOCATION  => false,
				CURLOPT_SSL_VERIFYPEER  => true,
				CURLOPT_SSL_VERIFYHOST  => 2,
				CURLOPT_NOPROGRESS      => false,
				CURLOPT_XFERINFOFUNCTION => static function ( $resource, $download_size, $downloaded, $upload_size, $uploaded ): int {
					return $downloaded > 25 * MB_IN_BYTES ? 1 : 0;
				},
			);
			if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) && defined( 'CURLPROTO_HTTP' ) ) {
				$options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
			}
			curl_setopt_array( $ch, $options );
			curl_multi_add_handle( $multi, $ch );
			$handles[ (int) $job->id ] = array( 'handle' => $ch, 'file' => $fp, 'tmp' => $tmp );
		}

		if ( $handles ) {
			$running = null;
			do {
				$status = curl_multi_exec( $multi, $running );
				if ( $running ) {
					if ( function_exists( 'curl_multi_poll' ) ) {
						curl_multi_poll( $multi, 0.35 );
					} else {
						curl_multi_select( $multi, 0.35 );
					}
				}
			} while ( $running && CURLM_OK === $status );
		}

		foreach ( $handles as $job_id => $meta ) {
			$ch = $meta['handle'];
			@fclose( $meta['file'] );
			$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$content_type = strtolower( (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE ) );
			$error = curl_error( $ch );
			curl_multi_remove_handle( $multi, $ch );
			curl_close( $ch );
			if ( 200 === $code && ! $error && str_starts_with( $content_type, 'image/' ) && file_exists( $meta['tmp'] ) && filesize( $meta['tmp'] ) > 0 && filesize( $meta['tmp'] ) <= 25 * MB_IN_BYTES ) {
				$results[ $job_id ] = array( 'ok' => true, 'tmp' => $meta['tmp'], 'content_type' => $content_type );
			} else {
				@unlink( $meta['tmp'] );
				$results[ $job_id ] = array( 'ok' => false, 'error' => $error ?: 'HTTP ' . $code );
			}
		}
		curl_multi_close( $multi );
		return $results;
	}

	private function run_job( object $job ): void {
		$this->assert_current_generation( (array) $job->payload );
		switch ( $job->job_type ) {
			case 'catalog':
				$this->import_catalog( $job->payload );
				break;
			case 'starter':
				$this->import_starter( $job->payload );
				break;
			case 'manga':
				if ( 'collect' === ( $job->payload['pipeline_phase'] ?? '' ) ) { $this->collect_manga51( $job->payload ); }
				else { $this->import_manga( $job->payload ); }
				break;
			case 'chapter':
				$this->import_chapter( $job->payload );
				break;
			case 'cover_repair':
				$this->import_cover_repair( $job->payload );
				break;
			case 'chapter_page_repair':
				$this->import_chapter_page_repair( $job->payload );
				break;
			case 'image':
				$this->import_image( $job->payload );
				break;
			default:
				throw new RuntimeException( 'Tipo de job desconhecido: ' . $job->job_type );
		}
	}

	/** One bounded lookup per catalogue page instead of two post queries per URL. */
	private function catalog_existing_keys51( array $urls ): array {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) ), 120 ) as $chunk ) {
			$keys = array_map( fn( $u ) => $this->source_key( $u ), $chunk );
			$marks = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql = "SELECT m.meta_key,m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='mbi_manga' AND p.post_status NOT IN ('trash','auto-draft') AND ((m.meta_key='_mbi_source_key' AND m.meta_value IN ($marks)) OR (m.meta_key='_mbi_source_url' AND m.meta_value IN ($marks)))";
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( $keys, $chunk ) ) ) as $row ) { $out[ '_mbi_source_key' === $row->meta_key ? $row->meta_value : $this->source_key( $row->meta_value ) ] = true; }
		}
		return $out;
	}

	private function import_starter( array $payload ): void {
		$this->assert_current_generation( $payload );
		$mode = sanitize_key( (string) ( $payload['mode'] ?? 'catalog' ) );
		$mode = in_array( $mode, array( 'catalog', 'auto_catalog', 'catalog_full' ), true ) ? $mode : 'catalog';
		$limit = max( 1, min( 500, absint( $payload['limit'] ?? 10 ) ) );
		$scan_id = sanitize_text_field( (string) ( $payload['catalog_scan_id'] ?? '' ) );
		$state = $this->catalog_discovery_status();

		// Compatibilidade com um starter criado antes da v1.6.27: converte-o para
		// descoberta incremental em vez de continuar varrendo dezenas de páginas no
		// mesmo request PHP.
		if ( ! $scan_id ) {
			$scan_id = wp_generate_uuid4();
			$settings = $this->settings();
			$state = array(
				'id' => $scan_id, 'active' => true, 'mode' => $mode, 'limit' => $limit,
				'page' => max( 1, absint( $payload['catalog_page'] ?? 1 ) ),
				'page_limit' => max( 1, min( 500, (int) $settings['max_catalog_pages'] ) ),
				'items' => array(), 'seen' => array(),
				'candidate_limit' => 'catalog_full' === $mode ? 0 : $limit,
				'skipped_pages' => array(), 'consecutive_source_failures' => 0, 'page_backoffs' => array(),
				'generation' => (string) ( $payload['generation'] ?? $this->current_generation() ),
				'started_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			);
			update_option( 'mbi_catalog_discovery', $state, false );
		} elseif ( empty( $state['active'] ) || ! hash_equals( (string) $state['id'], $scan_id ) ) {
			throw new MBI_Skip_Exception( 'Descoberta de catálogo antiga/cancelada; nenhuma obra foi alterada.' );
		}

		// Uma importação manual ou reparo explícito sempre tem prioridade. O mesmo
		// starter é apenas adiado (sem consumir tentativa) e retoma depois.
		if ( $this->is_serial_active() ) {
			$state['updated_at'] = current_time( 'mysql' );
			update_option( 'mbi_catalog_discovery', $state, false );
			$base_wait = trailingslashit( $this->settings()['source_url'] ) . 'manga/';
			throw new MBI_Source_Backoff_Exception( 'CATALOG_YIELD: aguardando a obra explícita terminar.', $this->source_host( $base_wait ), 30 );
		}

		// Se a última rodada já terminou o mapeamento, basta iniciar o lote agora;
		// não faça outra requisição HTTP só para finalizar a descoberta.
		if ( ! empty( $state['ready_to_start'] ) ) {
			$items = array_values( (array) ( $state['items'] ?? array() ) );
			if ( ! $items ) {
				$state['active'] = false;
				$state['finished_at'] = current_time( 'mysql' );
				update_option( 'mbi_catalog_discovery', $state, false );
				$this->finish_empty_catalog_refill( $state );
				throw new MBI_Skip_Exception( 'A descoberta terminou sem obras novas.' );
			}
			$target = 'catalog_full' === $mode ? count( $items ) : $limit;
			update_option( 'mbi_catalog_discovery', $state, false );
			$serial = $this->start_serial_batch( $items, $mode, $target );
			$state['active'] = false;
			$state['ready_to_start'] = 0;
			$state['finished_at'] = current_time( 'mysql' );
			$state['serial_batch'] = (string) ( $serial['id'] ?? '' );
			$state['target'] = $serial['target'] ?? $target;
			update_option( 'mbi_catalog_discovery', $state, false );
			return;
		}

		$page = max( 1, absint( $state['page'] ?? 1 ) );
		$page_limit = max( 1, min( 500, absint( $state['page_limit'] ?? 1 ) ) );
		$base = trailingslashit( $this->settings()['source_url'] );
		$url = 1 === $page ? $base . 'manga/' : $base . 'manga/page/' . $page . '/';
		$this->runtime(
			'catalog-discovery',
			sprintf( 'Mapeando catálogo em segundo plano: página %d de até %d. Importação por URL e novos capítulos continuam disponíveis.', $page, $page_limit ),
			array( 'scan' => $scan_id, 'page' => $page, 'page_limit' => $page_limit )
		);

		try {
			// Cache curto só da descoberta; capítulos e obras continuam validados na origem.
			$cache_key = 'mbi_catalog_page_' . md5( $url );
			$data = get_transient( $cache_key );
			if ( ! is_array( $data ) || ! isset( $data['manga_urls'] ) ) {
				$html = $this->fetch_html( $url );
				$this->parser->load( $html, $url );
				$data = $this->parser->parse_catalog();
				if ( ! empty( $data['manga_urls'] ) ) {
					set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
				}
			}
			$state['consecutive_source_failures'] = 0;
		} catch ( MBI_Source_Backoff_Exception $exception ) {
			// CATALOG_YIELD é uma pausa voluntária para priorizar uma obra explícita;
			// não deve contar como falha da página.
			if ( str_starts_with( $exception->getMessage(), 'CATALOG_YIELD:' ) ) {
				throw $exception;
			}
			$backoffs = is_array( $state['page_backoffs'] ?? null ) ? $state['page_backoffs'] : array();
			$page_key = (string) $page;
			$backoffs[ $page_key ] = absint( $backoffs[ $page_key ] ?? 0 ) + 1;
			$state['page_backoffs'] = $backoffs;
			$state['updated_at'] = current_time( 'mysql' );
			if ( 1 === $page && $backoffs[ $page_key ] >= 2 ) {
				$state['active'] = false;
				$state['cancelled_at'] = current_time( 'mysql' );
				$state['cancel_reason'] = 'A primeira página da origem falhou duas vezes; carga encerrada sem travar.';
				update_option( 'mbi_catalog_discovery', $state, false );
				$this->finish_empty_catalog_refill( $state );
				$this->runtime( 'catalog-source-unavailable', 'Carga encerrada sem travar: a página inicial da origem falhou duas vezes. Tente novamente mais tarde.', array( 'url' => $url, 'error' => $exception->getMessage() ) );
				$this->db->log( 'error', 'Proteção antitrava encerrou a descoberta após duas falhas na primeira página da origem.', array( 'url' => $url, 'error' => $exception->getMessage() ) );
				throw new MBI_Skip_Exception( 'A origem não permitiu iniciar o catálogo após duas tentativas; o processo foi encerrado sem ficar preso.' );
			}

			// Duas respostas consecutivas de pressão na mesma página não podem
			// aprisionar o catálogo para sempre. A página é registrada e pulada;
			// as demais continuam somente depois do cooldown atual da origem.
			if ( $page > 1 && $backoffs[ $page_key ] >= 2 ) {
				$skipped = array_map( 'absint', (array) ( $state['skipped_pages'] ?? array() ) );
				if ( ! in_array( $page, $skipped, true ) ) {
					$skipped[] = $page;
				}
				$state['skipped_pages'] = array_values( array_unique( $skipped ) );
				$state['consecutive_source_failures'] = absint( $state['consecutive_source_failures'] ?? 0 ) + 1;
				$data = array( 'manga_urls' => array(), 'max_page' => $page_limit );
				update_option( 'mbi_catalog_discovery', $state, false );
				$this->db->log( 'warning', 'Página do catálogo pulada após duas pausas consecutivas da origem; a busca continuará na próxima página.', array( 'page' => $page, 'url' => $url, 'delay' => $exception->delay, 'error' => $exception->getMessage() ) );
			} else {
				update_option( 'mbi_catalog_discovery', $state, false );
				throw $exception;
			}
		} catch ( Throwable $exception ) {
			$error = (string) $exception->getMessage();
			$transient = $this->origin_pressure_error( $error );
			if ( $page > 1 && $transient ) {
				$state['skipped_pages'][] = $page;
				$state['consecutive_source_failures'] = absint( $state['consecutive_source_failures'] ?? 0 ) + 1;
				$data = array( 'manga_urls' => array(), 'max_page' => $page_limit );
				$this->db->log( 'warning', 'Página do catálogo indisponível nesta rodada; apenas essa página foi pulada.', array( 'page' => $page, 'url' => $url, 'error' => $error ) );
			} else {
				throw $exception;
			}
		}

		// A carga pode ter sido cancelada enquanto a requisição HTTP estava aberta.
		$current = $this->catalog_discovery_status();
		if ( empty( $current['active'] ) || ! hash_equals( (string) $current['id'], $scan_id ) ) {
			throw new MBI_Skip_Exception( 'Carga de catálogo cancelada enquanto a página da origem era lida.' );
		}
		// Mantém os dados calculados nesta rodada (inclusive contador de falhas e
		// páginas puladas); o estado atual é relido acima apenas para detectar cancelamento.
		$state['active'] = true;
		$state['id'] = $scan_id;
		$state['consecutive_source_failures'] = isset( $state['consecutive_source_failures'] ) ? absint( $state['consecutive_source_failures'] ) : 0;
		if ( isset( $data['manga_urls'] ) && is_array( $data['manga_urls'] ) ) {
			$items = is_array( $state['items'] ?? null ) ? $state['items'] : array();
			$seen  = is_array( $state['seen'] ?? null ) ? $state['seen'] : array();
			$existing_keys = $this->catalog_existing_keys51( $data['manga_urls'] );
			$known_rejections = (array) get_option( 'mbi_rejected_candidates52', array() );
			foreach ( $data['manga_urls'] as $manga_url ) {
				$manga_url = esc_url_raw( (string) $manga_url );
				if ( ! $manga_url ) {
					continue;
				}
				$key = $this->source_key( $manga_url );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				if ( (int) ( $known_rejections[$key]['until'] ?? 0 ) > time() ) { $state['cached_rejections'] = (int) ( $state['cached_rejections'] ?? 0 ) + 1; continue; }
				if ( ! isset( $existing_keys[$key] ) ) {
					$items[] = array( 'url' => $manga_url );
				}
			}
			$state['items'] = $items;
			$state['seen'] = $seen;
		}

		if ( 1 === $page && ! empty( $data['max_page'] ) ) {
			$state['page_limit'] = min( $page_limit, max( 1, (int) $data['max_page'] ) );
			$page_limit = (int) $state['page_limit'];
		}
		$state['last_page_scanned'] = $page;
		$state['updated_at'] = current_time( 'mysql' );
		// Também aplica ao lote já aberto antes da atualização, sem descartar candidatos.
		$candidate_limit = 'catalog_full' === $mode ? 0 : $limit;
		$state['candidate_limit'] = $candidate_limit;
		$items_count = count( (array) ( $state['items'] ?? array() ) );
		$consecutive_failures = absint( $state['consecutive_source_failures'] ?? 0 );
		$done = $page >= $page_limit || ( $candidate_limit > 0 && $items_count >= $candidate_limit ) || $consecutive_failures >= 2;

		if ( ! $done ) {
			$state['page'] = $page + 1;
			update_option( 'mbi_catalog_discovery', $state, false );
			$this->queue_catalog_discovery_page( $state );
			return;
		}

		$items = array_values( (array) ( $state['items'] ?? array() ) );
		if ( ! $items ) {
			$state['active'] = false;
			$state['finished_at'] = current_time( 'mysql' );
			update_option( 'mbi_catalog_discovery', $state, false );
			$this->finish_empty_catalog_refill( $state );
			throw new MBI_Skip_Exception( 'Nenhuma obra nova foi encontrada dentro da janela de catálogo lida.' );
		}

		$target = 'catalog_full' === $mode ? count( $items ) : $limit;
		// Reconfere depois da leitura: uma importação manual pode ter sido iniciada
		// enquanto esta página estava em rede. Nesse caso, só adia a criação do lote.
		if ( $this->is_serial_active() ) {
			$state['ready_to_start'] = 1;
			$state['page'] = $page;
			update_option( 'mbi_catalog_discovery', $state, false );
			throw new MBI_Source_Backoff_Exception( 'CATALOG_YIELD: lote mapeado e aguardando a obra explícita terminar.', $this->source_host( $url ), 30 );
		}

		update_option( 'mbi_catalog_discovery', $state, false );
		$serial = $this->start_serial_batch( $items, $mode, $target );
		$state['active'] = false;
		$state['finished_at'] = current_time( 'mysql' );
		$state['serial_batch'] = (string) ( $serial['id'] ?? '' );
		$state['target'] = $serial['target'] ?? $target;
		update_option( 'mbi_catalog_discovery', $state, false );
		$this->db->log( 'info', 'Descoberta incremental concluída; lote serial criado.', array( 'mode' => $mode, 'target' => $target, 'candidates' => count( $items ), 'last_page' => $page, 'skipped_pages' => (array) ( $state['skipped_pages'] ?? array() ) ) );
	}

	private function import_catalog( array $payload ): void {
		$this->assert_current_generation( $payload );
		$url  = $this->canonical_source_url( $this->required_url( $payload ) );
		$this->runtime( 'catalog', 'Lendo catálogo: ' . $url, array( 'url' => $url ) );
		$html = $this->fetch_html( $url );
		$this->assert_current_generation( $payload );
		$this->parser->load( $html, $url );
		$data = $this->parser->parse_catalog();

		if ( ! $data['manga_urls'] ) {
			throw new RuntimeException( 'Nenhuma obra foi encontrada na página de catálogo. O HTML da fonte pode ter mudado.' );
		}

		$settings = $this->settings();
		if ( ! empty( $payload['full'] ) && ! empty( $payload['first_page'] ) ) {
			$max_page = min( (int) $settings['max_catalog_pages'], max( 1, (int) $data['max_page'] ) );
			$base     = trailingslashit( $settings['source_url'] );
			for ( $page = 2; $page <= $max_page; $page++ ) {
				$this->db->queue(
					'catalog',
					array(
						'url'        => $base . 'manga/page/' . $page . '/',
						'generation' => (string) ( $payload['generation'] ?? $this->current_generation() ),
						'full'       => true,
						'sync'       => false,
						'first_page' => false,
					),
					true
				);
			}
		}

		foreach ( $data['manga_urls'] as $manga_url ) {
			$this->db->queue(
				'manga',
				array(
					'url'  => $manga_url,
					'generation' => (string) ( $payload['generation'] ?? $this->current_generation() ),
					'sync' => ! empty( $payload['sync'] ),
					'full' => ! empty( $payload['full'] ),
				),
				! empty( $payload['sync'] ) || ! empty( $payload['full'] )
			);
		}

		$this->db->log(
			'info',
			'Catálogo processado.',
			array( 'url' => $url, 'mangas' => count( $data['manga_urls'] ), 'max_page' => $data['max_page'] )
		);
	}

	/**
	 * v1.6.17: encontra uma imagem de fallback já pertencente à própria obra.
	 * Quando a página do mangá oferece somente placeholder, usamos a primeira
	 * página válida do capítulo mais antigo. Nada é baixado para o WordPress.
	 */
	private function cover_fallback_from_existing_chapters( int $manga_id ): array {
		$chapter_ids = get_posts( array(
			'post_type'      => 'mbi_chapter',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'post_parent'    => $manga_id,
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'menu_order ID',
			'order'          => 'ASC',
		) );

		$settings = $this->settings();
		foreach ( $chapter_ids as $chapter_id ) {
			$chapter_id = (int) $chapter_id;
			$candidates = get_post_meta( $chapter_id, '_mbi_source_image_urls', true );
			if ( ! is_array( $candidates ) || ! $candidates ) {
				$candidates = get_post_meta( $chapter_id, '_mbi_chapter_page_urls_original', true );
			}

			if ( ! is_array( $candidates ) || ! $candidates ) {
				$protected = get_post_meta( $chapter_id, '_mbi_chapter_page_urls', true );
				$protected = is_array( $protected ) ? $protected : array();
				$candidates = array();
				foreach ( $protected as $protected_url ) {
					$protected_url = esc_url_raw( (string) $protected_url );
					if ( ! $protected_url ) {
						continue;
					}
					$source = MBI_Image_Proxy::is_proxy_url( $protected_url, $settings )
						? MBI_Image_Proxy::unprotect( $protected_url, $settings )
						: $protected_url;
					if ( $source ) {
						$candidates[] = $source;
					}
				}
			}

			foreach ( (array) $candidates as $candidate ) {
				$candidate = esc_url_raw( (string) $candidate );
				if ( ! $candidate || ! wp_http_validate_url( $candidate ) ) {
					continue;
				}
				if ( MBI_Parser::looks_like_placeholder_url( $candidate ) || MBI_Parser::looks_like_non_content_asset_url( $candidate ) ) {
					continue;
				}
				return array( 'url' => $candidate, 'chapter_id' => $chapter_id );
			}
		}

		return array();
	}

	private function import_cover_repair( array $payload ): void {
		$this->assert_current_generation( $payload );
		$manga_id = absint( $payload['manga_id'] ?? 0 );
		$url      = $this->required_url( $payload );
		if ( ! $manga_id || 'mbi_manga' !== get_post_type( $manga_id ) ) {
			throw new MBI_Skip_Exception( 'Reparo de capa ignorado: obra local não existe mais.' );
		}
		$this->runtime( 'cover-repair', 'Revalidando capa: ' . get_the_title( $manga_id ), array( 'manga_id' => $manga_id, 'url' => $url ) );
		$html = $this->fetch_html( $url );
		$this->assert_current_generation( $payload );
		$this->parser->load( $html, $url );
		$data = $this->parser->parse_manga( $url );
		$cover = esc_url_raw( (string) ( $data['cover_url'] ?? '' ) );
		$method = 'source';
		$fallback_chapter_id = 0;

		if ( ! $cover || ! wp_http_validate_url( $cover ) || MBI_Parser::looks_like_placeholder_url( $cover ) || MBI_Parser::looks_like_non_content_asset_url( $cover ) ) {
			$fallback = $this->cover_fallback_from_existing_chapters( $manga_id );
			$cover = esc_url_raw( (string) ( $fallback['url'] ?? '' ) );
			$fallback_chapter_id = absint( $fallback['chapter_id'] ?? 0 );
			$method = 'chapter_fallback';
		}

		if ( ! $cover || ! wp_http_validate_url( $cover ) || MBI_Parser::looks_like_placeholder_url( $cover ) || MBI_Parser::looks_like_non_content_asset_url( $cover ) ) {
			update_post_meta( $manga_id, '_mbi_cover_repair_status', 'unresolved_no_valid_cover' );
			update_post_meta( $manga_id, '_mbi_cover_repair_method', 'none' );
			update_post_meta( $manga_id, '_mbi_cover_repair_checked_at', current_time( 'mysql' ) );
			delete_transient( 'mbi_invalid_cover_count' );
			// Não tratar como "concluído": o painel precisa mostrar que a capa continua sem solução.
			throw new RuntimeException( 'A origem não oferece capa real e nenhum capítulo local possui imagem válida para fallback.' );
		}

		$delivery = $this->image_delivery_url( $cover );
		if ( ! $delivery || ! wp_http_validate_url( $delivery ) ) {
			throw new RuntimeException( 'Não foi possível gerar a URL protegida da nova capa.' );
		}
		MBI_Media_Repair::backup( $manga_id );
		update_post_meta( $manga_id, '_mbi_cover_original_url', $cover );
		update_post_meta( $manga_id, '_mbi_cover_source_url', $delivery );
		update_post_meta( $manga_id, '_mbi_cover_repair_status', 'repaired' );
		update_post_meta( $manga_id, '_mbi_cover_repair_method', $method );
		if ( $fallback_chapter_id ) {
			update_post_meta( $manga_id, '_mbi_cover_fallback_chapter_id', $fallback_chapter_id );
		} else {
			delete_post_meta( $manga_id, '_mbi_cover_fallback_chapter_id' );
		}
		update_post_meta( $manga_id, '_mbi_cover_repair_checked_at', current_time( 'mysql' ) );
		delete_transient( 'mbi_invalid_cover_count' );
		$this->update_generated_rankmath_meta( $manga_id, 'rank_math_facebook_image', $delivery );
		$this->update_generated_rankmath_meta( $manga_id, 'rank_math_twitter_image', $delivery );
		if ( has_post_thumbnail( $manga_id ) ) {
			delete_post_thumbnail( $manga_id );
		}
		$this->db->log(
			'info',
			'source' === $method ? 'Capa inválida reparada a partir da capa real da origem.' : 'Capa ausente na origem reparada usando a primeira página válida de um capítulo como fallback.',
			array( 'post_id' => $manga_id, 'source_host' => wp_parse_url( $cover, PHP_URL_HOST ), 'method' => $method, 'chapter_id' => $fallback_chapter_id )
		);
	}

	/** Stable inventory identity excludes expiring request tokens, not chapter URLs/counts. */
	private function inventory_fingerprint( array $initial, array $loader ): string {
		unset( $loader['nonce'] );
		return md5( wp_json_encode( array( $initial, $loader ) ) );
	}

	/** Optional descriptive status is not a publication requirement; code is never accepted. */
	private function normalize_manga_fields( array $data ): array {
		$status = trim( (string) ( $data['status'] ?? '' ) );
		if ( $status && ! preg_match( '/^(Em Lançamento|Em Andamento|Completo|Finalizado|Conclu[ií]do|Pausado)$/iu', $status ) && ! $this->suspicious_text( $status, 80 ) ) {
			$data['status'] = '';
			$data['_metadata_notes50'] = array( 'Status descritivo não reconhecido omitido: ' . $status );
		}
		return $data;
	}

	/** Validate the actual cover before collecting additional chapter pages. */
	private function preflight_manga_cover( string $url, array $data ): array {
		$generation = $this->current_generation();
		$check_current = function() use ( $generation ): void {
			if ( $this->is_paused() || $generation !== $this->current_generation() ) { throw new MBI_Resource_Wait( 'Validação de capa interrompida; conteúdo preservado.', 5 ); }
		};
		$input = (string) $data['cover_url'];
		$key = 'mbi_cover_receipt50_' . md5( $this->current_generation() . '|' . $url . '|' . $input );
		$receipt = get_transient( $key );
		if ( is_array( $receipt ) && ( $receipt['at'] ?? 0 ) > time() - 5 * MINUTE_IN_SECONDS
			&& ( ( $receipt['url'] ?? '' ) === $input || in_array( $receipt['url'] ?? '', (array) ( $data['_cover_candidates50'] ?? array() ), true ) ) ) {
			$data['cover_url'] = $receipt['url']; return $data;
		}
		if ( MBI_Resource_Guard::remaining() < 10 || MBI_Resource_Guard::requests_remaining() < 2 ) {
			throw new MBI_Resource_Wait( 'Rodada limitada para liberar recursos; capa será conferida antes da lista completa.', 3 );
		}
		$cover_probe = MBI_Global_Diagnostic::probe( (string) $data['cover_url'], 4 );
		$check_current();
		// A stale lazy-load attribute does not prove the work is broken. Try one
		// alternate from the same actual cover element, never a recommendation.
		if ( ! $cover_probe['ok'] && in_array( (int) $cover_probe['http'], array( 200, 206, 404, 410 ), true ) && empty( $cover_probe['header_inconclusive'] ) ) {
			$candidates = array_values( array_diff( (array) ( $data['_cover_candidates50'] ?? array() ), array( (string) $data['cover_url'] ) ) );
			if ( $candidates ) {
				$alternate_probe = MBI_Global_Diagnostic::probe( (string) $candidates[0], 4 );
				$check_current();
				if ( $alternate_probe['ok'] || ! empty( $alternate_probe['header_inconclusive'] ) ) { $data['cover_url'] = $candidates[0]; $cover_probe = $alternate_probe; }
			}
		}
		if ( ! $cover_probe['ok'] ) {
			$error = 'Imagem da origem inválida: ' . $cover_probe['message'];
			if ( ! empty( $cover_probe['resolver_failure'] ) || 0 === (int) $cover_probe['http'] || ! in_array( (int) $cover_probe['http'], array( 200, 206, 404, 410 ), true ) ) {
				throw new RuntimeException( $error );
			}
			if ( ! empty( $cover_probe['header_inconclusive'] ) ) {
				$this->db->log( 'warning', 'Cabeçalho da capa ultrapassa a amostra de 4 KB; validação de bytes inconclusiva, HTTP e MIME de imagem válidos.', array( 'url' => $data['cover_url'] ) );
			} else {
			set_transient( 'mbi_broken_work_' . md5( $this->canonical_source_url( $url ) ), $error, DAY_IN_SECONDS );
			throw new MBI_Skip_Exception( $error );
			}
		}
		set_transient( $key, array( 'url' => $data['cover_url'], 'at' => time() ), 5 * MINUTE_IN_SECONDS );
		if ( $input !== $data['cover_url'] ) { set_transient( 'mbi_cover_receipt50_' . md5( $this->current_generation() . '|' . $url . '|' . $data['cover_url'] ), array( 'url' => $data['cover_url'], 'at' => time() ), 5 * MINUTE_IN_SECONDS ); }
		return $data;
	}

	private function read_inventory_root( string $url ): array {
		$this->parser->load( $this->fetch_html( $url ), $url );
		$data = $this->normalize_manga_fields( $this->parser->parse_manga( $url, true ) );
		$data['_cover_candidates50'] = $this->parser->cover_image_candidates( false );
		return array( $data, $this->parser->import_chapter_inventory( $url ), $this->parser->slimeread_loader() );
	}

	/** Checkpoint bounded pagination before creating any manga or chapter. */
	private function complete_import_inventory( string $url, bool $check_cover = true, bool $collect_only = false ): array {
		$generation = $this->current_generation();
		$cache_key = 'mbi_inventory43_' . md5( $generation . '|' . $url );
		$restart_key = 'mbi_inventory46_restart_' . md5( $generation . '|' . $url );
		$saved = get_transient( $cache_key ); $fresh_root = false;
		if ( is_array( $saved ) && ( $saved['version'] ?? 0 ) === 46 && ( $saved['origin'] ?? '' ) === $url && ( $saved['generation'] ?? '' ) === $generation && ( $saved['updated_at'] ?? $saved['read_at'] ?? 0 ) > time() - 15 * MINUTE_IN_SECONDS ) {
			$state = $saved;
			$state['data'] = $this->normalize_manga_fields( $state['data'] );
		} else {
			list( $data, $initial, $loader ) = $this->read_inventory_root( $url );
			$fresh_root = true;
			if ( $this->is_paused() || $generation !== $this->current_generation() ) { throw new MBI_Resource_Wait( 'Leitura interrompida; conteúdo preservado.', 5 ); }
			// Return invalid metadata to the normal review branch before loading more chapters.
			if ( ! $collect_only && $this->validate_manga_data( $data, false ) ) { delete_transient( $cache_key ); delete_transient( $restart_key ); return $data; }
			$state = array( 'version' => 46, 'origin' => $url, 'generation' => $generation, 'read_at' => time(), 'updated_at' => time(), 'data' => $data,
				'fingerprint' => $this->inventory_fingerprint( $initial, $loader ), 'expected' => (int) $initial['reported_count'],
				'urls' => array_fill_keys( $initial['chapter_urls'], true ), 'pending' => $initial['pagination_urls'], 'visited' => array( $url => true ), 'loader' => $loader );
			if ( $state['expected'] < 1 ) { throw new RuntimeException( 'Origem sem contagem explícita confiável; publicação completa bloqueada.' ); }
			if ( $loader && ( $loader['total'] !== $state['expected'] || $loader['offset'] !== count( $initial['chapter_urls'] ) ) ) { throw new RuntimeException( 'Contagem do carregador diverge da lista inicial; publicação bloqueada.' ); }
			set_transient( $cache_key, $state, 15 * MINUTE_IN_SECONDS );
		}
		if ( $this->is_paused() || $generation !== $this->current_generation() ) { throw new MBI_Resource_Wait( 'Leitura interrompida; conteúdo preservado.', 5 ); }

		// Also stop old checkpoints with invalid metadata, without resetting the order.
		if ( ! $collect_only && ! $fresh_root && $this->validate_manga_data( $state['data'], false ) ) { delete_transient( $cache_key ); delete_transient( $restart_key ); return $state['data']; }

		if ( $check_cover && isset( $state['data']['_cover_candidates50'] ) ) {
			try { $state['data'] = $this->preflight_manga_cover( $url, $state['data'] ); }
			catch ( MBI_Skip_Exception $e ) { delete_transient( $cache_key ); throw $e; }
		}

		$round = 0;
		while ( count( $state['urls'] ) < $state['expected'] && ( $state['pending'] || ( $state['loader'] && $state['loader']['offset'] < $state['expected'] ) ) ) {
			if ( $round >= 2 || MBI_Resource_Guard::remaining() < 8 || MBI_Resource_Guard::requests_remaining() < 1 ) {
				set_transient( $cache_key, $state, 15 * MINUTE_IN_SECONDS );
				throw new MBI_Resource_Wait( 'Reunindo lista paginada de capítulos; progresso preservado.', 3 );
			}

			if ( $this->is_paused() || $generation !== $this->current_generation() ) { throw new MBI_Resource_Wait( 'Leitura interrompida; conteúdo preservado.', 5 ); }
			if ( $state['loader'] && $state['loader']['offset'] < $state['expected'] ) {
				$old_offset = $state['loader']['offset'];
				$endpoint = add_query_arg( array( 'offset' => $old_offset, 'search' => '' ), $state['loader']['endpoint'] );
				$json = json_decode( $this->fetch_html( $endpoint, 15, true, $state['loader']['nonce'] ), true );
				if ( $this->is_paused() || $generation !== $this->current_generation() ) { throw new MBI_Resource_Wait( 'Leitura interrompida; conteúdo preservado.', 5 ); }
				if ( ! is_array( $json ) || ! isset( $json['offset'], $json['total'], $json['list'] ) || ! is_string( $json['list'] ) || ! preg_match( '/^[0-9]+$/', (string) $json['offset'] ) || ! preg_match( '/^[0-9]+$/', (string) $json['total'] ) ) { throw new RuntimeException( 'Resposta REST de capítulos inválida ou incompleta.' ); }
				$next_offset = (int) $json['offset'];
				if ( (int) $json['total'] !== $state['expected'] || $next_offset <= $old_offset || $next_offset > $state['expected'] ) { delete_transient( $cache_key ); throw new RuntimeException( 'REST de capítulos sem avanço ou com contagem alterada; publicação bloqueada.' ); }
				$this->parser->load( '<ul class="chapters-list">' . $json['list'] . '</ul>', $url );
				$part = $this->parser->update_chapter_snapshot( $url );
				$merged = $state['urls']; foreach ( $part['chapter_urls'] as $chapter_url ) { $merged[$chapter_url] = true; }
				if ( count( $merged ) !== $next_offset || count( $merged ) - count( $state['urls'] ) !== $next_offset - $old_offset ) { throw new RuntimeException( 'REST devolveu capítulos repetidos, ausentes ou incompatíveis com o offset; publicação bloqueada.' ); }
				$state['urls'] = $merged; $state['loader']['offset'] = $next_offset; $state['updated_at'] = time();
				set_transient( $cache_key, $state, 15 * MINUTE_IN_SECONDS ); $round++;
				$this->runtime( 'chapter-inventory', sprintf( 'Lista da origem: %d de %d capítulos reunidos.', $next_offset, $state['expected'] ) );
				continue;
			}
			$page = $state['pending'][0];
			if ( isset( $state['visited'][$page] ) ) { array_shift( $state['pending'] ); continue; }
			if ( count( $state['visited'] ) >= 100 ) { throw new RuntimeException( 'Lista de capítulos excedeu 100 páginas explícitas; revisão necessária.' ); }
			$html = $this->fetch_html( $page );
			if ( $this->is_paused() ) { throw new MBI_Resource_Wait( 'Leitura de capítulos pausada; conteúdo preservado.', 5 ); }
			$this->parser->load( $html, $page );
			$part = $this->parser->import_chapter_inventory( $url );
			if ( $part['reported_count'] > 0 && (int) $part['reported_count'] !== $state['expected'] ) { delete_transient( $cache_key ); throw new RuntimeException( 'Contagem da origem mudou entre páginas; nova leitura necessária.' ); }
			array_shift( $state['pending'] ); $state['visited'][$page] = true;
			foreach ( $part['chapter_urls'] as $chapter_url ) { $state['urls'][$chapter_url] = true; }
			foreach ( $part['pagination_urls'] as $next ) { if ( ! isset( $state['visited'][$next] ) && ! in_array( $next, $state['pending'], true ) ) { $state['pending'][] = $next; } }
			$state['updated_at'] = time();
			set_transient( $cache_key, $state, 15 * MINUTE_IN_SECONDS ); $round++;
		}
		if ( count( $state['urls'] ) !== $state['expected'] ) {
			throw new RuntimeException( sprintf( 'Lista de capítulos incompleta: %d encontrados de %d informados; nenhuma página explícita restante. Obra preservada.', count( $state['urls'] ), $state['expected'] ) );
		}
		// A resumed inventory cannot publish stale root metadata or a changed chapter list.
		if ( ! $fresh_root ) {
			if ( MBI_Resource_Guard::remaining() < 8 || MBI_Resource_Guard::requests_remaining() < 1 ) { set_transient( $cache_key, $state, 15 * MINUTE_IN_SECONDS ); throw new MBI_Resource_Wait( 'Conferindo lista final da origem na próxima rodada; progresso preservado.', 3 ); }
			list( $data, $initial, $loader ) = $this->read_inventory_root( $url );
			if ( $this->is_paused() || $generation !== $this->current_generation() ) { throw new MBI_Resource_Wait( 'Leitura interrompida; conteúdo preservado.', 5 ); }
			if ( $this->inventory_fingerprint( $initial, $loader ) !== $state['fingerprint'] ) {
				delete_transient( $cache_key );
				$restarts = (int) get_transient( $restart_key ) + 1; set_transient( $restart_key, $restarts, 15 * MINUTE_IN_SECONDS );
				if ( $restarts > 2 ) { throw new MBI_Source_Compatibility_Exception( 'Lista da origem mudou repetidamente durante a coleta; pedido preservado para revisão.' ); }
				throw new MBI_Resource_Wait( 'Lista da origem mudou; nova coleta limitada antes de publicar.', 3 );
			}
		} else { $data = $state['data']; }
		if ( ! $collect_only && $this->validate_manga_data( $data, false ) ) { delete_transient( $cache_key ); delete_transient( $restart_key ); return $data; }
		if ( $check_cover ) { $data = $this->preflight_manga_cover( $url, $data ); }
		$data['chapter_urls'] = array_keys( $state['urls'] );
		$data['source_hash'] = md5( wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		delete_transient( $cache_key ); delete_transient( $restart_key );
		return $data;
	}

	private function import_manga( array $payload ): void {
		$this->assert_current_generation( $payload );
		$staged = 'validate' === ( $payload['pipeline_phase'] ?? '' );
		if ( $staged ) { $this->pipeline_state51( $payload ); }
		$url  = $this->required_url( $payload );
		$quarantined = get_transient( 'mbi_broken_work_' . md5( $this->canonical_source_url( $url ) ) );
		if ( ! $staged && $quarantined ) { throw new MBI_Skip_Exception( 'Obra ignorada por defeito confirmado na origem nas últimas 24h: ' . $quarantined ); }
		$invalid_key = 'mbi_invalid_metadata50_' . md5( $this->canonical_source_url( $url ) );
		$invalid = ! empty( $payload['serial_batch'] ) ? get_transient( $invalid_key ) : false;
		if ( ! $staged && $invalid ) { throw new MBI_Skip_Exception( 'Dados inválidos já identificados na última hora: ' . implode( '; ', (array) $invalid ) ); }
		$existing_post_id = $this->find_by_source_url( 'mbi_manga', $url );
		if ( ! $existing_post_id && ! empty( $payload['manga_id_hint'] ) && 'mbi_manga' === get_post_type( absint( $payload['manga_id_hint'] ) ) ) {
			$existing_post_id = absint( $payload['manga_id_hint'] );
		}
		if ( ! empty( $payload['catalog_import'] ) && $existing_post_id ) { throw new MBI_Skip_Exception( 'Obra já existente ignorada pela carga de catálogo: ' . $url ); }
		if ( ! empty( $payload['update_existing'] ) && ! $existing_post_id ) { throw new MBI_Skip_Exception( 'Atualização ignorada porque a obra ainda não existe no catálogo local: ' . $url ); }
		$this->runtime( 'manga', 'Lendo dados da obra: ' . $url, array( 'url' => $url ) );
		$data = $staged ? $this->staged_inventory51( $payload ) : $this->complete_import_inventory( $url, ! $existing_post_id || 'publish' !== get_post_status( $existing_post_id ) );
		$this->assert_current_generation( $payload );

		$validation_issues = $this->validate_manga_data( $data );
		if ( $validation_issues ) {
			set_transient( $invalid_key, $validation_issues, HOUR_IN_SECONDS );
			if ( $existing_post_id && 'publish' === get_post_status( $existing_post_id ) ) {
				update_post_meta( $existing_post_id, '_mbi_validation_status', 'review' );
				update_post_meta( $existing_post_id, '_mbi_validation_errors', $validation_issues );
				update_post_meta( $existing_post_id, '_mbi_validation_checked_at', current_time( 'mysql' ) );
				$this->db->log( 'warning', 'Dados suspeitos da origem ignorados; obra e capítulos publicados preservados.', array( 'url' => $url, 'post_id' => $existing_post_id, 'issues' => $validation_issues ) );
				throw new MBI_Skip_Exception( 'Obra publicada preservada; dados da origem exigem revisão: ' . implode( '; ', $validation_issues ) );
			}
			// Invalid new source data is logged and skipped without creating another
			// incomplete catalogue entry. Existing drafts remain available for review.
			$review_id = $existing_post_id ? $this->save_validation_review_draft( $url, $data, $validation_issues, $existing_post_id ) : 0;
			$this->db->log( 'warning', 'Obra bloqueada pela validação anti-corrupção.', array( 'url' => $url, 'post_id' => $review_id, 'issues' => $validation_issues ) );
			throw new MBI_Skip_Exception( 'Obra enviada para revisão: ' . implode( '; ', $validation_issues ) );
		}

		delete_transient( $invalid_key );
		if ( ! empty( $data['_metadata_notes50'] ) ) { $this->db->log( 'info', 'Campo opcional omitido sem bloquear a obra.', array( 'url' => $url, 'notes' => $data['_metadata_notes50'] ) ); }
		if ( ! $data['title'] ) {
			throw new MBI_Skip_Exception( 'Não foi possível identificar o título da obra: ' . $url );
		}
		if ( ! trim( wp_strip_all_tags( (string) $data['synopsis'] ) ) ) {
			throw new MBI_Skip_Exception( 'A obra não possui sinopse válida na fonte: ' . $url );
		}
		if ( empty( $data['cover_url'] ) ) {
			throw new MBI_Skip_Exception( 'A obra não possui capa válida na fonte: ' . $url );
		}
		$chapter_urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $data['chapter_urls'] ) ) ) );
		// Never merge stale historical chapter URLs into the current root list.
		if ( ! $chapter_urls ) {
			throw new MBI_Skip_Exception( 'A obra não possui capítulos reconhecidos na fonte: ' . $url );
		}

		$original_title  = $data['title'];
		$formatted_title = $this->format_manga_title( $original_title );
		$reservation_token = $this->acquire_manga_reservation( $url );
		$post_id         = $existing_post_id;
		$is_update = ! empty( $payload['update_existing'] );
		if ( $is_update && ! $post_id ) {
			$this->release_manga_reservation( $url, $reservation_token );
			throw new MBI_Skip_Exception( 'Atualização ignorada porque a obra ainda não existe no catálogo local: ' . $url );
		}
		if ( ! empty( $payload['catalog_import'] ) && $post_id ) {
			$this->release_manga_reservation( $url, $reservation_token );
			throw new MBI_Skip_Exception( 'Obra já existente ignorada pela carga de catálogo: ' . $url );
		}
		$current_status = $post_id ? sanitize_key( (string) get_post_status( $post_id ) ) : '';
		$target_status  = ! empty( $payload['serial_batch'] ) ? 'publish' : $this->publish_status();
		// Uma obra pública não deve sair do ar por uma nova importação ou reparo.
		if ( 'publish' === $current_status || ( $is_update && in_array( $current_status, array( 'draft', 'private' ), true ) ) ) {
			$target_status = $current_status;
		}
		$staging_status = ( $post_id && 'publish' === $current_status ) || ( $is_update && $post_id ) ? $target_status : ( 'publish' === $target_status ? 'draft' : $target_status );
		$postarr = array(
			'post_type'    => 'mbi_manga',
			'post_status'  => $staging_status,
			'post_title'   => $formatted_title,
			'post_name'    => sanitize_title( $formatted_title ),
			'post_content' => $data['synopsis'],
			'post_excerpt' => wp_trim_words( wp_strip_all_tags( $data['synopsis'] ), 36 ),
		);
		if ( $post_id ) {
			$postarr['ID'] = $post_id;
			$result = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $result ) ) {
			$this->release_manga_reservation( $url, $reservation_token );
			throw new RuntimeException( $result->get_error_message() );
		}
		$post_id = (int) $result;

		update_post_meta( $post_id, '_mbi_source_url', esc_url_raw( $url ) );
		update_post_meta( $post_id, '_mbi_source_key', $this->source_key( $url ) );
		$this->release_manga_reservation( $url, $reservation_token );
		update_post_meta( $post_id, '_mbi_original_title', $original_title );
		update_post_meta( $post_id, '_mbi_source_hash', $data['source_hash'] );
		update_post_meta( $post_id, '_mbi_last_sync', current_time( 'mysql' ) );
		update_post_meta( $post_id, '_mbi_year', $data['year'] );
		update_post_meta( $post_id, '_mbi_author', $data['author'] );
		update_post_meta( $post_id, '_mbi_artist', $data['artist'] );
		update_post_meta( $post_id, '_mbi_rating', $data['rating'] );
		update_post_meta( $post_id, '_mbi_views', $data['views'] );
		update_post_meta( $post_id, '_mbi_readers', $data['readers'] );
		update_post_meta( $post_id, '_mbi_target_status', $target_status );
		if ( $is_update ) {
			update_post_meta( $post_id, '_mbi_update_in_progress', 1 );
		} else {
			update_post_meta( $post_id, '_mbi_ready', 0 );
			delete_post_meta( $post_id, '_mbi_update_in_progress' );
		}
		update_post_meta( $post_id, '_mbi_expected_chapters', count( $chapter_urls ) );
		update_post_meta( $post_id, '_mbi_source_chapter_urls', $chapter_urls );
		update_post_meta( $post_id, '_mbi_validation_status', 'passed' );
		update_post_meta( $post_id, '_mbi_validation_errors', array() );
		update_post_meta( $post_id, '_mbi_validation_checked_at', current_time( 'mysql' ) );
		delete_post_meta( $post_id, '_mbi_validation_blocked_batch' );

		if ( $data['genres'] ) {
			wp_set_object_terms( $post_id, $data['genres'], 'mbi_genre', false );
		}
		if ( $data['types'] ) {
			wp_set_object_terms( $post_id, $data['types'], 'mbi_type', false );
		}
		if ( $data['status'] ) {
			wp_set_object_terms( $post_id, array( $data['status'] ), 'mbi_status', false );
		}

		$this->ensure_taxonomy_editorial_descriptions( $data );
		$this->apply_rankmath_manga_metadata( $post_id, $original_title, $data );
		$batch_id = sanitize_text_field( (string) ( $payload['serial_batch'] ?? '' ) );
		if ( $batch_id ) {
			$this->bind_serial_manga( $batch_id, $url, $post_id );
		}
		$this->assert_current_generation( $payload );

		// v1.5.5: mantém a origem internamente e entrega pelo Proxy/CDN quando ativado.
		$cover_original = esc_url_raw( (string) $data['cover_url'] );
		if ( ! wp_http_validate_url( $cover_original ) ) {
			throw new MBI_Skip_Exception( 'A capa da obra não possui URL HTTP(S) válida: ' . $url );
		}
		$cover_url = $this->image_delivery_url( $cover_original );
		if ( ! $cover_url || ( $this->image_proxy_ready() && ! MBI_Image_Proxy::is_proxy_url( $cover_url, $this->settings() ) ) ) {
			throw new MBI_Skip_Exception( 'Proxy/CDN ativo não conseguiu proteger a capa. Obra preservada em rascunho para não expor URL original.' );
		}
		$old_cover = (string) get_post_meta( $post_id, '_mbi_cover_original_url', true );
		if ( $old_cover && 'publish' === get_post_status( $post_id ) && $old_cover !== $cover_original ) {
			$check = MBI_Media_Repair::probe( $cover_original );
			$this->assert_current_generation( $payload );
			if ( ! $check['ok'] ) {
				$this->db->log( 'warning', 'Nova capa recusada; links anteriores preservados.', array( 'post_id' => $post_id, 'probe' => $check ) );
				$cover_original = $old_cover;
				$cover_url = $this->image_delivery_url( $old_cover );
			}
		}
		if ( ! $cover_url || ( $this->image_proxy_ready() && ! MBI_Image_Proxy::is_proxy_url( $cover_url, $this->settings() ) ) ) {
			throw new MBI_Skip_Exception( 'Proxy/CDN não gerou endereço para a capa. Dados anteriores mantidos.' );
		}
		MBI_Media_Repair::backup( $post_id );
		update_post_meta( $post_id, '_mbi_cover_original_url', $cover_original );
		update_post_meta( $post_id, '_mbi_cover_source_url', $cover_url );
		// A confirmação antiga de 404 não pode bloquear para sempre uma obra que
		// acaba de passar por HTTP 200, extração, metadados, capítulos e capa.
		$this->clear_revalidated_origin_block( $post_id, $url );
		// Remove apenas a associação com thumbnail local; o arquivo legado não é apagado automaticamente.
		if ( has_post_thumbnail( $post_id ) ) {
			delete_post_thumbnail( $post_id );
		}

		// Uma busca em lotes substitui mais de mil consultas individuais em obras longas.
		$chapter_index = $this->chapter_index_for_urls( $chapter_urls, $post_id );
		$chapter_jobs  = array();
		foreach ( $chapter_urls as $chapter_url ) {
			$chapter_id   = (int) ( $chapter_index[ $this->source_key( $chapter_url ) ] ?? 0 );
			$same_parent  = $chapter_id && wp_get_post_parent_id( $chapter_id ) === $post_id;
			$complete     = $chapter_id && $same_parent && $this->chapter_is_complete_cached( $chapter_id );
			// No reparo rápido, capítulos que já passam pela validação estrutural completa são reutilizados.
			// Isso evita baixar novamente centenas/milhares de páginas que já estão corretas.
			if ( $complete ) {
				continue;
			}

			if ( $chapter_id ) {
				// Não invalidar páginas de um capítulo público antes da substituição estar pronta.
				if ( 'publish' !== get_post_status( $chapter_id ) ) {
					delete_post_meta( $chapter_id, '_mbi_chapter_complete' );
					delete_post_meta( $chapter_id, '_mbi_chapter_page_ids' );
					delete_post_meta( $chapter_id, '_mbi_chapter_page_map' );
				}
				if ( ! $same_parent ) {
					// Associa o capítulo encontrado sem rebaixar uma publicação existente.
					wp_update_post( array( 'ID' => $chapter_id, 'post_parent' => $post_id ) );
				}
			}

			$chapter_jobs[] = array(
				'url'          => $chapter_url,
				'generation'   => (string) ( $payload['generation'] ?? $this->current_generation() ),
				'manga_id'     => $post_id,
				'manga_title'  => $original_title,
				'repair'       => true,
				'chapter_number_hint' => $this->chapter_number_from_url( $chapter_url ),
				'serial_batch' => $batch_id,
				'dedupe'       => $chapter_url . '|manga|' . $post_id . ( $batch_id ? '|batch|' . $batch_id : '' ),
			);
		}

		// v1.6.21: milhares de capítulos entram na fila em UPSERTs de 120 linhas,
		// em vez de SELECT+INSERT individual por capítulo. Esse era um gargalo grande
		// antes mesmo de começar o download dos HTMLs de uma obra longa.
		$queued_chapters = $chapter_jobs ? $this->db->queue_many( 'chapter', $chapter_jobs, true ) : 0;
		if ( $chapter_jobs && $queued_chapters < count( $chapter_jobs ) ) {
			$this->db->log( 'warning', 'Parte da fila de capítulos precisou do modo compatível durante o enfileiramento em massa.', array( 'manga_id' => $post_id, 'expected' => count( $chapter_jobs ), 'accepted' => $queued_chapters ) );
		}

		// Se não houve capítulo para processar, a obra já pode ser validada. Caso
		// contrário, o último job concluído fará uma única validação integral.
		if ( 0 === $queued_chapters ) {
			$this->maybe_publish_ready_manga( $post_id );
		}
		$this->db->log( 'info', 'Obra preparada integralmente: ' . $original_title, array( 'post_id' => $post_id, 'chapters_expected' => count( $chapter_urls ), 'serial_batch' => $batch_id ) );
	}


	/** Somente import_manga chama após validar novamente os dados reais da origem. */
	private function clear_revalidated_origin_block( int $post_id, string $url ): void {
		$backup = get_post_meta( $post_id, '_mbi_origin_withdrawn', true );
		if ( ! $backup || 'passed' !== get_post_meta( $post_id, '_mbi_validation_status', true ) ) { return; }
		if ( ! MBI_Image_Proxy::valid_browser_url( (string) get_post_meta( $post_id, '_mbi_cover_source_url', true ) ) ) { return; }
		if ( $this->canonical_source_url( (string) get_post_meta( $post_id, '_mbi_source_url', true ) ) !== $this->canonical_source_url( $url ) ) { return; }
		if ( is_array( $backup ) && ! empty( $backup['source'] ) && $this->canonical_source_url( (string) $backup['source'] ) !== $this->canonical_source_url( $url ) ) { return; }
		update_post_meta( $post_id, '_mbi_origin_revalidated', array( 'previous' => $backup, 'source' => $url, 'at' => time() ) );
		delete_post_meta( $post_id, '_mbi_origin_withdrawn' );
		delete_post_meta( $post_id, '_mbi_finalization_block' );
		clean_post_cache( $post_id );
		$this->db->log( 'warning', 'Origem validada novamente; bloqueio antigo de indisponibilidade removido. Publicação ainda depende de todos os capítulos completos.', array( 'manga_id' => $post_id, 'url' => $url ) );
	}

	/** Valida os dados extraídos antes que eles possam alcançar o front-end. */
	private function validate_manga_data( array $data, bool $check_chapters = true ): array {
		$issues = array();
		$title = trim( wp_strip_all_tags( (string) ( $data['title'] ?? '' ) ) );
		$synopsis = trim( wp_strip_all_tags( (string) ( $data['synopsis'] ?? '' ) ) );
		$author = trim( wp_strip_all_tags( (string) ( $data['author'] ?? '' ) ) );
		$artist = trim( wp_strip_all_tags( (string) ( $data['artist'] ?? '' ) ) );
		$year = trim( (string) ( $data['year'] ?? '' ) );
		$status = trim( (string) ( $data['status'] ?? '' ) );
		$cover = esc_url_raw( (string) ( $data['cover_url'] ?? '' ) );
		$chapter_urls = $check_chapters ? array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) ( $data['chapter_urls'] ?? array() ) ) ) ) ) : array();

		if ( ! $title || $this->suspicious_text( $title, 260 ) ) {
			$issues[] = 'título inválido ou contaminado por código';
		}
		$synopsis_len = function_exists( 'mb_strlen' ) ? mb_strlen( $synopsis ) : strlen( $synopsis );
		if ( $synopsis_len < 30 ) {
			$issues[] = 'sinopse curta/incompleta';
		} elseif ( $this->suspicious_text( $synopsis, 12000 ) ) {
			$issues[] = 'sinopse contaminada por HTML/CSS/JS';
		}
		if ( ! $cover || ! MBI_Image_Proxy::valid_browser_url( $cover ) ) {
			$issues[] = 'capa sem URL HTTP(S) válida';
		}
		if ( $check_chapters && ! $chapter_urls ) {
			$issues[] = 'nenhum capítulo reconhecido';
		}
		if ( $author && $this->suspicious_text( $author, 180 ) ) {
			$issues[] = 'autor contaminado ou fora do padrão';
		}
		if ( $artist && $this->suspicious_text( $artist, 180 ) ) {
			$issues[] = 'artista contaminado ou fora do padrão';
		}
		if ( $year && ( ! preg_match( '/^\d{4}$/', $year ) || (int) $year < 1900 || (int) $year > (int) gmdate( 'Y' ) + 2 ) ) {
			$issues[] = 'ano inválido';
		}
		if ( $status && ! preg_match( '/^(Em Lançamento|Em Andamento|Completo|Finalizado|Conclu[ií]do|Pausado)$/iu', $status ) ) {
			$issues[] = 'status fora do padrão';
		}
		foreach ( array_merge( (array) ( $data['genres'] ?? array() ), (array) ( $data['types'] ?? array() ) ) as $term ) {
			if ( $this->suspicious_text( (string) $term, 80 ) ) {
				$issues[] = 'categoria/gênero contaminado por código';
				break;
			}
		}
		return array_values( array_unique( $issues ) );
	}

	private function suspicious_text( string $value, int $max_length ): bool {
		$value = html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		if ( ! $value || $length > $max_length ) {
			return true;
		}
		if ( preg_match( '/(?:<\s*(?:script|style|iframe)|\bfunction\s*\(|@media\b|javascript:|document\.|window\.|(?:background|display|position|width|height|transform|overflow|border|box-shadow|font-size|grid-template|z-index)\s*:|[.#][a-z0-9_-]+\s*\{|\{\s*[a-z-]+\s*:)/iu', $value ) ) {
			return true;
		}
		return substr_count( $value, '{' ) + substr_count( $value, '}' ) >= 2;
	}

	/** Salva uma obra suspeita em rascunho, sem deixar campos contaminados no front-end. */
	private function save_validation_review_draft( string $url, array $data, array $issues, int $post_id = 0 ): int {
		// Nunca transformar uma obra já publicada em rascunho por erro de extração.
		if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
			update_post_meta( $post_id, '_mbi_validation_status', 'review' );
			update_post_meta( $post_id, '_mbi_validation_errors', array_values( $issues ) );
			update_post_meta( $post_id, '_mbi_validation_checked_at', current_time( 'mysql' ) );
			return $post_id;
		}
		$title = trim( wp_strip_all_tags( (string) ( $data['title'] ?? '' ) ) );
		if ( $this->suspicious_text( $title, 260 ) ) {
			$title = 'Obra para revisão ' . substr( $this->source_key( $url ), 0, 8 );
		}
		$formatted = $this->format_manga_title( $title );
		$synopsis = trim( wp_strip_all_tags( (string) ( $data['synopsis'] ?? '' ) ) );
		$post_content = ( $synopsis && ! $this->suspicious_text( $synopsis, 12000 ) ) ? wpautop( esc_html( $synopsis ) ) : '';
		$postarr = array(
			'post_type' => 'mbi_manga',
			'post_status' => 'draft',
			'post_title' => $formatted,
			'post_name' => sanitize_title( $formatted ),
			'post_content' => $post_content,
			'post_excerpt' => $post_content ? wp_trim_words( $synopsis, 36 ) : '',
		);
		if ( $post_id ) {
			$postarr['ID'] = $post_id;
			$result = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $result ) ) {
			return 0;
		}
		$post_id = (int) $result;
		update_post_meta( $post_id, '_mbi_source_url', esc_url_raw( $url ) );
		update_post_meta( $post_id, '_mbi_source_key', $this->source_key( $url ) );
		update_post_meta( $post_id, '_mbi_original_title', $title );
		update_post_meta( $post_id, '_mbi_ready', 0 );
		update_post_meta( $post_id, '_mbi_target_status', 'publish' );
		update_post_meta( $post_id, '_mbi_validation_status', 'review' );
		update_post_meta( $post_id, '_mbi_validation_errors', array_values( $issues ) );
		update_post_meta( $post_id, '_mbi_validation_checked_at', current_time( 'mysql' ) );
		$cover = MBI_Image_Proxy::normalize_source_url( (string) ( $data['cover_url'] ?? '' ) );
		if ( $cover && wp_http_validate_url( $cover ) ) {
			$delivery = $this->image_delivery_url( $cover );
			if ( $delivery && ( ! $this->image_proxy_ready() || MBI_Image_Proxy::is_proxy_url( $delivery, $this->settings() ) ) ) {
				update_post_meta( $post_id, '_mbi_cover_original_url', $cover );
				update_post_meta( $post_id, '_mbi_cover_source_url', $delivery );
			}
		}
		foreach ( array( '_mbi_author' => 'author', '_mbi_artist' => 'artist' ) as $meta => $field ) {
			$value = trim( wp_strip_all_tags( (string) ( $data[ $field ] ?? '' ) ) );
			if ( $value && ! $this->suspicious_text( $value, 180 ) ) {
				update_post_meta( $post_id, $meta, $value );
			} else {
				delete_post_meta( $post_id, $meta );
			}
		}
		return $post_id;
	}

	private function validate_existing_manga( int $manga_id ): array {
		$data = array(
			'title' => (string) get_post_meta( $manga_id, '_mbi_original_title', true ),
			'synopsis' => (string) get_post_field( 'post_content', $manga_id ),
			'cover_url' => (string) get_post_meta( $manga_id, '_mbi_cover_source_url', true ),
			'author' => (string) get_post_meta( $manga_id, '_mbi_author', true ),
			'artist' => (string) get_post_meta( $manga_id, '_mbi_artist', true ),
			'year' => (string) get_post_meta( $manga_id, '_mbi_year', true ),
			'status' => '',
			'genres' => wp_get_object_terms( $manga_id, 'mbi_genre', array( 'fields' => 'names' ) ),
			'types' => wp_get_object_terms( $manga_id, 'mbi_type', array( 'fields' => 'names' ) ),
			'chapter_urls' => (array) get_post_meta( $manga_id, '_mbi_source_chapter_urls', true ),
		);
		if ( ! $data['title'] ) {
			$data['title'] = preg_replace( '/^Ler\s+|\s+Online\s+Gr[aá]tis$/iu', '', (string) get_the_title( $manga_id ) );
		}
		$status_terms = wp_get_object_terms( $manga_id, 'mbi_status', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $status_terms ) && $status_terms ) {
			$data['status'] = (string) reset( $status_terms );
		}
		if ( is_wp_error( $data['genres'] ) ) $data['genres'] = array();
		if ( is_wp_error( $data['types'] ) ) $data['types'] = array();
		return $this->validate_manga_data( $data );
	}

	public function validation_review_count(): int {
		$query = new WP_Query( array( 'post_type' => 'mbi_manga', 'post_status' => array( 'draft', 'pending', 'private' ), 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_mbi_validation_status', 'meta_value' => 'review', 'no_found_rows' => false ) );
		return (int) $query->found_posts;
	}

	/** Localiza obras suspeitas e agenda revisão sem alterar publicações existentes. */
	public function revalidate_suspicious_mangas( int $limit = 500 ): array {
		$limit = max( 1, min( 2000, $limit ) );
		$ids = get_posts( array( 'post_type' => 'mbi_manga', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => $limit, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true ) );
		$stats = array( 'scanned' => 0, 'flagged' => 0, 'queued' => 0 );
		foreach ( $ids as $manga_id ) {
			$stats['scanned']++;
			$issues = $this->validate_existing_manga( (int) $manga_id );
			$status = (string) get_post_meta( (int) $manga_id, '_mbi_validation_status', true );
			if ( ! $issues && 'review' !== $status ) {
				continue;
			}
			if ( $issues ) {
				$stats['flagged']++;
				update_post_meta( (int) $manga_id, '_mbi_validation_status', 'review' );
				update_post_meta( (int) $manga_id, '_mbi_validation_errors', $issues );
				update_post_meta( (int) $manga_id, '_mbi_validation_checked_at', current_time( 'mysql' ) );
				if ( 'publish' !== get_post_status( $manga_id ) ) {
					update_post_meta( (int) $manga_id, '_mbi_ready', 0 );
				}
			}
			$url = esc_url_raw( (string) get_post_meta( (int) $manga_id, '_mbi_source_url', true ) );
			if ( $url ) {
				$this->db->queue( 'manga', array( 'url' => $url, 'generation' => $this->current_generation(), 'manga_id_hint' => (int) $manga_id, 'repair' => true, 'full' => true, 'dedupe' => 'revalidate|' . $this->source_key( $url ) ), true );
				$stats['queued']++;
			}
		}
		if ( $stats['queued'] && ! $this->is_paused() ) {
			$this->kick_worker();
		}
		return $stats;
	}

	/**
	 * Preenche os campos do Rank Math sem apagar uma otimização feita manualmente.
	 * Os metadados podem ser gravados antes mesmo do Rank Math ser ativado.
	 */
	private function apply_rankmath_manga_metadata( int $post_id, string $original_title, array $data = array() ): void {
		$title       = $this->format_manga_title( $original_title );
		$focus       = 'Ler ' . $original_title . ' Online Grátis';
		$description = wp_trim_words(
			sprintf(
				'Ler %s online grátis em português. Confira sinopse, gêneros, status e capítulos atualizados de %s.',
				$original_title,
				$original_title
			),
			30,
			''
		);

		$this->update_generated_rankmath_meta( $post_id, 'rank_math_focus_keyword', $focus );
		$this->update_generated_rankmath_meta( $post_id, 'rank_math_title', $title . ' %sep% Ler Mangá Online' );
		$this->update_generated_rankmath_meta( $post_id, 'rank_math_description', $description );
		$this->update_generated_rankmath_meta( $post_id, 'rank_math_facebook_title', $title );
		$this->update_generated_rankmath_meta( $post_id, 'rank_math_facebook_description', $description );
		$this->update_generated_rankmath_meta( $post_id, 'rank_math_twitter_title', $title );
		$this->update_generated_rankmath_meta( $post_id, 'rank_math_twitter_description', $description );
		$remote_cover = $this->image_delivery_url( esc_url_raw( (string) ( $data['cover_url'] ?? '' ) ) );
		if ( $remote_cover && wp_http_validate_url( $remote_cover ) ) {
			$this->update_generated_rankmath_meta( $post_id, 'rank_math_facebook_image', $remote_cover );
			$this->update_generated_rankmath_meta( $post_id, 'rank_math_twitter_image', $remote_cover );
		}

		$this->ensure_base_categories();
		$category_name = $this->rankmath_category_name( (array) ( $data['types'] ?? array() ) );
		$category_slug = 'Mangás' === $category_name ? 'mangas' : ( 'Manhwas' === $category_name ? 'manhwas' : 'manhuas' );
		$term          = get_term_by( 'slug', $category_slug, 'category' );
		if ( $term && ! is_wp_error( $term ) ) {
			$term_id = (int) $term->term_id;
			wp_set_object_terms( $post_id, array( $term_id ), 'category', false );
			$this->update_generated_rankmath_meta( $post_id, 'rank_math_primary_category', (string) $term_id );
		}

		$genres = wp_get_post_terms( $post_id, 'mbi_genre', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $genres ) && $genres ) {
			$this->update_generated_rankmath_meta( $post_id, 'rank_math_primary_mbi_genre', (string) $genres[0] );
		}

		$this->apply_generated_tags( $post_id, $original_title, (array) ( $data['types'] ?? array() ), $genres );
	}

	/** Mantém tags manuais e substitui somente as tags criadas pelo importador. */
	private function apply_generated_tags( int $post_id, string $original_title, array $types, $genres ): void {
		$tag_names = array_filter(
			array_unique(
				array_merge(
					array( $original_title, 'Ler Mangá Online', 'Mangá Online Grátis' ),
					array_map( 'sanitize_text_field', $types ),
					is_array( $genres ) && ! is_wp_error( $genres ) ? wp_get_post_terms( $post_id, 'mbi_genre', array( 'fields' => 'names' ) ) : array()
				)
			)
		);
		$old_generated_ids = array_map( 'absint', (array) get_post_meta( $post_id, '_mbi_generated_tag_ids', true ) );
		$current_ids       = wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );
		$current_ids       = is_wp_error( $current_ids ) ? array() : array_map( 'absint', $current_ids );
		$manual_ids        = array_values( array_diff( $current_ids, $old_generated_ids ) );
		$generated_ids     = wp_set_object_terms( $post_id, $tag_names, 'post_tag', false );
		if ( ! is_wp_error( $generated_ids ) ) {
			$all_ids = array_values( array_unique( array_merge( $manual_ids, array_map( 'absint', $generated_ids ) ) ) );
			wp_set_object_terms( $post_id, $all_ids, 'post_tag', false );
			update_post_meta( $post_id, '_mbi_generated_tag_ids', array_map( 'absint', $generated_ids ) );
		}
	}

	/** Cria apenas a introdução inicial de hubs de taxonomia; textos editados manualmente nunca são trocados. */
	private function ensure_taxonomy_editorial_descriptions( array $data ): void {
		$this->ensure_term_description( 'mbi_genre', (array) ( $data['genres'] ?? array() ), 'Explore mangás, manhwas e manhuas de %s para ler online em português. Veja obras atualizadas, informações e capítulos disponíveis.' );
		$this->ensure_term_description( 'mbi_type', (array) ( $data['types'] ?? array() ), 'Encontre %s para ler online em português. Descubra obras atualizadas, gêneros e capítulos organizados em uma única biblioteca.' );
		$this->ensure_term_description( 'mbi_status', array_filter( array( (string) ( $data['status'] ?? '' ) ) ), 'Confira mangás %s para ler online, com informações da obra e capítulos disponíveis.' );
	}

	private function ensure_term_description( string $taxonomy, array $names, string $template ): void {
		foreach ( $names as $name ) {
			$term = get_term_by( 'name', (string) $name, $taxonomy );
			if ( ! $term || trim( (string) $term->description ) ) {
				continue;
			}
			wp_update_term( (int) $term->term_id, $taxonomy, array( 'description' => sprintf( $template, $term->name ) ) );
		}
	}

	private function rankmath_category_name( array $types ): string {
		$types = array_map(
			static fn( $type ): string => function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $type ) : strtolower( (string) $type ),
			$types
		);
		if ( in_array( 'manhwa', $types, true ) ) {
			return 'Manhwas';
		}
		if ( in_array( 'manhua', $types, true ) ) {
			return 'Manhuas';
		}
		return 'Mangás';
	}

	private function update_generated_rankmath_meta( int $post_id, string $key, string $value ): void {
		$generated_key = '_mbi_generated_' . $key;
		$current       = (string) get_post_meta( $post_id, $key, true );
		$previous      = (string) get_post_meta( $post_id, $generated_key, true );
		if ( '' === $current || $current === $previous ) {
			update_post_meta( $post_id, $key, $value );
			update_post_meta( $post_id, $generated_key, $value );
		}
	}

	/** Atualiza 100 obras existentes por execução após instalar a versão 1.2.0. */
	public function backfill_rankmath_metadata(): void {
		$page  = max( 1, absint( get_option( 'mbi_rankmath_backfill_page', 1 ) ) );
		$query = new WP_Query(
			array(
				'post_type'      => 'mbi_manga',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		foreach ( $query->posts as $post_id ) {
			$original = (string) get_post_meta( $post_id, '_mbi_original_title', true );
			$original = $original ?: preg_replace( '/^Ler\s+|\s+Online\s+Gr[aá]tis$/iu', '', get_the_title( $post_id ) );
			$types    = wp_get_post_terms( $post_id, 'mbi_type', array( 'fields' => 'names' ) );
			$genres   = wp_get_post_terms( $post_id, 'mbi_genre', array( 'fields' => 'names' ) );
			$status   = wp_get_post_terms( $post_id, 'mbi_status', array( 'fields' => 'names' ) );
			$data     = array(
				'types'  => is_wp_error( $types ) ? array() : $types,
				'genres' => is_wp_error( $genres ) ? array() : $genres,
				'status' => is_wp_error( $status ) || ! $status ? '' : $status[0],
			);
			$this->ensure_taxonomy_editorial_descriptions( $data );
			$this->apply_rankmath_manga_metadata( (int) $post_id, trim( $original ), $data );
		}
		if ( $query->max_num_pages > $page ) {
			update_option( 'mbi_rankmath_backfill_page', $page + 1, false );
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'mbi_rankmath_backfill' );
		} else {
			delete_option( 'mbi_rankmath_backfill_page' );
			$this->db->log( 'info', 'Metadados Rank Math atualizados para o catálogo existente.' );
		}
		wp_reset_postdata();
	}

	private function import_chapter( array $payload ): void {
		$this->assert_current_generation( $payload );
		$this->touch_manual_update( $payload );
		$url      = $this->required_url( $payload );
		$request_url = $url;
		$manga_id = isset( $payload['manga_id'] ) ? absint( $payload['manga_id'] ) : 0;
		if ( ! $manga_id || 'mbi_manga' !== get_post_type( $manga_id ) ) {
			throw new RuntimeException( 'Mangá pai inválido para o capítulo: ' . $url );
		}

		// Resume the same chapter job: no extra queue rows and no repeated HTML reads.
		$candidate_id = absint( $payload['chapter_id'] ?? 0 ) ?: $this->find_by_source_url( 'mbi_chapter', $url );
		$candidate = $candidate_id ? (array) get_post_meta( $candidate_id, '_mbi_delivery_candidate', true ) : array();
		if ( $candidate && ! empty( $candidate['browser_error'] ) && ( $candidate['key'] ?? '' ) === $this->delivery_candidate_key( $payload, $url ) ) { throw new MBI_Skip_Exception( $candidate['browser_error'] ); }
		if ( $candidate && empty( $candidate['failed'] ) && ( $candidate['key'] ?? '' ) === $this->delivery_candidate_key( $payload, $url ) && ( $candidate['created'] ?? 0 ) > time() - DAY_IN_SECONDS ) {
			$this->finish_delivery_candidate( $candidate_id, $candidate, $payload ); return;
		}

		$this->runtime( 'chapter', 'Lendo capítulo: ' . $url, array( 'url' => $url, 'manga_id' => $manga_id ) );
		try {
			$html = $this->fetch_html( $url );
		} catch ( MBI_Resource_Wait $exception ) { throw $exception;
		} catch ( Throwable $exception ) {
			if ( $exception instanceof MBI_Source_Backoff_Exception || $this->origin_pressure_error( $exception->getMessage() ) ) { throw $exception; }
			$recovery = $this->recover_repair_chapter_reference( $payload, $url, $exception->getMessage() );
			if ( 'unavailable' === (string) ( $recovery['action'] ?? '' ) ) {
				return;
			}
			if ( 'remapped' !== (string) ( $recovery['action'] ?? '' ) ) {
				throw $exception;
			}
			$payload = (array) $recovery['payload'];
			$payload['_delivery_request_url'] = $request_url;
			$url     = esc_url_raw( (string) $recovery['url'] );
			$this->runtime( 'chapter-remapped', 'URL do capítulo atualizada automaticamente: ' . $url, array( 'url' => $url, 'manga_id' => $manga_id ) );
			$html = $this->fetch_html( $url );
		}
		$this->assert_current_generation( $payload );
		$this->parser->load( $html, $url );
		$data = $this->parser->parse_chapter( $url, false, true );
		if ( ! $data['image_urls'] ) {
			throw new RuntimeException( 'Nenhuma página de imagem foi encontrada no capítulo: ' . $url );
		}

		$settings       = $this->settings();
		// Se uma URL que estava em cooldown voltou a entregar páginas, remova o bloqueio.
		$unavailable = get_post_meta( $manga_id, '_mbi_unavailable_chapter_urls', true );
		if ( is_array( $unavailable ) ) {
			$key = $this->source_key( $url );
			if ( isset( $unavailable[ $key ] ) ) {
				unset( $unavailable[ $key ] );
				update_post_meta( $manga_id, '_mbi_unavailable_chapter_urls', $unavailable );
			}
		}

		if ( $this->parser->has_numbered_reader() && $this->parser->complete_reader_pages() !== (array) $data['image_urls'] ) { throw new RuntimeException( 'Lista numerada incompleta na origem; publicação bloqueada.' ); }
		$image_urls     = array_values( array_filter( (array) $data['image_urls'] ) ); // Preserve numbered reader positions, including repetitions in the origin.
		$maximum_pages = max( 1, (int) $settings['max_images_chapter'] );
		// Long remote readers store URLs only. Bypass the download limit only with exact numbered proof.
		$complete_remote = 'remote' === ( $settings['page_storage_mode'] ?? 'remote' ) && $this->parser->complete_reader_pages() === $image_urls;
		if ( count( $image_urls ) > 5000 || ( count( $image_urls ) > $maximum_pages && ! $complete_remote ) ) {
			throw new RuntimeException( sprintf( 'O capítulo possui %d páginas, acima do limite de segurança configurado (%d). A obra continuará em rascunho até o limite ser aumentado.', count( $image_urls ), $maximum_pages ) );
		}
		$manga_title = (string) get_post_meta( $manga_id, '_mbi_original_title', true );
		if ( ! $manga_title && ! empty( $payload['manga_title'] ) ) {
			$manga_title = (string) $payload['manga_title'];
		}
		if ( ! $manga_title ) {
			$manga_title = trim( (string) preg_replace( '/^Ler\s+|\s+Online\s+Gr[aá]tis$/iu', '', (string) get_the_title( $manga_id ) ) );
		}
		$manga_title = $manga_title ?: 'Mangá';
		$number = MBI_Parser::recovery_number( (string) $data['number'] );
		if ( $number === '' ) {
			// The source's chapter list is a second piece of evidence when an
			// otherwise valid reader has no number in og:title or its heading.
			// Never trust a number guessed from a third-party payload alone.
			$hint = MBI_Parser::recovery_number( (string) ( $payload['chapter_number_hint'] ?? '' ) );
			$path_hint = MBI_Parser::recovery_number( $this->chapter_number_from_url( $url ) );
			$known = (array) get_post_meta( $manga_id, '_mbi_source_chapter_urls', true );
			$known_keys = array_map( fn( $u ) => $this->source_key( (string) $u ), $known );
			if ( in_array( $this->source_key( $url ), $known_keys, true ) ) {
				if ( $hint !== '' && $path_hint !== '' && $hint !== $path_hint ) {
					throw new RuntimeException( 'Número de capítulo divergente entre URL e lista da origem; publicação bloqueada.' );
				}
				$number = $path_hint !== '' ? $path_hint : $hint;
			}
		}
		if ( $number === '' ) { throw new RuntimeException( 'Número do capítulo não foi comprovado por título, URL nem lista da obra; capítulo preservado em rascunho.' ); }
		$title       = sprintf( 'Ler %s Capítulo %s Online Grátis', $manga_title, $number );
		$chapter_id = absint( $payload['chapter_id'] ?? 0 ) ?: $this->find_by_source_url( 'mbi_chapter', $url );
		if ( $chapter_id && ( get_post_type( $chapter_id ) !== 'mbi_chapter' || (int) wp_get_post_parent_id( $chapter_id ) !== $manga_id ) ) { throw new RuntimeException( 'Capítulo pertence a outra obra; gravação bloqueada.' ); }
		if ( $chapter_id && get_post_meta( $chapter_id, '_mbi_chapter_number', true ) !== '' && MBI_Parser::recovery_number( (string) get_post_meta( $chapter_id, '_mbi_chapter_number', true ) ) !== MBI_Parser::recovery_number( $number ) ) { throw new RuntimeException( 'Número do capítulo difere do registro existente; gravação bloqueada.' ); }
		$chapter_target = ( ! empty( $payload['serial_batch'] ) || ! empty( $payload['manual_update'] ) ) ? 'publish' : $this->publish_status();
		$already_published = $chapter_id && 'publish' === get_post_status( $chapter_id );
		if ( $already_published ) {
			$chapter_target = 'publish';
			$before = max( count( (array) get_post_meta( $chapter_id, '_mbi_source_image_urls', true ) ), (int) get_post_meta( $chapter_id, '_mbi_expected_pages', true ) );
			if ( count( $image_urls ) < $before ) { throw new RuntimeException( 'Reimportação incompleta: as páginas do capítulo publicado foram preservadas.' ); }
		}

		$postarr     = array(
			'post_type'   => 'mbi_chapter',
			// Reprocessar um capítulo público nunca deve deixá-lo em rascunho
			// enquanto as URLs das imagens são recalculadas.
			'post_status' => $already_published ? 'publish' : ( 'publish' === $chapter_target ? 'draft' : $chapter_target ),
			'post_title'  => $title,
			'post_name'   => sanitize_title( $manga_title . '-capitulo-' . $number ),
			'post_parent' => $manga_id,
			'menu_order'  => (int) floor( (float) $number * 100 ),
		);
		// Published chapter metadata is not touched until the complete proposed list passes.
		if ( $chapter_id ) { unset( $postarr['post_name'] ); } // Existing public URLs remain stable.
		if ( ! $chapter_id ) {
			$result = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
			$chapter_id = (int) $result;
			update_post_meta( $chapter_id, '_mbi_source_url', $url );
			update_post_meta( $chapter_id, '_mbi_source_key', $this->source_key( $url ) );
		}
		if ( ! $already_published ) {
			update_post_meta( $chapter_id, '_mbi_delivery_required', 1 );
			delete_post_meta( $chapter_id, '_mbi_chapter_complete' );
		}
		$candidate = array( 'key' => $this->delivery_candidate_key( $payload, $url ), 'created' => time(), 'cursor' => 0, 'generation' => $payload['generation'] ?? '',
			'base' => MBI_Chapter_Integrity::snapshot( $chapter_id ), 'urls' => $image_urls, 'delivery' => $this->image_delivery_urls( $image_urls ),
			'url' => $url, 'number' => $number, 'target' => $chapter_target, 'manga_id' => $manga_id, 'postarr' => $postarr );
		$this->assert_current_generation( $payload );
		update_post_meta( $chapter_id, '_mbi_delivery_candidate', wp_slash( $candidate ) );
		$this->finish_delivery_candidate( $chapter_id, $candidate, $payload );
	}

	private function delivery_candidate_key( array $payload, string $url ): string {
		return hash( 'sha256', serialize( array( $payload['_delivery_request_url'] ?? $url, $payload['generation'] ?? '', $payload['serial_batch'] ?? '', $payload['manual_update_batch'] ?? '' ) ) );
	}

	/** Refresh stale proposed data, never bless a changed snapshot as already verified. */
	private function guard_delivery_candidate( int $id, array $candidate ): void {
		wp_cache_delete( $id, 'post_meta' ); wp_cache_delete( $id, 'posts' ); wp_cache_delete( 'mbi_settings', 'options' );
		if ( get_post_type( $id ) !== 'mbi_chapter' || in_array( get_post_status( $id ), array( 'trash', 'auto-draft' ), true )
			|| (int) wp_get_post_parent_id( $id ) !== (int) $candidate['manga_id'] || get_post_meta( $id, '_mbi_origin_withdrawn', true ) || get_post_meta( $id, '_mbi_root_absent', true ) ) {
			throw new RuntimeException( 'Capítulo removido, movido ou retirado da origem; publicação bloqueada.' );
		}
		if ( MBI_Chapter_Integrity::snapshot( $id, false ) === $candidate['base'] ) { return; }
		$retry = (array) get_post_meta( $id, '_mbi_candidate_refresh', true );
		$count = ( $retry['key'] ?? '' ) === ( $candidate['key'] ?? '' ) ? (int) ( $retry['count'] ?? 0 ) : 0;
		if ( $count > 0 && ( $retry['base'] ?? '' ) === $candidate['base'] ) { throw new MBI_Resource_Wait( 'Candidato já descartado; aguardando nova leitura.', 3 ); }
		if ( $count >= 2 ) { throw new RuntimeException( 'Capítulo alterado repetidamente durante a validação; revisão necessária. Dados publicados preservados.' ); }
		update_post_meta( $id, '_mbi_candidate_refresh', array( 'key' => $candidate['key'], 'base' => $candidate['base'], 'count' => $count + 1 ) );
		delete_post_meta( $id, '_mbi_delivery_candidate' );
		$this->db->log( 'warning', 'Candidato desatualizado descartado; capítulo será relido e validado novamente sem alterar conteúdo publicado.', array( 'post_id' => $id, 'refresh' => $count + 1 ) );
		throw new MBI_Resource_Wait( 'Capítulo mudou: nova leitura limitada para refazer a validação com dados atuais.', 3 );
	}

	private function validation_control_current53( array $payload ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			$this->assert_current_generation( $payload ); return ! $this->is_paused();
		}
		$rows = $wpdb->get_results( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN ('mbi_queue_paused','mbi_process_generation','mbi_generation_enforced')" );
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) { throw new MBI_Resource_Wait( 'Controle da fila indisponível; publicação adiada.', 3 ); }
		$control = array();
		foreach ( $rows as $row ) { $control[$row->option_name] = maybe_unserialize( $row->option_value ); }
		if ( ! empty( $control['mbi_generation_enforced'] ) ) {
			$expected = (string) ( $control['mbi_process_generation'] ?? '' );
			$received = (string) ( $payload['generation'] ?? '' );
			if ( ! $expected || ! $received || ! hash_equals( $expected, $received ) ) { throw new MBI_Skip_Exception( 'Job invalidado por um reinício controlado do processador.' ); }
		}
		return empty( $control['mbi_queue_paused'] );
	}

	private function finish_delivery_candidate( int $id, array $candidate, array $payload ): void {
		if ( ! isset( $candidate['generation'] ) ) { $candidate['generation'] = $payload['generation'] ?? ''; }
		$checked = false;
		$allowed = function( bool $fresh = true ) use ( $id, $candidate, $payload, &$checked ): bool {
			if ( ! $this->validation_control_current53( $payload ) ) { return false; }
			if ( $fresh || ! $checked ) { $this->guard_delivery_candidate( $id, $candidate ); $checked = true; }
			return true;
		};
		$checkpoint = function( array $state ) use ( $id, $allowed ): void {
			if ( ! $allowed() ) { throw new MBI_Resource_Wait( 'Validação pausada.', 5 ); }
			update_post_meta( $id, '_mbi_delivery_candidate', wp_slash( $state ) );
			update_option( 'mbi_image_validation_progress', array( 'post_id' => $id, 'parent' => (int) $state['manga_id'], 'cursor' => (int) $state['cursor'], 'pages' => count( $state['urls'] ), 'generation' => (string) get_option( 'mbi_process_generation', '' ), 'at' => time() ), false );
			$this->runtime( 'validating-chapter-images', 'Conferindo páginas ' . $state['cursor'] . '/' . count( $state['urls'] ) . ' de ' . get_the_title( $id ), array( 'post_id' => $id, 'page' => $state['cursor'], 'pages' => count( $state['urls'] ) ) );
		};
		// v1.7.36: the server validates the image bytes at their ORIGINAL URLs.
		// A Proxy Guard deliberately rejects server-side GETs to its PUBLIC Worker URL.
		// Therefore verify the Worker token locally, then probe every origin image in
		// bounded, checkpointed rounds; never require an administrator browser tab.
		$settings = $this->settings();
		$proxy_enabled = ! empty( $settings['image_proxy_enabled'] );
		if ( $proxy_enabled && ! MBI_Chapter_Integrity::signed_proxy_links_valid( (array) $candidate['urls'], (array) $candidate['delivery'] ) ) {
			throw new RuntimeException( 'VALIDACAO_IMAGENS: token Proxy/CDN ausente, incorreto ou incompatível com a configuração. Capítulo preservado em rascunho.' );
		}
		if ( ! empty( $candidate['browser_error'] ) ) {
			throw new RuntimeException( 'VALIDACAO_IMAGENS: falha pública previamente detectada: ' . $candidate['browser_error'] . ' Capítulo preservado para revisão.' );
		}
		$verification_mode = $proxy_enabled ? 'server_origin_signed_proxy_v1' : 'server_origin_direct_v1';
		if ( ( $candidate['verification_mode'] ?? '' ) !== $verification_mode ) {
			// Do not inherit a browser/legacy cursor as proof that source bytes were read.
			$candidate['cursor'] = 0;
			$candidate['verification_mode'] = $verification_mode;
			unset( $candidate['awaiting_browser'], $candidate['browser_progress'], $candidate['browser_approval'], $candidate['error'], $candidate['failed'] );
			$checkpoint( $candidate );
		}
		if ( ! MBI_Chapter_Integrity::check( $id, $candidate, $allowed, $checkpoint, true ) ) {
			throw new MBI_Resource_Wait( 'Validando páginas ' . $candidate['cursor'] . '/' . count( $candidate['urls'] ) . ' de ' . get_the_title( $id ) . '. O capítulo ainda não foi liberado.', 2 );
		}
		if ( ! $allowed() ) { throw new MBI_Resource_Wait( 'Publicação pausada.', 5 ); }
		$values = array( '_mbi_source_url' => $candidate['url'], '_mbi_source_key' => $this->source_key( $candidate['url'] ),
			'_mbi_chapter_number' => $candidate['number'], '_mbi_expected_pages' => count( $candidate['urls'] ), '_mbi_target_status' => $candidate['target'],
			'_mbi_source_image_urls' => $candidate['urls'], '_mbi_chapter_page_urls_original' => $candidate['urls'], '_mbi_chapter_page_urls' => $candidate['delivery'] );
		$before = array(); foreach ( $values as $key => $_ ) { $before[$key] = get_post_meta( $id, $key, true ); }
		MBI_Media_Repair::backup( $id );
		foreach ( $values as $key => $value ) { update_post_meta( $id, $key, wp_slash( $value ) ); }
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) ? get_post_meta( $id, $key, true ) !== $value : (string) get_post_meta( $id, $key, true ) !== (string) $value ) {
				foreach ( $before as $old => $value ) { update_post_meta( $id, $old, wp_slash( $value ) ); }
				throw new RuntimeException( 'Gravação das páginas não confirmada; lista anterior restaurada.' );
			}
		}
		delete_post_meta( $id, '_mbi_chapter_page_map' ); delete_post_meta( $id, '_mbi_chapter_page_ids' );
		update_post_meta( $id, '_mbi_last_sync', current_time( 'mysql' ) );
		if ( (string) $before['_mbi_source_url'] !== (string) $candidate['url'] ) {
			$known = (array) get_post_meta( (int) $candidate['manga_id'], '_mbi_source_chapter_urls', true );
			$changed = false; foreach ( $known as $i => $known_url ) { if ( $known_url === $before['_mbi_source_url'] ) { $known[$i] = $candidate['url']; $changed = true; } }
			if ( $changed ) { $known = array_values( array_unique( $known ) ); update_post_meta( (int) $candidate['manga_id'], '_mbi_source_chapter_urls', $known ); update_post_meta( (int) $candidate['manga_id'], '_mbi_expected_chapters', count( $known ) ); }
		}
		MBI_Chapter_Integrity::verified( $id, $proxy_enabled ? 'server_origin_signed_proxy' : 'server_image_addresses' );
		if ( ! MBI_Chapter_Integrity::valid( $id ) ) { throw new RuntimeException( 'Validação das páginas não confirmada no banco; publicação bloqueada.' ); }
		$postarr = $candidate['postarr']; $postarr['ID'] = $id;
		// Preserve a public chapter; new serial chapters wait for the work's final gate.
		$postarr['post_status'] = get_post_status( $id ) === 'publish' || ! empty( $payload['manual_update'] ) ? 'publish' : ( $candidate['target'] === 'publish' ? 'draft' : $candidate['target'] );
		$updated = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $updated ) ) { throw new RuntimeException( $updated->get_error_message() ); }
		delete_post_meta( $id, '_mbi_delivery_candidate' );
		delete_post_meta( $id, '_mbi_candidate_refresh' );
		$progress = (array) get_option( 'mbi_image_validation_progress', array() ); if ( ( $progress['post_id'] ?? 0 ) === $id ) { delete_option( 'mbi_image_validation_progress' ); }
		if ( ! empty( $payload['manual_update'] ) ) {
			if ( get_post_status( $id ) !== 'publish' ) { throw new RuntimeException( 'WordPress não confirmou a publicação do capítulo validado.' ); }
			$this->complete_manual_update( $payload );
		}
		if ( empty( $payload['serial_batch'] ) && empty( $payload['manual_update'] ) ) { $this->maybe_publish_ready_manga( (int) $candidate['manga_id'] ); }
		clean_post_cache( $id ); do_action( 'litespeed_purge_post', $id );
		$this->db->log( 'info', 'Capítulo validado autonomamente: conteúdo de imagem verificado na origem e token Proxy/CDN conferido localmente (sem GET no Worker).', array( 'post_id' => $id, 'pages' => count( $candidate['urls'] ), 'source' => $candidate['url'] ) );
	}

	private function import_image( array $payload ): void {
		// Compatibilidade com jobs antigos: a partir da v1.5.1 nenhum job de imagem baixa arquivos.
		$this->assert_current_generation( $payload );
		$url     = $this->required_url( $payload, false );
		$post_id = isset( $payload['post_id'] ) ? absint( $payload['post_id'] ) : 0;
		$role    = sanitize_key( (string) ( $payload['role'] ?? '' ) );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			throw new MBI_Skip_Exception( 'Job legado de imagem ignorado: post de destino inválido.' );
		}

		if ( 'cover' === $role && 'mbi_manga' === get_post_type( $post_id ) ) {
			$cover_url = MBI_Image_Proxy::normalize_source_url( $url );
			if ( wp_http_validate_url( $cover_url ) ) {
				$delivery = $this->image_delivery_url( $cover_url );
				if ( ! $delivery || ( $this->image_proxy_ready() && ! MBI_Image_Proxy::is_proxy_url( $delivery, $this->settings() ) ) ) {
					throw new MBI_Skip_Exception( 'Job legado de capa preservado: não foi possível gerar endereço protegido.' );
				}
				update_post_meta( $post_id, '_mbi_cover_original_url', $cover_url );
				update_post_meta( $post_id, '_mbi_cover_source_url', $delivery );
				if ( has_post_thumbnail( $post_id ) ) {
					delete_post_thumbnail( $post_id );
				}
				$this->runtime( 'cover-remote', 'Capa remota vinculada sem download: ' . $cover_url, array( 'post_id' => $post_id ) );
				$this->maybe_publish_ready_manga( $post_id );
			}
			return;
		}

		if ( 'page' === $role && 'mbi_chapter' === get_post_type( $post_id ) ) {
			$expected    = absint( get_post_meta( $post_id, '_mbi_expected_pages', true ) );
			$source_urls = array_values( array_filter( array_map( 'esc_url_raw', (array) get_post_meta( $post_id, '_mbi_source_image_urls', true ) ) ) );
			if ( $expected > 0 && count( $source_urls ) === $expected ) {
				foreach ( $source_urls as $source_url ) {
					if ( ! wp_http_validate_url( $source_url ) ) {
						throw new MBI_Skip_Exception( 'Job legado ignorado: capítulo contém URL de página inválida.' );
					}
				}
				$delivery_pages = $this->image_delivery_urls( $source_urls );
				if ( count( $delivery_pages ) !== $expected || in_array( '', $delivery_pages, true ) || ( $this->image_proxy_ready() && count( array_filter( $delivery_pages, fn( $u ) => MBI_Image_Proxy::is_proxy_url( (string) $u, $this->settings() ) ) ) !== $expected ) ) {
					throw new MBI_Skip_Exception( 'Job legado de páginas preservado: não foi possível proteger todas as imagens.' );
				}
				update_post_meta( $post_id, '_mbi_chapter_page_urls', $delivery_pages );
				delete_post_meta( $post_id, '_mbi_chapter_page_map' );
				delete_post_meta( $post_id, '_mbi_chapter_page_ids' );
				$this->runtime( 'page-remote', 'Páginas convertidas para URLs remotas sem download.', array( 'post_id' => $post_id, 'pages' => $expected ) );
				$this->maybe_publish_ready_manga( wp_get_post_parent_id( $post_id ) );
			}
			return;
		}

		throw new MBI_Skip_Exception( 'Job legado de imagem ignorado no modo 100% remoto.' );
	}

	private function apply_image_attachment( array $payload, int $attachment_id ): void {
		$post_id = absint( $payload['post_id'] ?? 0 );
		if ( 'cover' === ( $payload['role'] ?? '' ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
			$origin = MBI_Image_Proxy::normalize_source_url( (string) ( $payload['url'] ?? '' ) );
			$delivery = $this->image_delivery_url( $origin );
			if ( ! $delivery || ( $this->image_proxy_ready() && ! MBI_Image_Proxy::is_proxy_url( $delivery, $this->settings() ) ) ) {
				throw new MBI_Skip_Exception( 'Job legado de anexo preservado: capa sem URL de entrega protegida.' );
			}
			update_post_meta( $post_id, '_mbi_cover_original_url', $origin );
			update_post_meta( $post_id, '_mbi_cover_source_url', $delivery );
			$this->maybe_publish_ready_manga( $post_id );
			return;
		}

		$index = max( 0, (int) ( $payload['index'] ?? 0 ) );
		$page_map = get_post_meta( $post_id, '_mbi_chapter_page_map', true );
		$page_map = is_array( $page_map ) ? $page_map : array();
		$page_map[ $index ] = $attachment_id;
		ksort( $page_map, SORT_NUMERIC );
		update_post_meta( $post_id, '_mbi_chapter_page_map', $page_map );

		$expected = absint( get_post_meta( $post_id, '_mbi_expected_pages', true ) );
		if ( $expected && count( $page_map ) >= $expected ) {
			$complete = true;
			$pages    = array();
			for ( $position = 0; $position < $expected; $position++ ) {
				if ( empty( $page_map[ $position ] ) ) {
					$complete = false;
					break;
				}
				$pages[] = absint( $page_map[ $position ] );
			}
			if ( $complete ) {
				update_post_meta( $post_id, '_mbi_chapter_page_ids', $pages );
				$target_status = sanitize_key( get_post_meta( $post_id, '_mbi_target_status', true ) );
				if ( in_array( $target_status, array( 'publish', 'draft', 'private' ), true ) ) {
					$parent_id = wp_get_post_parent_id( $post_id );
					$this->maybe_publish_ready_manga( $parent_id );
					$status = 'publish' === $target_status && 'publish' !== get_post_status( $parent_id ) ? 'draft' : $target_status;
					wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ) );
				}
			}
		}
	}

	/** Libera a obra somente quando TODOS os capítulos detectados e TODAS as páginas estiverem completos. */
	private function maybe_publish_ready_manga( int $manga_id ): void {
		$block = static function ( string $reason ) use ( $manga_id ): void { if ( $manga_id ) { update_post_meta( $manga_id, '_mbi_finalization_block', $reason ); } };
		if ( get_post_meta( $manga_id, '_mbi_origin_withdrawn', true ) ) { $block( 'Obra marcada como indisponível na origem; exige revisão.' ); return; }
		if ( ! $manga_id || 'mbi_manga' !== get_post_type( $manga_id ) ) {
			$block( 'Registro da obra inválido ou ausente.' );
			return;
		}
		$validation_issues = $this->validate_existing_manga( $manga_id );
		if ( $validation_issues ) {
			$block( 'Metadados exigem revisão: ' . implode( '; ', array_map( 'strval', $validation_issues ) ) );
			update_post_meta( $manga_id, '_mbi_validation_status', 'review' );
			update_post_meta( $manga_id, '_mbi_validation_errors', $validation_issues );
			update_post_meta( $manga_id, '_mbi_validation_checked_at', current_time( 'mysql' ) );
			if ( 'publish' !== get_post_status( $manga_id ) ) {
				update_post_meta( $manga_id, '_mbi_ready', 0 );
			}
			$this->db->log( 'warning', 'Dados da obra exigem revisão; publicações existentes foram preservadas.', array( 'manga_id' => $manga_id, 'issues' => $validation_issues ) );
			$batch_id = (string) get_post_meta( $manga_id, '_mbi_serial_batch_id', true );
			$blocked_for = (string) get_post_meta( $manga_id, '_mbi_validation_blocked_batch', true );
			if ( $batch_id && $blocked_for !== $batch_id ) {
				update_post_meta( $manga_id, '_mbi_validation_blocked_batch', $batch_id );
				$this->advance_serial_batch( $batch_id, $manga_id, false );
			}
			return;
		}
		if ( 'passed' !== (string) get_post_meta( $manga_id, '_mbi_validation_status', true ) ) {
			update_post_meta( $manga_id, '_mbi_validation_status', 'passed' );
			update_post_meta( $manga_id, '_mbi_validation_errors', array() );
			update_post_meta( $manga_id, '_mbi_validation_checked_at', current_time( 'mysql' ) );
		}
		$cover_url = esc_url_raw( (string) get_post_meta( $manga_id, '_mbi_cover_source_url', true ) );
		if ( ! $cover_url || ! MBI_Image_Proxy::valid_browser_url( $cover_url ) ) {
			$block( 'Capa ausente ou endereço inválido.' );
			return;
		}
		$content = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $manga_id ) ) );
		if ( ! $content ) {
			$block( 'Sinopse ausente.' );
			return;
		}
		$category_slugs = wp_get_object_terms( $manga_id, 'category', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $category_slugs ) || ! array_intersect( array( 'mangas', 'manhwas', 'manhuas' ), (array) $category_slugs ) ) {
			$block( 'Categoria principal ausente: mangás, manhwas ou manhuas.' );
			return;
		}
		$expected_urls = array_values( array_unique( array_filter( (array) get_post_meta( $manga_id, '_mbi_source_chapter_urls', true ) ) ) );
		$expected      = absint( get_post_meta( $manga_id, '_mbi_expected_chapters', true ) );
		if ( ! $expected_urls || ! $expected || count( $expected_urls ) !== $expected ) {
			$block( 'Lista e contagem de capítulos esperados estão inconsistentes.' );
			return;
		}

		$chapter_index = $this->chapter_index_for_urls( $expected_urls, $manga_id );
		$chapter_ids = array();
		foreach ( $expected_urls as $chapter_url ) {
			$chapter_id = (int) ( $chapter_index[ $this->source_key( (string) $chapter_url ) ] ?? 0 );
			if ( ! $chapter_id || wp_get_post_parent_id( $chapter_id ) !== $manga_id || ! $this->chapter_is_complete_cached( $chapter_id ) ) {
				$block( 'Capítulo esperado ausente, incompleto ou associado a outra obra: ' . (string) $chapter_url );
				return;
			}
			$chapter_ids[] = $chapter_id;
		}

		$target = sanitize_key( get_post_meta( $manga_id, '_mbi_target_status', true ) );
		if ( 'publish' === get_post_status( $manga_id ) ) {
			$target = 'publish';
		}
		if ( ! in_array( $target, array( 'publish', 'draft', 'private' ), true ) ) {
			$block( 'Destino editorial da obra ausente ou inválido.' );
			return;
		}

		// Para uma obra pública, primeiro todos os capítulos completos precisam ficar públicos.
		// Só depois liberamos a página do mangá e avançamos o lote serial.
		if ( 'publish' === $target ) {
			$this->publish_completed_chapters( $manga_id, $chapter_ids );
			if ( ! $this->all_chapters_published( $chapter_ids ) ) {
				$block( 'Capítulos completos não ficaram públicos; confira destino editorial e gravação no banco.' );
				return;
			}
		}

		$result = wp_update_post( array( 'ID' => $manga_id, 'post_status' => $target ), true );
		if ( is_wp_error( $result ) || sanitize_key( (string) get_post_status( $manga_id ) ) !== $target ) {
			$block( is_wp_error( $result ) ? 'Falha ao publicar obra: ' . $result->get_error_message() : 'A gravação não confirmou o destino editorial da obra.' );
			return;
		}
		delete_post_meta( $manga_id, '_mbi_finalization_block' );
		update_post_meta( $manga_id, '_mbi_ready', 1 );
		delete_post_meta( $manga_id, '_mbi_update_in_progress' );

		$batch_id = (string) get_post_meta( $manga_id, '_mbi_serial_batch_id', true );
		$done_for = (string) get_post_meta( $manga_id, '_mbi_serial_completed_batch', true );
		if ( $batch_id && $done_for !== $batch_id ) {
			update_post_meta( $manga_id, '_mbi_serial_completed_batch', $batch_id );
			$this->db->log( 'info', 'Obra 100% concluída e liberada. Próxima obra será iniciada agora.', array( 'manga_id' => $manga_id, 'chapters' => count( $chapter_ids ), 'batch' => $batch_id ) );
			$this->advance_serial_batch( $batch_id, $manga_id, true );
		}
	}

	private function chapter_is_complete( int $chapter_id ): bool {
		$expected    = absint( get_post_meta( $chapter_id, '_mbi_expected_pages', true ) );
		$source_urls = array_values( array_filter( array_map( 'esc_url_raw', (array) get_post_meta( $chapter_id, '_mbi_source_image_urls', true ) ) ) );
		$remote_urls = array_values( array_filter( array_map( 'esc_url_raw', (array) get_post_meta( $chapter_id, '_mbi_chapter_page_urls', true ) ) ) );
		if ( $expected > 0 && count( $remote_urls ) === $expected && count( $source_urls ) === $expected ) {
			$delivery_urls = $this->image_delivery_urls( $source_urls );
			for ( $i = 0; $i < $expected; $i++ ) {
				if ( ! MBI_Image_Proxy::valid_browser_url( $remote_urls[ $i ] ) || $remote_urls[ $i ] !== (string) ( $delivery_urls[ $i ] ?? '' ) ) {
					return false;
				}
			}
			return MBI_Chapter_Integrity::valid( $chapter_id );
		}
		$pages       = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $chapter_id, '_mbi_chapter_page_ids', true ) ) ) );
		if ( $expected < 1 || count( $pages ) !== $expected || count( $source_urls ) !== $expected ) {
			return false;
		}
		foreach ( $pages as $position => $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				return false;
			}
			$attachment_source = esc_url_raw( (string) get_post_meta( $attachment_id, '_mbi_source_url', true ) );
			$expected_source   = esc_url_raw( (string) ( $source_urls[ $position ] ?? '' ) );
			if ( ! $attachment_source || ! $expected_source || $attachment_source !== $expected_source ) {
				return false;
			}
		}
		return true;
	}

	private function chapter_is_complete_cached( int $chapter_id ): bool {
		$complete = $this->chapter_is_complete( $chapter_id );
		if ( $complete ) {
			update_post_meta( $chapter_id, '_mbi_chapter_complete', 1 );
		}
		return $complete;
	}

	/**
	 * Repara obras já publicadas após a correção de permalink: publica apenas capítulos
	 * estruturalmente completos que ainda tenham ficado em rascunho por versões anteriores.
	 * Não cria, baixa nem reprocessa conteúdo e não interfere na fila ativa.
	 */
	public function repair_published_chapter_access( int $limit = 200 ): int {
		$limit = max( 1, min( 1000, $limit ) );
		$mangas = get_posts(
			array(
				'post_type'      => 'mbi_manga',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$repaired = 0;
		foreach ( $mangas as $manga_id ) {
			$before = count( get_posts( array( 'post_type' => 'mbi_chapter', 'post_status' => 'publish', 'post_parent' => (int) $manga_id, 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) );
			$this->publish_completed_chapters( (int) $manga_id );
			$after = count( get_posts( array( 'post_type' => 'mbi_chapter', 'post_status' => 'publish', 'post_parent' => (int) $manga_id, 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) );
			$repaired += max( 0, $after - $before );
		}
		return $repaired;
	}

	/** Publica capítulos completos que aguardavam a publicação da obra real. */
	private function publish_completed_chapters( int $manga_id, array $validated_ids = array() ): void {
		if ( get_post_meta( $manga_id, '_mbi_origin_withdrawn', true ) ) { return; }
		$chapters = $validated_ids ?: get_posts(
			array(
				'post_type'      => 'mbi_chapter',
				'post_status'    => 'draft',
				'post_parent'    => $manga_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		if ( $validated_ids ) {
			// Todos estes IDs já passaram pela validação acima. Publicar em blocos evita
			// mais de mil chamadas wp_update_post() na etapa final de uma obra longa.
			global $wpdb;
			$publish_ids = array_values( array_unique( array_filter( array_map( 'absint', $chapters ), static fn( $id ) => MBI_Chapter_Integrity::valid( $id ) ) ) );
			$now_gmt   = current_time( 'mysql', true );
			$now_local = current_time( 'mysql' );
			foreach ( array_chunk( $publish_ids, 400 ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} target ON target.post_id = p.ID AND target.meta_key = '_mbi_target_status' AND target.meta_value = 'publish'
						 SET p.post_status = 'publish', p.post_modified = %s, p.post_modified_gmt = %s
						 WHERE p.ID IN ({$placeholders}) AND p.post_parent = %d AND p.post_type = 'mbi_chapter' AND p.post_status = 'draft'",
						$now_local,
						$now_gmt,
						...array_merge( $chunk, array( $manga_id ) )
					)
				);
			}
			foreach ( $publish_ids as $chapter_id ) {
				clean_post_cache( $chapter_id );
			}
			do_action( 'rank_math/sitemap/flush_cache' );
			return;
		}
		foreach ( $chapters as $chapter_id ) {
			if ( 'draft' !== get_post_status( (int) $chapter_id ) ) {
				continue;
			}
			$target = sanitize_key( get_post_meta( $chapter_id, '_mbi_target_status', true ) );
			$complete = $validated_ids ? true : $this->chapter_is_complete_cached( (int) $chapter_id );
			if ( 'publish' === $target && $complete ) {
				wp_update_post( array( 'ID' => (int) $chapter_id, 'post_status' => 'publish' ), true );
				clean_post_cache( (int) $chapter_id );
			}
		}
	}

	private function all_chapters_published( array $chapter_ids ): bool {
		global $wpdb;
		$chapter_ids = array_values( array_unique( array_filter( array_map( 'absint', $chapter_ids ) ) ) );
		if ( ! $chapter_ids ) {
			return false;
		}
		$published = 0;
		foreach ( array_chunk( $chapter_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$published += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ({$placeholders}) AND post_type = 'mbi_chapter' AND post_status = 'publish'",
					...$chunk
				)
			);
		}
		return $published === count( $chapter_ids );
	}


	/**
	 * Caminho rápido para páginas de leitura: salva o original e cria o attachment sem gerar
	 * miniaturas/subsizes nem converter para WebP. Capas continuam usando o pipeline completo.
	 */
	private function sideload_temp_image_fast( string $tmp, string $url, int $post_id, string $filename, string $alt, string $content_type ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! $tmp || ! file_exists( $tmp ) || ! $post_id ) {
			throw new RuntimeException( 'Arquivo temporário de imagem inválido.' );
		}
		$content_type = strtolower( trim( explode( ';', $content_type )[0] ) );
		if ( ! str_starts_with( $content_type, 'image/' ) ) {
			@unlink( $tmp );
			throw new RuntimeException( 'O arquivo remoto não foi identificado como imagem.' );
		}
		$extension = $this->extension_from_mime( $content_type );
		$file = array( 'name' => sanitize_file_name( $filename ) . '.' . $extension, 'tmp_name' => $tmp );
		$moved = wp_handle_sideload( $file, array( 'test_form' => false ) );
		if ( ! is_array( $moved ) || ! empty( $moved['error'] ) || empty( $moved['file'] ) || empty( $moved['url'] ) ) {
			@unlink( $tmp );
			throw new RuntimeException( 'Falha ao salvar página do mangá: ' . (string) ( $moved['error'] ?? 'erro desconhecido' ) );
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $content_type,
				'post_title'     => sanitize_text_field( $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			(string) $moved['file'],
			$post_id,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( (string) $moved['file'] );
			throw new RuntimeException( 'Falha ao registrar página do mangá: ' . $attachment_id->get_error_message() );
		}
		$dimensions = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( (string) $moved['file'] ) : @getimagesize( (string) $moved['file'] );
		if ( is_array( $dimensions ) && ! empty( $dimensions[0] ) && ! empty( $dimensions[1] ) ) {
			$relative = function_exists( '_wp_relative_upload_path' ) ? _wp_relative_upload_path( (string) $moved['file'] ) : '';
			wp_update_attachment_metadata(
				(int) $attachment_id,
				array(
					'width'      => (int) $dimensions[0],
					'height'     => (int) $dimensions[1],
					'file'       => (string) $relative,
					'sizes'      => array(),
					'image_meta' => array(),
				)
			);
		}
		update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		update_post_meta( (int) $attachment_id, '_mbi_source_url', esc_url_raw( $url ) );
		update_post_meta( (int) $attachment_id, '_mbi_fast_page_attachment', 1 );
		return (int) $attachment_id;
	}

	private function sideload_temp_image( string $tmp, string $url, int $post_id, string $filename, string $alt, string $content_type ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		if ( ! $tmp || ! file_exists( $tmp ) || ! $post_id ) {
			throw new RuntimeException( 'Arquivo temporário de imagem inválido.' );
		}
		$content_type = strtolower( trim( explode( ';', $content_type )[0] ) );
		if ( ! str_starts_with( $content_type, 'image/' ) ) {
			@unlink( $tmp );
			throw new RuntimeException( 'O arquivo remoto não foi identificado como imagem.' );
		}
		$extension = $this->extension_from_mime( $content_type );
		$settings  = $this->settings();
		if ( ! empty( $settings['convert_webp'] ) && 'gif' !== $extension ) {
			$editor = wp_get_image_editor( $tmp );
			if ( ! is_wp_error( $editor ) && $editor->supports_mime_type( 'image/webp' ) ) {
				$editor->set_quality( max( 60, min( 95, (int) $settings['webp_quality'] ) ) );
				$webp_tmp = $tmp . '.webp';
				$saved    = $editor->save( $webp_tmp, 'image/webp' );
				if ( ! is_wp_error( $saved ) && file_exists( $webp_tmp ) ) {
					@unlink( $tmp );
					$tmp       = $webp_tmp;
					$extension = 'webp';
				}
			}
		}
		$file = array( 'name' => sanitize_file_name( $filename ) . '.' . $extension, 'tmp_name' => $tmp );
		$attachment_id = media_handle_sideload( $file, $post_id, $alt );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			throw new RuntimeException( 'Falha ao salvar imagem: ' . $attachment_id->get_error_message() );
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		update_post_meta( $attachment_id, '_mbi_source_url', esc_url_raw( $url ) );
		return (int) $attachment_id;
	}

	private function download_image( string $url, int $post_id, string $filename, string $alt, string $referer, bool $fast_page = false ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$tmp = wp_tempnam( $url );
		if ( ! $tmp ) {
			throw new RuntimeException( 'Não foi possível criar arquivo temporário.' );
		}

		$headers = array( 'User-Agent' => $this->user_agent() );
		if ( $referer ) {
			$headers['Referer'] = esc_url_raw( $referer );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'redirection'         => 5,
				'headers'             => $headers,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => 25 * MB_IN_BYTES,
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp );
			throw new RuntimeException( 'Falha ao baixar imagem: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			@unlink( $tmp );
			throw new RuntimeException( 'A imagem respondeu com HTTP ' . $code );
		}

		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( ! str_starts_with( $content_type, 'image/' ) ) {
			@unlink( $tmp );
			throw new RuntimeException( 'O arquivo remoto não foi identificado como imagem.' );
		}

		if ( $fast_page ) {
			return $this->sideload_temp_image_fast( $tmp, $url, $post_id, $filename, $alt, $content_type );
		}

		$extension = $this->extension_from_mime( $content_type );
		$settings  = $this->settings();
		if ( ! empty( $settings['convert_webp'] ) && 'gif' !== $extension ) {
			$editor = wp_get_image_editor( $tmp );
			if ( ! is_wp_error( $editor ) && $editor->supports_mime_type( 'image/webp' ) ) {
				$editor->set_quality( max( 60, min( 95, (int) $settings['webp_quality'] ) ) );
				$webp_tmp = $tmp . '.webp';
				$saved    = $editor->save( $webp_tmp, 'image/webp' );
				if ( ! is_wp_error( $saved ) && file_exists( $webp_tmp ) ) {
					@unlink( $tmp );
					$tmp       = $webp_tmp;
					$extension = 'webp';
				}
			}
		}

		$file = array(
			'name'     => sanitize_file_name( $filename ) . '.' . $extension,
			'tmp_name' => $tmp,
		);
		$attachment_id = media_handle_sideload( $file, $post_id, $alt );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			throw new RuntimeException( 'Falha ao salvar imagem: ' . $attachment_id->get_error_message() );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		update_post_meta( $attachment_id, '_mbi_source_url', esc_url_raw( $url ) );
		return (int) $attachment_id;
	}

	public function test_connection(): array {
		$url = trailingslashit( $this->settings()['source_url'] ) . 'manga/';
		try {
			$html = $this->fetch_html( $url );
			$this->parser->load( $html, $url );
			$data = $this->parser->parse_catalog();
			if ( empty( $data['manga_urls'] ) ) {
				throw new RuntimeException( 'A página respondeu, mas nenhuma obra foi reconhecida.' );
			}

			$sample = array();
			$sample_url = '';
			foreach ( array_slice( $data['manga_urls'], 0, 10 ) as $candidate_url ) {
				$candidate_html = $this->fetch_html( $candidate_url );
				$this->parser->load( $candidate_html, $candidate_url );
				$candidate = $this->parser->parse_manga( $candidate_url );
				if ( ! empty( $candidate['title'] ) && ! empty( $candidate['synopsis'] ) && ! empty( $candidate['cover_url'] ) && ! empty( $candidate['chapter_urls'] ) ) {
					$sample = $candidate;
					$sample_url = $candidate_url;
					break;
				}
			}
			if ( ! $sample ) {
				throw new RuntimeException( 'O catálogo respondeu, mas nenhuma das dez obras testadas possui título, capa, sinopse e capítulos completos.' );
			}

			$chapter_url  = (string) reset( $sample['chapter_urls'] );
			$chapter_html = $this->fetch_html( $chapter_url );
			$this->parser->load( $chapter_html, $chapter_url );
			$chapter = $this->parser->parse_chapter( $chapter_url, false );
			if ( empty( $chapter['image_urls'] ) ) {
				throw new RuntimeException( 'A obra foi reconhecida, mas nenhuma página foi encontrada no capítulo de teste.' );
			}
			return array(
				'ok'      => true,
				'url'     => $url,
				'mangas'  => count( $data['manga_urls'] ),
				'max_page'=> $data['max_page'],
				'message' => sprintf( 'Teste completo aprovado: “%s”, capa, sinopse, %d capítulos e %d páginas no capítulo de amostra.', $sample['title'], count( $sample['chapter_urls'] ), count( $chapter['image_urls'] ) ),
			);
		} catch ( Throwable $exception ) {
			return array( 'ok' => false, 'url' => $url, 'mangas' => 0, 'max_page' => 0, 'message' => $exception->getMessage() );
		}
	}

	private function fetch_html( string $url, int $timeout = 15, bool $json = false, string $nonce = '' ): string {
		$this->assert_allowed_source_url( $url );
		$key = esc_url_raw( $url );
		if ( isset( $this->prefetched_html[ $key ] ) ) {
			$html = (string) $this->prefetched_html[ $key ];
			unset( $this->prefetched_html[ $key ] );
			$this->clear_source_circuit( $url );
			return $html;
		}

		$remaining = $this->source_circuit_remaining( $url );
		if ( $remaining > 0 ) {
			throw new MBI_Source_Backoff_Exception(
				'SOURCE_COOLDOWN: a origem está em pausa após erro de disponibilidade.',
				$this->source_host( $url ),
				$remaining
			);
		}

		// Ritmo preventivo por host. Evita rajadas que pareciam rápidas por alguns
		// segundos, mas terminavam em 429/522 e deixavam a importação parada por
		// minutos. Com uma cadência curta e contínua, o throughput líquido aumenta.
		$pace_key = 'mbi_source_last_' . md5( $this->source_host( $url ) );
		$last_request = (float) get_option( $pace_key, 0 );
		$elapsed = microtime( true ) - $last_request;
		$minimum_interval = 0.65;
		if ( $last_request > 0 && $elapsed < $minimum_interval ) {
			usleep( (int) ( ( $minimum_interval - $elapsed ) * 1000000 ) );
		}
		update_option( $pace_key, microtime( true ), false );

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => max( 1, min( 15, $timeout ) ),
				'limit_response_size' => 8 * MB_IN_BYTES + 1,
				'redirection' => 5,
				'headers'     => array_merge( array(
					'User-Agent' => $this->user_agent(),
					'Accept'     => $json ? 'application/json' : 'text/html,application/xhtml+xml',
					'Connection' => 'keep-alive',
				), $json && $nonce !== '' ? array( 'X-WP-Nonce' => $nonce ) : array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
			if ( $this->resolver_pressure_error( $error ) ) { throw new RuntimeException( $error ); }
			if ( $this->origin_pressure_error( $error ) ) {
				$delay = $this->open_source_circuit( $url, $error );
				throw new MBI_Source_Backoff_Exception( $error, $this->source_host( $url ), $delay );
			}
			throw new RuntimeException( $error );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$error = 'A fonte respondeu com HTTP ' . $code . ' para ' . $url;
			if ( $this->origin_pressure_error( $error ) ) {
				$retry_after = $this->retry_after_seconds( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
				$delay = $this->open_source_circuit( $url, $error, $retry_after );
				throw new MBI_Source_Backoff_Exception( $error, $this->source_host( $url ), $delay );
			}
			throw new RuntimeException( $error );
		}
		$html = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $html ) > 8 * MB_IN_BYTES ) {
			throw new RuntimeException( 'HTML excedeu o limite seguro de 8 MB; conteúdo não foi publicado.' );
		}
		if ( ! $json && strlen( $html ) < 500 ) {
			throw new RuntimeException( 'A fonte retornou HTML vazio ou incompleto.' );
		}
		$this->clear_source_circuit( $url );
		return $html;
	}

	private function required_url( array $payload, bool $source_only = true ): string {
		$url = isset( $payload['url'] ) ? esc_url_raw( $payload['url'] ) : '';
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			throw new RuntimeException( 'URL ausente ou inválida no job.' );
		}
		if ( $source_only ) {
			$this->assert_allowed_source_url( $url );
		}
		return $url;
	}

	private function assert_allowed_source_url( string $url ): void {
		$host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$scheme  = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$allowed = preg_split( '/[\r\n,]+/', (string) $this->settings()['allowed_hosts'] );
		$allowed = array_filter( array_map( static fn( $item ) => strtolower( trim( $item ) ), $allowed ) );
		if ( 'https' !== $scheme || ! in_array( $host, $allowed, true ) ) {
			throw new RuntimeException( 'A URL não pertence a um domínio autorizado nas configurações.' );
		}
	}


	private function canonical_source_url( string $url ): string {
		$url = esc_url_raw( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		$host = strtolower( (string) $parts['host'] );
		$host = preg_replace( '/^www\./i', '', $host );
		$path = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '/';
		$path = '/' === $path ? '/' : trailingslashit( untrailingslashit( $path ) );
		return 'https://' . $host . $path;
	}

	private function source_key( string $url ): string {
		return md5( $this->canonical_source_url( $url ) );
	}

	private function acquire_manga_reservation( string $url ): string {
		$name = 'mbi_manga_res_' . $this->source_key( $url );
		$token = wp_generate_uuid4();
		$value = array( 'token' => $token, 'time' => time() );
		for ( $i = 0; $i < 12; $i++ ) {
			if ( add_option( $name, $value, '', false ) ) {
				return $token;
			}
			$existing = get_option( $name, array() );
			$locked_at = is_array( $existing ) ? (int) ( $existing['time'] ?? 0 ) : 0;
			if ( ! $locked_at || $locked_at < time() - 180 ) {
				delete_option( $name );
				continue;
			}
			usleep( 150000 );
		}
		throw new RuntimeException( 'A mesma obra já está sendo processada por outro worker. Nenhuma cópia nova será criada.' );
	}

	private function release_manga_reservation( string $url, string $token ): void {
		if ( ! $token ) {
			return;
		}
		$name = 'mbi_manga_res_' . $this->source_key( $url );
		$existing = get_option( $name, array() );
		if ( is_array( $existing ) && ! empty( $existing['token'] ) && hash_equals( $token, (string) $existing['token'] ) ) {
			delete_option( $name );
		}
	}

	private function manga_score( int $manga_id ): int {
		$score = 'publish' === get_post_status( $manga_id ) ? 1000000 : 0;
		$chapters = get_posts( array( 'post_parent' => $manga_id, 'post_type' => 'mbi_chapter', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ) );
		$score += count( $chapters ) * 1000;
		foreach ( $chapters as $chapter_id ) {
			$pages = get_post_meta( (int) $chapter_id, '_mbi_chapter_page_urls', true );
			$score += is_array( $pages ) ? min( 999, count( $pages ) ) : 0;
		}
		return $score;
	}

	public function cleanup_duplicate_manga_drafts(): array {
		$query = new WP_Query( array(
			'post_type' => 'mbi_manga',
			'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'fields' => 'ids',
			'orderby' => 'ID',
			'order' => 'ASC',
		) );
		$groups = array();
		foreach ( $query->posts as $manga_id ) {
			$url = esc_url_raw( (string) get_post_meta( $manga_id, '_mbi_source_url', true ) );
			if ( ! $url ) {
				continue;
			}
			$key = $this->source_key( $url );
			update_post_meta( $manga_id, '_mbi_source_key', $key );
			$groups[ $key ][] = (int) $manga_id;
		}
		wp_reset_postdata();

		$state = $this->serial_state();
		$current_id = absint( $state['current_manga_id'] ?? 0 );
		$mapping = array();
		$stats = array( 'groups' => 0, 'manga_trashed' => 0, 'chapters_reparented' => 0, 'chapters_trashed' => 0 );

		foreach ( $groups as $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$stats['groups']++;
			$published = array_values( array_filter( $ids, static fn( $id ) => 'publish' === get_post_status( $id ) ) );
			if ( $published ) {
				$keeper = (int) $published[0];
			} elseif ( $current_id && in_array( $current_id, $ids, true ) ) {
				$keeper = $current_id;
			} else {
				usort( $ids, function( $a, $b ) {
					$sa = $this->manga_score( (int) $a );
					$sb = $this->manga_score( (int) $b );
					return $sa === $sb ? ( (int) $a <=> (int) $b ) : ( $sb <=> $sa );
				} );
				$keeper = (int) $ids[0];
			}

			foreach ( $ids as $duplicate_id ) {
				$duplicate_id = (int) $duplicate_id;
				if ( $duplicate_id === $keeper || 'publish' === get_post_status( $duplicate_id ) ) {
					continue;
				}
				foreach ( array( '_mbi_cover_source_url', '_mbi_original_title', '_mbi_source_hash', '_mbi_expected_chapters', '_mbi_source_chapter_urls', '_mbi_target_status' ) as $meta_key ) {
					if ( ! get_post_meta( $keeper, $meta_key, true ) ) {
						$value = get_post_meta( $duplicate_id, $meta_key, true );
						if ( $value ) {
							update_post_meta( $keeper, $meta_key, $value );
						}
					}
				}
				$children = get_posts( array( 'post_parent' => $duplicate_id, 'post_type' => 'mbi_chapter', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ) );
				foreach ( $children as $chapter_id ) {
					$chapter_id = (int) $chapter_id;
					$source = esc_url_raw( (string) get_post_meta( $chapter_id, '_mbi_source_url', true ) );
					$existing = 0;
					if ( $source ) {
						$candidates = get_posts( array( 'post_type' => 'mbi_chapter', 'post_status' => 'any', 'post_parent' => $keeper, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_mbi_source_url', 'meta_value' => $source, 'no_found_rows' => true ) );
						$existing = $candidates ? (int) $candidates[0] : 0;
					}
					if ( ! $existing ) {
						wp_update_post( array( 'ID' => $chapter_id, 'post_parent' => $keeper ) );
						$stats['chapters_reparented']++;
					} elseif ( 'publish' !== get_post_status( $chapter_id ) ) {
						wp_trash_post( $chapter_id );
						$stats['chapters_trashed']++;
					}
				}
				$mapping[ $duplicate_id ] = $keeper;
				wp_trash_post( $duplicate_id );
				$stats['manga_trashed']++;
			}
		}

		if ( $mapping && is_array( $state ) && ! empty( $state['id'] ) ) {
			if ( ! empty( $state['current_manga_id'] ) && isset( $mapping[ (int) $state['current_manga_id'] ] ) ) {
				$state['current_manga_id'] = $mapping[ (int) $state['current_manga_id'] ];
			}
			foreach ( (array) ( $state['items'] ?? array() ) as $index => $item ) {
				$item = (array) $item;
				$id = absint( $item['manga_id'] ?? 0 );
				if ( $id && isset( $mapping[ $id ] ) ) {
					$state['items'][ $index ]['manga_id'] = $mapping[ $id ];
				}
			}
			update_option( 'mbi_serial_batch', $state, false );
		}
		$this->db->log( 'warning', 'Deduplicação concluída: duplicatas não publicadas com a mesma URL de origem foram movidas para a lixeira.', $stats );
		return $stats;
	}

	public function repair_duplicate_state(): array {
		$was_paused = $this->is_paused();
		update_option( 'mbi_queue_paused', 1, false );
		$generation = $this->rotate_generation();
		delete_option( 'mbi_worker_lock' );
		$this->worker_lock_token = '';
		delete_transient( 'mbi_worker_dispatching' );
		update_option( 'mbi_worker_token', wp_generate_password( 48, false, false ), false );
		$state = $this->serial_state();
		$removed = 0;
		if ( ! empty( $state['active'] ) && ! empty( $state['id'] ) ) {
			$removed = $this->db->purge_active_content_jobs_for_batch( (string) $state['id'] );
		}
		$stats = $this->cleanup_duplicate_manga_drafts();
		$state = $this->serial_state();
		if ( ! empty( $state['active'] ) && ! empty( $state['id'] ) ) {
			$state['generation'] = $generation;
			update_option( 'mbi_serial_batch', $state, false );
			$this->queue_serial_current( $state );
		}
		update_option( 'mbi_queue_paused', $was_paused ? 1 : 0, false );
		if ( ! $was_paused && ! empty( $state['active'] ) ) {
			wp_schedule_single_event( time() + 1, 'mbi_process_queue' );
			$this->kick_worker();
		}
		$stats['removed_active_jobs'] = $removed;
		$stats['paused_preserved'] = $was_paused;
		return $stats;
	}

	/**
	 * Localiza centenas de capítulos em poucas consultas indexadas. Prefere o post
	 * já ligado à obra atual quando encontra dados legados duplicados.
	 */
	private function chapter_index_for_urls( array $urls, int $preferred_parent = 0 ): array {
		global $wpdb;
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
		if ( ! $urls ) {
			return array();
		}
		$index = array();
		$ids   = array();
		$keys  = array_values( array_unique( array_map( fn( $url ) => $this->source_key( $url ), $urls ) ) );

		foreach ( array_chunk( $keys, 250 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql = $wpdb->prepare(
				"SELECT p.ID, p.post_parent, pm.meta_value source_value
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_mbi_source_key'
				 WHERE p.post_type = 'mbi_chapter' AND p.post_status NOT IN ('trash','auto-draft')
				   AND pm.meta_value IN ({$placeholders})
				 ORDER BY p.ID ASC",
				...$chunk
			);
			foreach ( (array) $wpdb->get_results( $sql ) as $row ) {
				$key = (string) $row->source_value;
				if ( ! isset( $index[ $key ] ) || ( $preferred_parent && (int) $row->post_parent === $preferred_parent ) ) {
					$index[ $key ] = (int) $row->ID;
				}
				$ids[] = (int) $row->ID;
			}
		}

		// Compatibilidade com capítulos antigos que ainda não receberam source_key.
		$missing_urls = array_values( array_filter( $urls, fn( $url ) => ! isset( $index[ $this->source_key( $url ) ] ) ) );
		foreach ( array_chunk( $missing_urls, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql = $wpdb->prepare(
				"SELECT p.ID, p.post_parent, pm.meta_value source_value
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_mbi_source_url'
				 WHERE p.post_type = 'mbi_chapter' AND p.post_status NOT IN ('trash','auto-draft')
				   AND pm.meta_value IN ({$placeholders})
				 ORDER BY p.ID ASC",
				...$chunk
			);
			foreach ( (array) $wpdb->get_results( $sql ) as $row ) {
				$key = $this->source_key( (string) $row->source_value );
				if ( ! isset( $index[ $key ] ) || ( $preferred_parent && (int) $row->post_parent === $preferred_parent ) ) {
					$index[ $key ] = (int) $row->ID;
				}
				$ids[] = (int) $row->ID;
				update_post_meta( (int) $row->ID, '_mbi_source_key', $key );
			}
		}

		if ( $ids ) {
			update_meta_cache( 'post', array_values( array_unique( $ids ) ) );
		}
		return $index;
	}

	private function find_by_source_url( string $post_type, string $url ): int {
		$key = $this->source_key( $url );
		$posts = get_posts( array(
			'post_type' => $post_type,
			'post_status' => 'any',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_key' => '_mbi_source_key',
			'meta_value' => $key,
			'no_found_rows' => true,
		) );
		if ( $posts ) {
			return (int) $posts[0];
		}
		$posts = get_posts( array(
			'post_type' => $post_type,
			'post_status' => 'any',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_key' => '_mbi_source_url',
			'meta_value' => esc_url_raw( $url ),
			'no_found_rows' => true,
		) );
		if ( $posts ) {
			update_post_meta( (int) $posts[0], '_mbi_source_key', $key );
			return (int) $posts[0];
		}
		return 0;
	}

	private function starter_post_count(): int {
		$counts = wp_count_posts( 'mbi_manga' );
		if ( ! $counts ) {
			return 0;
		}
		$total = array_sum( array_map( 'intval', (array) $counts ) );
		return max( 0, $total - (int) ( $counts->trash ?? 0 ) - (int) ( $counts->{'auto-draft'} ?? 0 ) );
	}

	private function find_attachment_by_source_url( string $url ): int {
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_mbi_source_url',
				'meta_value'     => esc_url_raw( $url ),
				'no_found_rows'  => true,
			)
		);
		return $posts ? (int) $posts[0] : 0;
	}

	private function publish_status(): string {
		$status = sanitize_key( $this->settings()['publish_status'] );
		return in_array( $status, array( 'publish', 'draft', 'private' ), true ) ? $status : 'publish';
	}

	private function format_manga_title( string $title ): string {
		$title = trim( preg_replace( '/^Ler\s+|\s+Online\s+Gr[aá]tis$/iu', '', $title ) );
		return sprintf( 'Ler %s Online Grátis', $title );
	}

	private function user_agent(): string {
		return 'MangaBridgeImporter/' . MBI_VERSION . '; ' . home_url( '/' );
	}

	private function extension_from_mime( string $mime ): string {
		$mime = strtolower( trim( explode( ';', $mime )[0] ) );
		return array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		)[ $mime ] ?? 'jpg';
	}
}
