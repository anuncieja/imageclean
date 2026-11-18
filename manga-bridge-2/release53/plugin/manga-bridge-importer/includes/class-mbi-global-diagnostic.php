<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only catalogue inventory and bounded HTTP sampling; never changes content. */
final class MBI_Global_Diagnostic {
	private MBI_Importer $importer;
	private MBI_DB $db;
	private int $events = 0;
	private array $pending = array();
	private array $timers = array();
	private bool $own_request = false;
	private const KEYS = array( '_mbi_cover_original_url', '_mbi_cover_source_url', '_mbi_source_image_urls', '_mbi_chapter_page_urls_original', '_mbi_chapter_page_urls', '_thumbnail_id', '_mbi_chapter_page_ids', '_mbi_expected_pages', '_mbi_expected_chapters', '_mbi_ready', '_mbi_chapter_complete', '_mbi_source_url' );

	public function __construct( MBI_Importer $importer, MBI_DB $db ) {
		$this->importer = $importer; $this->db = $db;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_mbi_global_diagnostic', array( $this, 'ajax' ) );
		add_action( 'admin_post_mbi_global_diagnostic', array( $this, 'ajax' ) );
		add_action( 'admin_post_mbi_diagnostic_download', array( $this, 'download' ) );
		foreach ( array( 'add', 'update', 'delete' ) as $operation ) {
			add_filter( $operation . '_post_metadata', array( $this, 'before_' . $operation ), 999, 5 );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) { add_action( $hook, array( $this, 'after_meta' ), 999, 4 ); }
		add_action( 'transition_post_status', array( $this, 'status_change' ), 999, 3 );
		add_action( 'update_option_mbi_settings', array( $this, 'settings_change' ), 999, 3 );
		add_filter( 'pre_http_request', array( $this, 'http_start' ), 999, 3 );
		add_action( 'http_api_debug', array( $this, 'http_result' ), 999, 5 );
	}

