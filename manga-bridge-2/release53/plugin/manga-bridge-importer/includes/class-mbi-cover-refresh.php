<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Manual cover-only refresh. One bounded HTTP request per step; no chapter jobs. */
final class MBI_Cover_Refresh {
	private MBI_Importer $importer;
	private MBI_DB $db;
	private const OPTION = 'mbi_cover_refresh';

	public function __construct( MBI_Importer $importer, MBI_DB $db ) {
		$this->importer = $importer; $this->db = $db;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_mbi_cover_refresh', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_mbi_cover_refresh', array( $this, 'ajax' ) );
	}
	public function menu(): void {
		add_submenu_page( 'edit.php?post_type=mbi_manga', 'Recuperação rápida de capas', 'Recuperar capas', 'manage_options', 'mbi-cover-refresh', array( $this, 'render' ) );
	}
	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'mbi-cover-refresh' ) ) { return; }
		wp_enqueue_style( 'mbi-cover-refresh', MBI_URL . 'assets/admin.css', array(), MBI_VERSION );
		wp_enqueue_script( 'mbi-cover-refresh', MBI_URL . 'assets/cover-refresh.js', array(), MBI_VERSION, true );
		wp_localize_script( 'mbi-cover-refresh', 'MBICovers', array( 'url' => admin_url( 'admin-post.php' ), 'nonce' => wp_create_nonce( 'mbi_cover_refresh' ) ) );
	}
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		?>
		<div class="wrap mbi-wrap" id="mbi-cover-refresh"><h1>Recuperação rápida de capas <small>v<?php echo esc_html( MBI_VERSION ); ?></small></h1>
		<div class="mbi-panel"><p>Atualiza as capas de todas as obras publicadas, começando pelos destaques. Relê a página atual da origem e valida a imagem no navegador quando utiliza o Worker, mantendo o Proxy Guard ativo. Não processa capítulos nem altera publicação, texto ou SEO.</p>
		<div class="mbi-actions"><button type="button" class="button button-primary" data-cover-command="start">Recuperar todas as capas agora</button><button type="button" class="button" data-cover-command="pause">Pausar</button><button type="button" class="button" data-cover-command="resume">Continuar</button><button type="button" class="button" data-cover-command="stop">Parar</button></div>
		<p>Mantenha esta página aberta. A validação da capa no navegador tem limite de oito segundos e há somente uma imagem em teste por vez. Uma falha preserva a capa anterior e avança para a próxima obra. Fechar a página interrompe os próximos passos; use Continuar ao voltar.</p></div>
		<div class="mbi-panel"><progress id="mbi-cover-progress" max="100" value="0" style="width:100%"></progress><h2 id="mbi-cover-status" role="status" aria-live="polite">Aguardando.</h2><p id="mbi-cover-counts"></p>
		<table class="widefat striped"><thead><tr><th>Mangá</th><th>Resultado</th><th>Detalhe</th></tr></thead><tbody id="mbi-cover-rows"></tbody></table>
		<p>O histórico completo fica em Mangás → Logs de erros. A rapidez depende da resposta da origem e do serviço de imagens. Nenhuma importação ou diagnóstico de capítulos é necessário para esta recuperação.</p></div></div>
		<?php
	}
	public function ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 ); }
		check_ajax_referer( 'mbi_cover_refresh', 'nonce' );
		try {
			$command = sanitize_key( (string) ( $_POST['command'] ?? 'status' ) );
			if ( 'start' === $command ) { wp_send_json_success( $this->start() ); }
			if ( in_array( $command, array( 'pause', 'stop', 'resume' ), true ) ) { wp_send_json_success( $this->control( $command ) ); }
			if ( in_array( $command, array( 'tick', 'browser_result' ), true ) ) {
				$verification = 'browser_result' === $command ? array(
					'challenge' => sanitize_text_field( (string) ( $_POST['challenge'] ?? '' ) ),
					'ok' => '1' === (string) ( $_POST['ok'] ?? '' ),
					'width' => absint( $_POST['width'] ?? 0 ), 'height' => absint( $_POST['height'] ?? 0 ),
				) : null;
				wp_send_json_success( $this->tick( sanitize_text_field( (string) ( $_POST['run_id'] ?? '' ) ), $verification ) );
			}
			wp_send_json_success( $this->state() );
		} catch ( Throwable $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 400 ); }
	}
	public function state(): array { if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::OPTION, 'options' ); } return (array) get_option( self::OPTION, array() ); }
	public function start(): array {
		if ( ! empty( $this->state()['active'] ) ) { throw new RuntimeException( 'Já existe recuperação em andamento. Pause ou continue a execução atual.' ); }
		$queue = $this->db->counts();
		if ( ! $this->importer->is_paused() && ( ! empty( $queue['pending'] ) || ! empty( $queue['processing'] ) || $this->importer->is_serial_active() ) ) { throw new RuntimeException( 'Pause a importação antes de recuperar capas.' ); }
		if ( ! empty( get_option( 'mbi_media_repair', array() )['active'] ) || ! empty( get_option( 'mbi_global_diagnostic', array() )['active'] ) ) { throw new RuntimeException( 'Pause o diagnóstico/reparo de imagens antes de recuperar capas.' ); }
		$common = array( 'post_type' => 'mbi_manga', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false );
		$featured = get_posts( array_merge( $common, array( 'meta_key' => '_mbi_featured', 'meta_value' => '1', 'orderby' => 'date', 'order' => 'DESC' ) ) );
		$all = get_posts( array_merge( $common, array( 'orderby' => 'date', 'order' => 'DESC' ) ) );
		$ids = array_values( array_unique( array_map( 'intval', array_merge( $featured, $all ) ) ) );
		if ( ! $ids ) { throw new RuntimeException( 'Nenhuma obra publicada encontrada.' ); }
		$state = array( 'id' => wp_generate_uuid4(), 'active' => true, 'paused' => false, 'stage' => 'source', 'ids' => $ids, 'index' => 0, 'total' => count( $ids ), 'checked' => 0, 'repaired' => 0, 'unchanged' => 0, 'failed' => 0, 'rows' => array(), 'current' => 'Iniciando pelos destaques.', 'started_at' => gmdate( 'c' ) );
		update_option( self::OPTION, $state, false ); return $state;
	}
	public function control( string $command ): array {
		$state = $this->state();
		if ( 'resume' === $command ) {
			if ( empty( $state['id'] ) || (int) ( $state['index'] ?? 0 ) >= (int) ( $state['total'] ?? 0 ) || 'stopped' === ( $state['stage'] ?? '' ) ) { throw new RuntimeException( 'Inicie uma nova recuperação.' ); }
			$state['active'] = true; $state['paused'] = false;
		} else {
			$state['active'] = false; $state['paused'] = 'pause' === $command;
			// Fences requests that were already running, even after an immediate resume.
			$state['id'] = wp_generate_uuid4();
			if ( 'browser' === ( $state['stage'] ?? '' ) ) { $state['stage'] = 'image'; unset( $state['browser'] ); }
			if ( 'stop' === $command ) { $state['stage'] = 'stopped'; }
		}
		update_option( self::OPTION, $state, false );
		if ( 'resume' !== $command && ! empty( $state['repaired'] ) ) { $this->flush_page_cache(); }
		return $state;
	}
	public function tick( string $run, ?array $verification = null ): array {
		if ( ! MBI_Resource_Guard::begin( 'reparo de capas', true ) ) { return $this->state(); }
		try { return $this->tick_bounded( $run, $verification ); } finally { MBI_Resource_Guard::end(); }
	}

	private function tick_bounded( string $run, ?array $verification = null ): array {
		$state = $this->state();
		if ( empty( $state['active'] ) || ( $state['id'] ?? '' ) !== $run ) { return $state; }
		$token = wp_generate_uuid4();
		if ( ! add_option( 'mbi_cover_refresh_lock', array( 'token' => $token, 'until' => time() + 30 ), '', false ) ) {
			$old = (array) get_option( 'mbi_cover_refresh_lock', array() );
			if ( (int) ( $old['until'] ?? 0 ) < time() ) { delete_option( 'mbi_cover_refresh_lock' ); }
			return $state;
		}
		try {
			$state = $this->state();
			if ( empty( $state['active'] ) || ( $state['id'] ?? '' ) !== $run ) { return $state; }
			// Results are tied to this exact run/candidate. Late or replayed replies
			// cannot advance the next work or save a URL supplied by the client.
			if ( null !== $verification && ( 'browser' !== ( $state['stage'] ?? '' ) || empty( $state['browser']['challenge'] ) || ! hash_equals( $state['browser']['challenge'], (string) ( $verification['challenge'] ?? '' ) ) ) ) { return $state; }
			$id = (int) ( $state['ids'][ $state['index'] ] ?? 0 );
			if ( ! $id ) { $state['active'] = false; $state['stage'] = 'done'; update_option( self::OPTION, $state, false ); return $state; }
			$state['current'] = get_the_title( $id ) . ( 'source' === $state['stage'] ? ' — buscando a capa atual na origem' : ' — validando a imagem para exibição' );
			update_option( self::OPTION, $state, false );
			try {
				if ( 'mbi_manga' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) { throw new RuntimeException( 'Obra deixou de estar publicada; preservada.' ); }
				if ( 'source' === $state['stage'] ) {
					$url = (string) get_post_meta( $id, '_mbi_source_url', true );
					if ( ! $url ) { throw new RuntimeException( 'Sem endereço da obra na origem.' ); }
					$html = $this->importer->cover_refresh_html( $url );
					$parser = new MBI_Parser(); $parser->load( $html, $url );
					$state['candidates'] = array_slice( $parser->cover_image_candidates( false ), 0, 3 );
					if ( ! $state['candidates'] ) { throw new RuntimeException( 'A página não ofereceu capa específica válida. Capa anterior preservada.' ); }
					$state['candidate_index'] = 0; $state['stage'] = 'image';
					$state['source_url'] = $url;
					$state['before'] = array( 'original' => get_post_meta( $id, '_mbi_cover_original_url', true ), 'delivery' => get_post_meta( $id, '_mbi_cover_source_url', true ) );
				} else {
					$origin = (string) $state['candidates'][ $state['candidate_index'] ];
					if ( ! wp_http_validate_url( $origin ) ) { throw new RuntimeException( 'Endereço de imagem inválido ou não público; capa preservada.' ); }
					$settings = $this->importer->settings();
					$delivery = MBI_Image_Proxy::protect( $origin, $settings, false );
					if ( ! $delivery ) { throw new RuntimeException( 'Falha ao gerar endereço de entrega.' ); }
					if ( 'browser' === $state['stage'] && ( $state['browser']['url'] ?? '' ) !== $delivery ) { throw new RuntimeException( 'Configuração de entrega alterada durante a validação; capa preservada.' ); }
					if ( MBI_Image_Proxy::is_proxy_url( $delivery, $settings ) ) {
						// The public image Worker is intended for browsers. Do not issue
						// server-side HTTP requests or disable Proxy Guard filters.
						if ( 'browser' !== $state['stage'] ) {
							$state['stage'] = 'browser';
							$state['browser'] = array( 'challenge' => wp_generate_uuid4(), 'url' => $delivery );
							$state['current'] = get_the_title( $id ) . ' — validando a capa no navegador';
							if ( $this->running( $run ) ) { update_option( self::OPTION, $state, false ); }
							return $this->state();
						}
						if ( null === $verification ) { return $state; }
						$valid = ! empty( $verification['ok'] ) && (int) ( $verification['width'] ?? 0 ) >= 32 && (int) ( $verification['height'] ?? 0 ) >= 32;
						$probe = array( 'ok' => $valid, 'message' => $valid ? 'Imagem carregada e validada no navegador' : 'A capa não carregou no navegador ou é uma imagem pequena de substituição' );
					} else { $probe = self::probe( $delivery ); }
					if ( ! $this->running( $run ) ) { return $this->state(); }
					if ( ! $probe['ok'] ) {
						$state['stage'] = 'image'; unset( $state['browser'] );
						$state['last_error'] = $probe['message']; $state['candidate_index']++;
						if ( $state['candidate_index'] >= count( $state['candidates'] ) ) { throw new RuntimeException( 'Imagem/entrega não validada: ' . $probe['message'] . '. Capa anterior preservada; revise origem e hosts permitidos no Worker.' ); }
					} else {
						if ( 'publish' !== get_post_status( $id ) ) { throw new RuntimeException( 'Publicação alterada durante a recuperação; preservada.' ); }
						if ( get_post_meta( $id, '_mbi_source_url', true ) !== $state['source_url'] || MBI_Image_Proxy::protect( $origin, $this->importer->settings(), false ) !== $delivery ) { throw new RuntimeException( 'Origem/configuração alterada durante o teste; capa preservada.' ); }
						$now = array( 'original' => get_post_meta( $id, '_mbi_cover_original_url', true ), 'delivery' => get_post_meta( $id, '_mbi_cover_source_url', true ) );
						if ( $now !== $state['before'] ) { throw new RuntimeException( 'Outro processo alterou a capa durante o teste; preservada para evitar sobrescrita.' ); }
						if ( $now['original'] === $origin && $now['delivery'] === $delivery ) { $this->finish_record( $state, $id, 'unchanged', 'Já atualizada', 'Imagem validada; links já correspondem à origem atual.' ); }
						else {
							MBI_Media_Repair::backup( $id );
							update_post_meta( $id, '_mbi_cover_original_url', $origin );
							update_post_meta( $id, '_mbi_cover_source_url', $delivery );
							if ( get_post_meta( $id, '_mbi_cover_original_url', true ) !== $origin || get_post_meta( $id, '_mbi_cover_source_url', true ) !== $delivery ) {
								update_post_meta( $id, '_mbi_cover_original_url', $now['original'] );
								update_post_meta( $id, '_mbi_cover_source_url', $now['delivery'] );
								throw new RuntimeException( 'Banco não confirmou a gravação da capa. Restauração dos links anteriores solicitada; confira o log.' );
							}
							update_post_meta( $id, '_mbi_cover_repair_status', 'repaired' );
							update_post_meta( $id, '_mbi_cover_provenance', 'source_cover' );
							update_post_meta( $id, '_mbi_cover_repair_method', 'fast_source_refresh' );
							update_post_meta( $id, '_mbi_media_repaired_at', current_time( 'mysql' ) );
							clean_post_cache( $id );
							$this->finish_record( $state, $id, 'repaired', 'Capa recuperada', 'Endereço atual validado. Links anteriores guardados em backup.' );
							try { do_action( 'litespeed_purge_post', $id ); do_action( 'litespeed_purge_url', home_url( '/' ) ); } catch ( Throwable $cache_error ) { $this->db->log( 'warning', 'Capa recuperada; limpe o cache da página.', array( 'post_id' => $id, 'error' => $cache_error->getMessage() ) ); }
						}
					}
				}
			} catch ( Throwable $e ) {
				if ( ! $this->running( $run ) ) { return $this->state(); }
				$this->finish_record( $state, $id, 'failed', 'Preservada — não recuperada', $e->getMessage() );
			}
			if ( ! $this->running( $run ) ) { return $this->state(); }
			$state['updated_at'] = gmdate( 'c' );
			if ( $state['index'] >= $state['total'] ) { $state['active'] = false; $state['stage'] = 'done'; }
			$flush = $state['repaired'] && ( 'done' === $state['stage'] || empty( $state['cache_refreshed'] ) );
			if ( $flush ) { $state['cache_refreshed'] = true; }
			update_option( self::OPTION, $state, false );
			if ( $flush ) { $this->flush_page_cache(); }
			return $state;
		} finally {
			$lock = (array) get_option( 'mbi_cover_refresh_lock', array() );
			if ( ( $lock['token'] ?? '' ) === $token ) { delete_option( 'mbi_cover_refresh_lock' ); }
		}
	}
	private function running( string $run ): bool { $s = $this->state(); return ! empty( $s['active'] ) && ( $s['id'] ?? '' ) === $run; }
	private function finish_record( array &$state, int $id, string $counter, string $result, string $detail ): void {
		$state[ $counter ]++; $state['checked']++; $state['index']++; $state['stage'] = 'source';
		$state['rows'][] = array( 'title' => get_the_title( $id ), 'result' => $result, 'detail' => $detail );
		$state['rows'] = array_slice( $state['rows'], -100 );
		unset( $state['candidates'], $state['candidate_index'], $state['before'], $state['source_url'], $state['browser'] );
		$this->db->log( 'failed' === $counter ? 'error' : 'info', 'Recuperação rápida de capas: ' . $result, array( 'post_id' => $id, 'detail' => $detail ) );
	}
	private function flush_page_cache(): void {
		try {
			do_action( 'litespeed_purge_all' );
			if ( function_exists( 'wpo_cache_flush' ) ) { wpo_cache_flush(); }
		} catch ( Throwable $e ) { $this->db->log( 'warning', 'Recuperação de capas: limpeza de cache não concluída.', array( 'error' => $e->getMessage() ) ); }
	}
	public static function probe( string $url ): array {
		$response = wp_safe_remote_get( $url, array( 'timeout' => 6, 'redirection' => 2, 'limit_response_size' => 65536, 'headers' => array( 'Accept' => 'image/*', 'Range' => 'bytes=0-65535' ) ) );
		if ( is_wp_error( $response ) ) { return array( 'ok' => false, 'message' => $response->get_error_message() ); }
		$code = (int) wp_remote_retrieve_response_code( $response ); $body = (string) wp_remote_retrieve_body( $response );
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$image = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $body ) : false;
		$ok = in_array( $code, array( 200, 206 ), true ) && ( str_starts_with( $type, 'image/' ) || str_starts_with( $type, 'application/octet-stream' ) ) && false !== $image;
		return array( 'ok' => $ok, 'message' => 'HTTP ' . $code . ( $ok ? ' — imagem válida' : ' — imagem não validada' ) );
	}
}