	public function menu(): void { add_submenu_page( 'edit.php?post_type=mbi_manga', 'Diagnóstico global', 'Diagnóstico global', 'manage_options', 'mbi-global-diagnostic', array( $this, 'render' ) ); }
	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'mbi-global-diagnostic' ) ) { return; }
		wp_enqueue_style( 'mbi-admin', MBI_URL . 'assets/admin.css', array(), MBI_VERSION );
		wp_enqueue_script( 'mbi-global-diagnostic', MBI_URL . 'assets/global-diagnostic.js', array(), MBI_VERSION, true );
		wp_localize_script( 'mbi-global-diagnostic', 'MBIDiagnostic', array( 'url' => admin_url( 'admin-post.php' ), 'nonce' => wp_create_nonce( 'mbi_global_diagnostic' ) ) );
	}
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=mbi_diagnostic_download' ), 'mbi_diagnostic_download' );
		?>
		<div class="wrap mbi-wrap" id="mbi-global-panel"><h1>Diagnóstico global <small>v<?php echo esc_html( MBI_VERSION ); ?></small></h1>
		<div class="mbi-panel"><h2>Verificar todo o catálogo</h2>
		<p>Um único diagnóstico para todas as obras e todos os capítulos, incluindo rascunhos. Confere os links salvos e compara amostras de imagens de cada servidor com a entrega pelo Worker. Nenhuma publicação ou imagem é alterada.</p>
		<div class="mbi-actions"><button type="button" class="button button-primary" data-diag="start">Executar diagnóstico global</button><button type="button" class="button" data-diag="pause">Pausar diagnóstico</button><button type="button" class="button" data-diag="resume">Continuar diagnóstico</button><button type="button" class="button" data-diag="stop">Parar diagnóstico</button><a class="button button-secondary" href="<?php echo esc_url( $url ); ?>">Baixar relatório JSON</a></div>
		<p>Mantenha esta página aberta durante a execução. Se fechar, poderá continuar depois. São pequenos lotes de inventário e apenas uma conexão HTTP por etapa, sem baixar imagens completas.</p>
		</div><div class="mbi-panel"><h2>Acompanhamento</h2><progress id="mbi-diag-progress" max="100" value="0" style="width:100%"></progress><p id="mbi-diag-status" role="status" aria-live="polite">Aguardando.</p><p id="mbi-diag-counts"></p><table class="widefat striped"><thead><tr><th>Registro</th><th>Resultado</th><th>Detalhe</th></tr></thead><tbody id="mbi-diag-rows"></tbody></table></div>
		<div class="mbi-panel"><h2>Monitor de alterações ativo</h2><p>O relatório registra mudanças futuras nos campos de imagens, no endereço/chave do Worker e no status das publicações, com o componente que executou a alteração. Registra também falhas e lentidão das requisições PHP. O monitor não cria conexões extras nem inicia importações.</p><p>Todos os links são inventariados. Os testes HTTP incluem capas de obras e amostras das páginas por servidor (até 80 amostras no total). Uma amostra disponível não comprova que todas as páginas carregam; o relatório distingue esses resultados.</p><p>O relatório é restrito aos administradores e omite chaves, senhas, cookies e parâmetros privados dos endereços.</p></div></div>
		<?php
	}

	public function state(): array { if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( 'mbi_global_diagnostic', 'options' ); } return (array) get_option( 'mbi_global_diagnostic', array() ); }
	public function ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 ); return; }
		check_ajax_referer( 'mbi_global_diagnostic', 'nonce' );
		try {
			$command = sanitize_key( $_POST['command'] ?? 'status' );
			if ( 'start' === $command ) { $state = $this->start(); }
			elseif ( 'tick' === $command ) { $state = $this->tick( sanitize_text_field( $_POST['run_id'] ?? '' ) ); }
			elseif ( in_array( $command, array( 'pause', 'stop', 'resume' ), true ) ) {
				$state = $this->state();
				if ( 'resume' === $command && ! in_array( $state['stage'] ?? '', array( 'inventory', 'home', 'network' ), true ) ) { throw new RuntimeException( 'Inicie um novo diagnóstico.' ); }
				$state['active'] = 'resume' === $command; $state['paused'] = 'pause' === $command;
				if ( 'stop' === $command ) { $state['stage'] = 'stopped'; }
				update_option( 'mbi_global_diagnostic', $state, false );
			} else { $state = $this->state(); }
			wp_send_json_success( $this->public_state( $state ) );
		} catch ( Throwable $error ) { wp_send_json_error( array( 'message' => $error->getMessage() ), 400 ); }
	}
	public function start(): array {
		global $wpdb;
		if ( ! empty( $this->state()['active'] ) ) { throw new RuntimeException( 'Continue ou pare o diagnóstico atual antes de iniciar outro.' ); }
		$maximum = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN ('mbi_manga','mbi_chapter') AND post_status NOT IN ('trash','auto-draft','inherit')" );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('mbi_manga','mbi_chapter') AND post_status NOT IN ('trash','auto-draft','inherit') AND ID<=%d", $maximum ) );
		$state = array( 'id' => wp_generate_uuid4(), 'active' => true, 'stage' => 'inventory', 'cursor' => 0, 'maximum' => $maximum, 'total' => $total, 'checked' => 0, 'urls' => 0, 'issues' => 0, 'hosts' => array(), 'targets' => array(), 'sample_counts' => array(), 'index' => 0, 'role_index' => 0, 'http_checked' => 0, 'http_failed' => 0, 'rows' => array(), 'started_at' => gmdate( 'c' ), 'config' => $this->safe_settings( $this->importer->settings() ) );
		update_option( 'mbi_global_diagnostic', $state, false );
		update_option( 'mbi_diagnostic_trace_until', time() + 15 * MINUTE_IN_SECONDS, false );
		$this->event( 'scan_start', array( 'run' => $state['id'], 'records' => $total, 'config' => $state['config'] ) );
		return $state;
	}
	public function tick( string $run_id ): array {
		if ( ! MBI_Resource_Guard::begin( 'diagnóstico', true ) ) { return $this->state(); }
		try { return $this->tick_bounded( $run_id ); } finally { MBI_Resource_Guard::end(); }
	}

	private function tick_bounded( string $run_id ): array {
		$state = $this->state();
		if ( empty( $state['active'] ) || ( $state['id'] ?? '' ) !== $run_id ) { return $state; }
		$token = wp_generate_uuid4();
		if ( ! add_option( 'mbi_global_diagnostic_lock', array( 'token' => $token, 'until' => time() + 30 ), '', false ) ) {
			$old = (array) get_option( 'mbi_global_diagnostic_lock', array() );
			if ( (int) ( $old['until'] ?? 0 ) < time() ) { delete_option( 'mbi_global_diagnostic_lock' ); }
			return $state;
		}
		try {
			if ( 'inventory' === $state['stage'] ) { $this->inventory( $state ); }
			elseif ( 'home' === $state['stage'] ) { $this->home( $state ); }
			elseif ( 'network' === $state['stage'] ) { $this->network( $state ); }
			$current = $this->state();
			if ( empty( $current['active'] ) || ( $current['id'] ?? '' ) !== $run_id ) { return $current; }
			$state['updated_at'] = gmdate( 'c' );
			if ( 'done' === $state['stage'] ) { $state['active'] = false; $state['finished_at'] = gmdate( 'c' ); }
			update_option( 'mbi_global_diagnostic', $state, false );
			return $state;
		} finally {
			$lock = (array) get_option( 'mbi_global_diagnostic_lock', array() );
			if ( ( $lock['token'] ?? '' ) === $token ) { delete_option( 'mbi_global_diagnostic_lock' ); }
		}
	}

	private function inventory( array &$state ): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('mbi_manga','mbi_chapter') AND post_status NOT IN ('trash','auto-draft','inherit') AND ID>%d AND ID<=%d ORDER BY ID ASC LIMIT 20", (int) $state['cursor'], (int) $state['maximum'] ) );
		$settings = $this->importer->settings();
		foreach ( $ids as $id ) {
			$id = (int) $id; $cover = 'mbi_manga' === get_post_type( $id );
			$originals = $cover ? array( get_post_meta( $id, '_mbi_cover_original_url', true ) ) : (array) ( get_post_meta( $id, '_mbi_source_image_urls', true ) ?: get_post_meta( $id, '_mbi_chapter_page_urls_original', true ) );
			$delivery = $cover ? array( get_post_meta( $id, '_mbi_cover_source_url', true ) ) : (array) get_post_meta( $id, '_mbi_chapter_page_urls', true );
			$local = $cover ? has_post_thumbnail( $id ) : (bool) get_post_meta( $id, '_mbi_chapter_page_ids', true );
			$issues = array();
			if ( ! array_filter( $originals ) && ! array_filter( $delivery ) && ! $local ) { $issues[] = 'SEM_IMAGENS_SALVAS'; }
			if ( ! $cover && ! $local && count( $originals ) !== count( $delivery ) ) { $issues[] = 'QUANTIDADE_ORIGINAL_ENTREGA_DIFERENTE'; }
			foreach ( range( 0, max( 0, max( count( $originals ), count( $delivery ) ) - 1 ) ) as $i ) {
				$o = (string) ( $originals[ $i ] ?? '' ); $d = (string) ( $delivery[ $i ] ?? '' );
				if ( ! $o && ! $d ) { continue; }
				$state['urls']++;
				if ( ! $o && $d ) { $o = MBI_Image_Proxy::is_proxy_url( $d, $settings ) ? MBI_Image_Proxy::unprotect( $d, $settings, false ) : $d; }
				if ( MBI_Image_Proxy::is_proxy_url( $o, $settings ) ) { $o = MBI_Image_Proxy::unprotect( $o, $settings, false ); $issues[] = 'PROXY_EM_CAMPO_ORIGINAL'; }
				if ( ! $o ) { $issues[] = 'TOKEN_NAO_RECUPERAVEL'; continue; }
				if ( ! preg_match( '~^https?://[^/]+/~i', $o ) ) { $issues[] = 'URL_MALFORMADA'; continue; }
				if ( preg_match( '~^https?://[^/]+/i/[A-Za-z0-9_-]{30,}(?:$|\?)~', $o ) ) { $issues[] = 'PROXY_ANTIGO_OU_ANINHADO'; continue; }
				if ( MBI_Parser::looks_like_placeholder_url( $o ) || MBI_Parser::looks_like_non_content_asset_url( $o ) ) { $issues[] = 'IMAGEM_DE_INTERFACE_OU_PLACEHOLDER'; continue; }
				$host = strtolower( (string) wp_parse_url( $o, PHP_URL_HOST ) );
				if ( count( $state['hosts'] ) < 100 || isset( $state['hosts'][ $host ] ) ) { $state['hosts'][ $host ] = (int) ( $state['hosts'][ $host ] ?? 0 ) + 1; }
				$expected = MBI_Image_Proxy::protect( $o, $settings, false );
				if ( $d && $d !== $expected && ! $local ) { $issues[] = 'ENTREGA_DIFERE_DA_CONFIGURACAO_ATUAL'; }
				$kind = $cover ? 'capa' : 'pagina';
				// Covers get distinct samples per work; chapter samples are by host.
				$group = $cover ? $host . '|capa|' . $id : $host . '|pagina';
				if ( (int) ( $state['sample_counts'][ $group ] ?? 0 ) < ( $cover ? 1 : 2 ) && count( $state['targets'] ) < 80 ) {
					$state['sample_counts'][ $group ] = (int) ( $state['sample_counts'][ $group ] ?? 0 ) + 1;
					$state['targets'][] = array( 'post_id' => $id, 'title' => get_the_title( $id ), 'status' => get_post_status( $id ), 'kind' => $kind, 'origin' => $o, 'delivery' => $d ?: $expected, 'current_delivery' => $expected, 'results' => array() );
				}
			}
			$issues = array_values( array_unique( $issues ) );
			$backups = get_post_meta( $id, '_mbi_media_url_backups', true );
			if ( is_array( $backups ) && $backups ) {
				$history = array();
				foreach ( array_slice( $backups, -3 ) as $backup ) {
					if ( ! is_array( $backup ) ) { continue; }
					$entry = array( 'at' => $backup['at'] ?? '', 'fields' => array() );
					foreach ( (array) ( $backup['values'] ?? array() ) as $key => $value ) { $entry['fields'][ $key ] = $this->value_summary( $value ); }
					$history[] = $entry;
				}
				$this->event( 'saved_media_history', array( 'run' => $state['id'], 'post_id' => $id, 'history' => $history ) );
			}
			if ( $issues ) {
				$state['issues']++;
				$this->row( $state, get_the_title( $id ), 'Links exigem revisão', implode( ', ', $issues ) );
				$this->event( 'inventory_issue', array( 'run' => $state['id'], 'post_id' => $id, 'status' => get_post_status( $id ), 'issues' => $issues ) );
			}
			$state['current'] = get_the_title( $id ); $state['cursor'] = $id; $state['checked']++;
		}
		if ( count( $ids ) < 20 ) { $state['stage'] = 'home'; }
	}

	/** Public HTML may still point to old delivery URLs after a cache/config change. */
	private function home( array &$state ): void {
		$started = microtime( true ); $this->own_request = true;
		try { $response = wp_safe_remote_get( home_url( '/' ), array( 'timeout' => 8, 'redirection' => 2, 'limit_response_size' => 512 * 1024 ) ); }
		finally { $this->own_request = false; }
		$state['home'] = array( 'ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'http' => is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response ), 'error' => is_wp_error( $response ) ? $response->get_error_message() : '', 'headers' => array(), 'covers' => array() );
		if ( ! is_wp_error( $response ) ) {
			foreach ( array( 'content-type', 'cache-control', 'age', 'cf-cache-status', 'x-litespeed-cache', 'x-cache', 'content-security-policy' ) as $header ) { $state['home']['headers'][ $header ] = preg_replace( "/'nonce-[^']+'/", "'nonce-[omitido]'", sanitize_text_field( (string) wp_remote_retrieve_header( $response, $header ) ) ); }
			if ( 200 === $state['home']['http'] && class_exists( 'DOMDocument' ) ) {
				$old = libxml_use_internal_errors( true ); $doc = new DOMDocument();
				$doc->loadHTML( '<?xml encoding="UTF-8">' . (string) wp_remote_retrieve_body( $response ) ); libxml_clear_errors(); libxml_use_internal_errors( $old );
				$xpath = new DOMXPath( $doc ); $seen = array(); $settings = $this->importer->settings();
				foreach ( $xpath->query( '//img[@data-mlo-cover-post]' ) as $image ) {
					$id = (int) $image->getAttribute( 'data-mlo-cover-post' );
					if ( ! $id || isset( $seen[ $id ] ) || 'mbi_manga' !== get_post_type( $id ) ) { continue; }
					$seen[ $id ] = true; $src = html_entity_decode( $image->getAttribute( 'src' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$stored = (string) get_post_meta( $id, '_mbi_cover_source_url', true );
					$original = (string) get_post_meta( $id, '_mbi_cover_original_url', true );
					$expected = $original ? MBI_Image_Proxy::protect( $original, $settings, false ) : $stored;
					$state['home']['covers'][] = array( 'post_id' => $id, 'html_src' => self::safe_url( $src ), 'stored_src' => self::safe_url( $stored ), 'current_src' => self::safe_url( $expected ), 'html_matches_stored' => $src === $stored, 'html_matches_current' => $src === $expected, 'loading' => $image->getAttribute( 'loading' ) );
					// Prioritise the images visitors see, even if the catalogue sample cap was reached.
					if ( $original && preg_match( '~^https?://~i', $src ) ) {
						$state['targets'] = array_values( array_filter( $state['targets'], static fn( $t ) => ! ( $t['post_id'] === $id && 'capa' === $t['kind'] ) ) );
						if ( count( $state['targets'] ) >= 80 ) { array_pop( $state['targets'] ); }
						array_unshift( $state['targets'], array( 'post_id' => $id, 'title' => get_the_title( $id ), 'status' => get_post_status( $id ), 'kind' => 'capa', 'origin' => $original, 'delivery' => $src, 'current_delivery' => $expected, 'from_home_html' => true, 'results' => array() ) );
					}
					if ( count( $seen ) >= 12 ) { break; }
				}
			}
		}
		$this->event( 'public_home', array( 'run' => $state['id'], 'home' => $state['home'] ) );
		$state['stage'] = $state['targets'] ? 'network' : 'done';
	}

	private function network( array &$state ): void {
		$index = (int) $state['index'];
		if ( ! isset( $state['targets'][ $index ] ) ) { $state['stage'] = 'done'; return; }
		$target =& $state['targets'][ $index ];
		$roles = array( 'origin', 'delivery', 'current_delivery' );
		$role = $roles[ (int) $state['role_index'] ]; $url = $target[ $role ];
		$state['current'] = $target['title'] . ' — ' . $target['kind'] . ' — ' . $role;
		// Expose the current item while its bounded external request is running.
		update_option( 'mbi_global_diagnostic', $state, false );
		$cached = null;
		foreach ( $target['results'] as $other_role => $result ) { if ( $target[ $other_role ] === $url ) { $cached = $result; break; } }
		$this->own_request = true;
		try { $result = $cached ?? self::probe( $url ); } finally { $this->own_request = false; }
		$target['results'][ $role ] = $result;
		if ( null === $cached ) { $state['http_checked']++; if ( ! $result['ok'] ) { $state['http_failed']++; } }
		$this->row( $state, $target['title'], $result['ok'] ? 'Amostra disponível' : 'Falha na amostra', $role . ': ' . $result['message'] . ' — ' . $result['ms'] . ' ms' );
		if ( ++$state['role_index'] >= count( $roles ) ) {
			$target['classification'] = self::classify( $target['results'] );
			$this->event( 'http_sample', array( 'run' => $state['id'], 'sample' => $this->redact( $target ) ) );
			$state['role_index'] = 0; $state['index']++;
			if ( $state['index'] >= count( $state['targets'] ) ) { $state['stage'] = 'done'; }
		}
	}
	private function row( array &$state, string $title, string $result, string $detail ): void {
		$state['rows'][] = array( 'title' => $title, 'result' => $result, 'detail' => $detail ); $state['rows'] = array_slice( $state['rows'], -100 );
	}
	public static function classify( array $results ): string {
		$o = $results['origin'] ?? array(); $d = $results['delivery'] ?? array(); $n = $results['current_delivery'] ?? array();
		foreach ( $results as $result ) { if ( ! empty( $result['resolver_failure'] ) ) { return 'FALHA_DNS_NA_HOSPEDAGEM'; } }
		if ( in_array( $o['http'] ?? 0, array( 404, 410 ), true ) ) { return 'ARQUIVO_AUSENTE_NA_ORIGEM'; }
		if ( empty( $o['ok'] ) ) { return 'ORIGEM_NAO_VALIDADA'; }
		if ( empty( $d['ok'] ) && ! empty( $n['ok'] ) ) { return 'LINK_SALVO_DE_ENTREGA_DESATUALIZADO'; }
		if ( empty( $d['ok'] ) ) { return 'ORIGEM_OK_ENTREGA_FALHOU'; }
		return 'AMOSTRA_OK';
	}
	public static function probe( string $url, int $timeout = 6 ): array {
		$start = microtime( true );
		if ( ! preg_match( '~^https?://~i', $url ) ) { return array( 'ok' => false, 'http' => 0, 'ms' => 0, 'message' => 'URL inválida.' ); }
		$response = wp_safe_remote_get( $url, array( 'timeout' => max( 1, min( 6, $timeout ) ), 'redirection' => 2, 'limit_response_size' => 4096, 'headers' => array( 'Accept' => 'image/*', 'Range' => 'bytes=0-4095' ) ) );
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
			return array( 'ok' => false, 'http' => 0, 'ms' => $ms, 'message' => sanitize_text_field( $error ), 'resolver_failure' => (bool) preg_match( '/getaddrinfo|cURL error 6|Could not resolve host/iu', $error ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$image = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( wp_remote_retrieve_body( $response ) ) : false;
		$valid_type = str_starts_with( $type, 'image/' ) || str_starts_with( $type, 'application/octet-stream' );
		$ok = in_array( $code, array( 200, 206 ), true ) && $valid_type && false !== $image;
		$headers = array();
		foreach ( array( 'content-type', 'content-length', 'cache-control', 'age', 'cf-cache-status', 'cf-ray', 'retry-after' ) as $header ) { $headers[ $header ] = sanitize_text_field( (string) wp_remote_retrieve_header( $response, $header ) ); }
		return array( 'ok' => $ok, 'http' => $code, 'ms' => $ms, 'message' => 'HTTP ' . $code . ( $ok ? ' — imagem válida' : ' — imagem não validada' ), 'header_inconclusive' => in_array( $code, array( 200, 206 ), true ) && $valid_type && ! $ok && strlen( (string) wp_remote_retrieve_body( $response ) ) >= 4096, 'headers' => $headers );
	}

	private function public_state( array $state ): array {
		unset( $state['targets'], $state['config'], $state['sample_counts'] );
		$full = $this->state(); $samples = count( (array) ( $full['targets'] ?? array() ) );
		$state['samples'] = $samples;
		$state['percent'] = 'done' === ( $state['stage'] ?? '' ) ? 100 : ( 'inventory' === ( $state['stage'] ?? '' ) ? (int) min( 49, 50 * (int) ( $state['checked'] ?? 0 ) / max( 1, (int) ( $state['total'] ?? 0 ) ) ) : 50 + (int) min( 49, 50 * ( (int) ( $state['index'] ?? 0 ) * 3 + (int) ( $state['role_index'] ?? 0 ) ) / max( 1, $samples * 3 ) ) );
		if ( ! $state || empty( $state['id'] ) ) { $state['percent'] = 0; }
		return $state;
	}
	private function event( string $kind, array $data ): void {
		if ( ++$this->events > 40 ) { return; }
		$this->db->log( 'warning', 'Diagnóstico global: ' . $kind, array( 'diagnostic_event' => $kind, 'data' => $this->redact( $data ) ) );
	}
	public static function safe_url( string $url ): string {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) { return preg_match( '~^https?://~i', $url ) ? '[URL inválida]' : sanitize_text_field( substr( $url, 0, 250 ) ); }
		return ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . ( $p['path'] ?? '/' ) . ( ! empty( $p['query'] ) ? '?[parametros-omitidos]' : '' );
	}
	private function redact( $value ) {
		if ( is_array( $value ) ) {
			$out = array(); foreach ( $value as $key => $item ) {
				if ( preg_match( '/password|secret|cookie|authorization|nonce|image_proxy_key_b64|worker_key/i', (string) $key ) ) { $out[ $key ] = '[omitido]'; }
				else { $out[ $key ] = $this->redact( $item ); }
			} return $out;
		}
		return is_string( $value ) ? preg_replace_callback( '~https?://[^\s"<>]+~i', static fn( $m ) => self::safe_url( $m[0] ), $value ) : $value;
	}
	private function safe_settings( array $settings ): array {
		$key = (string) ( $settings['image_proxy_key_b64'] ?? '' );
		return array( 'source_url' => self::safe_url( (string) ( $settings['source_url'] ?? '' ) ), 'allowed_hosts' => $settings['allowed_hosts'] ?? '', 'proxy_base' => self::safe_url( (string) ( $settings['image_proxy_base_url'] ?? '' ) ), 'proxy_enabled' => (bool) ( $settings['image_proxy_enabled'] ?? false ), 'proxy_ready' => MBI_Image_Proxy::ready( $settings ), 'key_fingerprint' => $key ? substr( hash( 'sha256', $key ), 0, 16 ) : '', 'speed' => $settings['processing_speed'] ?? '' );
	}
	private function value_summary( $value ): array {
		$items = is_array( $value ) ? $value : array( $value ); $hosts = array(); $samples = array();
		foreach ( $items as $item ) {
			if ( is_string( $item ) && preg_match( '~^https?://~', $item ) ) {
				$host = (string) wp_parse_url( $item, PHP_URL_HOST ); $hosts[ $host ] = (int) ( $hosts[ $host ] ?? 0 ) + 1;
				if ( count( $samples ) < 2 ) { $samples[] = self::safe_url( $item ); }
			}
		}
		return array( 'count' => count( array_filter( $items, static fn( $v ) => '' !== $v && null !== $v ) ), 'hash' => hash( 'sha256', serialize( $value ) ), 'hosts' => $hosts, 'samples' => $samples, 'scalar' => is_scalar( $value ) && ! is_string( $value ) ? $value : null );
	}
	private function trace(): array {
		$out = array();
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 ) as $frame ) {
			$file = (string) ( $frame['file'] ?? '' );
			if ( $file && defined( 'WP_CONTENT_DIR' ) && str_starts_with( $file, WP_CONTENT_DIR ) && ! str_ends_with( $file, '/class-mbi-global-diagnostic.php' ) ) {
				$out[] = array( 'file' => substr( $file, strlen( WP_CONTENT_DIR ) + 1 ), 'line' => (int) ( $frame['line'] ?? 0 ), 'call' => ( $frame['class'] ?? '' ) . ( $frame['type'] ?? '' ) . ( $frame['function'] ?? '' ) );
			}
		}
		return array( 'components' => $out, 'cron' => defined( 'DOING_CRON' ) && DOING_CRON, 'ajax' => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(), 'user_id' => get_current_user_id() );
	}
	private function before_meta( $check, int $id, string $key, $value, string $operation ) {
		if ( (int) get_option( 'mbi_diagnostic_trace_until', 0 ) <= time() ) { return $check; }
		if ( ! in_array( $key, self::KEYS, true ) || ! in_array( get_post_type( $id ), array( 'mbi_manga', 'mbi_chapter' ), true ) || $this->events >= 40 ) { return $check; }
		$before = get_post_meta( $id, $key, true );
		if ( 'update' === $operation && $before === $value ) { return $check; }
		$this->pending[ $id . '|' . $key ] = array( 'post_id' => $id, 'key' => $key, 'operation' => $operation, 'before' => $this->value_summary( $before ), 'proposed' => $this->value_summary( $value ), 'caller' => $this->trace() );
		return $check; // Observe only; never short-circuit the metadata API.
	}
	public function before_add( $check, $id, $key, $value, $unique ) { return $this->before_meta( $check, (int) $id, (string) $key, $value, 'add' ); }
	public function before_update( $check, $id, $key, $value, $previous ) { return $this->before_meta( $check, (int) $id, (string) $key, $value, 'update' ); }
	public function before_delete( $check, $id, $key, $value, $all ) { return $this->before_meta( $check, (int) $id, (string) $key, $value, 'delete' ); }
	public function after_meta( $meta_id, $id, $key, $value ): void {
		$lookup = $id . '|' . $key;
		if ( ! isset( $this->pending[ $lookup ] ) ) { return; }
		$data = $this->pending[ $lookup ]; unset( $this->pending[ $lookup ] );
		$data['after'] = $this->value_summary( get_post_meta( (int) $id, (string) $key, true ) );
		$this->event( 'media_field_changed', $data );
	}
	public function status_change( string $new, string $old, object $post ): void {
		if ( (int) get_option( 'mbi_diagnostic_trace_until', 0 ) <= time() ) { return; }
		if ( $old !== $new && in_array( $post->post_type, array( 'mbi_manga', 'mbi_chapter' ), true ) ) { $this->event( 'publication_status_changed', array( 'post_id' => $post->ID, 'before' => $old, 'after' => $new, 'caller' => $this->trace() ) ); }
	}
	public function settings_change( $old, $new, $option ): void {
		$before = $this->safe_settings( (array) $old ); $after = $this->safe_settings( (array) $new );
		if ( $before !== $after ) { $this->event( 'image_settings_changed', array( 'before' => $before, 'after' => $after, 'caller' => $this->trace() ) ); }
	}
	public function http_start( $pre, $args, $url ) {
		if ( (int) get_option( 'mbi_diagnostic_trace_until', 0 ) <= time() ) { return $pre; }
		if ( ! $this->own_request && count( $this->timers ) < 40 && ( str_contains( (string) $url, 'mangalivre' ) || MBI_Image_Proxy::is_proxy_url( (string) $url, $this->importer->settings() ) ) ) { $this->timers[ $url ] = microtime( true ); }
		return $pre;
	}
	public function http_result( $response, $context, $transport, $args, $url ): void {
		if ( ! isset( $this->timers[ $url ] ) || 'response' !== $context ) { return; }
		$ms = (int) round( ( microtime( true ) - $this->timers[ $url ] ) * 1000 ); unset( $this->timers[ $url ] );
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || 0 === $code || $ms > 2000 ) { $this->event( 'outgoing_http', array( 'url' => self::safe_url( $url ), 'http' => $code, 'ms' => $ms, 'error' => is_wp_error( $response ) ? $response->get_error_message() : '', 'caller' => $this->trace() ) ); }
	}
	public function report(): array {
		global $wpdb, $wp_version;
		$theme = wp_get_theme();
		$logs = $wpdb->get_results( "SELECT id,level,message,context,created_at FROM {$wpdb->prefix}mbi_logs ORDER BY id DESC LIMIT 3000", ARRAY_A );
		foreach ( $logs as &$row ) { $row['context'] = json_decode( (string) $row['context'], true ) ?: array(); } unset( $row );
		$report = array( 'format' => 'mbi-global-diagnostic-1', 'generated_utc' => gmdate( 'c' ), 'plugin_version' => MBI_VERSION, 'coverage' => array( 'inventory' => 'Todos os registros existentes no início, exceto lixeira/auto-draft/revisões.', 'http' => 'Até uma capa por obra e duas páginas por host, máximo 80 amostras no total. Não é teste HTTP de todas as imagens.', 'logs' => 'Últimos 3000 eventos. O monitor captura alterações posteriores à instalação; não reconstrói o passado.' ), 'environment' => array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'memory_limit' => ini_get( 'memory_limit' ), 'wp_memory_limit' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '', 'max_execution_time' => ini_get( 'max_execution_time' ), 'curl' => function_exists( 'curl_version' ) ? curl_version() : null, 'openssl' => extension_loaded( 'openssl' ), 'dom' => extension_loaded( 'dom' ), 'theme' => $theme->get( 'Name' ), 'theme_version' => $theme->get( 'Version' ), 'active_plugins' => get_option( 'active_plugins', array() ) ), 'config' => $this->safe_settings( $this->importer->settings() ), 'diagnostic' => $this->state(), 'queue_counts' => $this->db->counts(), 'runtime' => $this->importer->runtime_status(), 'logs' => $logs );
		return $this->redact( $report );
	}
	public function download(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Acesso negado.', '', array( 'response' => 403 ) ); return; }
		check_admin_referer( 'mbi_diagnostic_download' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="manga-diagnostico-global-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $this->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
