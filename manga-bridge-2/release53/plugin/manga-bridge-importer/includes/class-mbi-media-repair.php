<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Media-only recovery: browser diagnostics or bounded cron delivery checks, without publication changes. */
final class MBI_Known_Source_Unavailable extends RuntimeException {}

final class MBI_Media_Repair {
	private MBI_Importer $importer;
	private MBI_DB $db;
	private bool $manual_read = false;
	private float $background_deadline = 0;
	private int $background_network_count = 0;
	private const MEDIA_KEYS = array( '_mbi_cover_original_url', '_mbi_cover_source_url', '_mbi_source_image_urls', '_mbi_chapter_page_urls_original', '_mbi_chapter_page_urls' );

	public function __construct( MBI_Importer $importer, MBI_DB $db ) {
		$this->importer = $importer;
		$this->db = $db;
		add_action( 'mbi_autonomous_media_tick', array( $this, 'background_tick' ) );
		add_action( 'mbi_autonomous_media_daily', array( $this, 'daily' ) );
		add_action( 'init', array( $this, 'ensure_background' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_mbi_media_action', array( $this, 'ajax' ) );
		add_action( 'admin_post_mbi_media_action', array( $this, 'ajax' ) );
		add_action( 'wp_head', array( $this, 'resource_hint' ), 2 );
	}

	public function ensure_background(): void {
		$s = $this->state();
		if ( ! empty( $s['background'] ) && ! empty( $s['active'] ) ) { $this->schedule_background(); }
		if ( get_option( 'mbi_autonomous_media_enabled', false ) && ! wp_next_scheduled( 'mbi_autonomous_media_daily' ) ) { wp_schedule_single_event( time() + DAY_IN_SECONDS, 'mbi_autonomous_media_daily' ); }
	}
	private function schedule_background(): void {
		$s = $this->state();
		if ( ! empty( $s['active'] ) && ! empty( $s['background'] ) && ! wp_next_scheduled( 'mbi_autonomous_media_tick' ) ) { wp_schedule_single_event( max( time() + 2, (int) ( $s['next_attempt'] ?? 0 ) ), 'mbi_autonomous_media_tick' ); }
	}
	public function start_background( string $chapter_url = '', bool $covers = false ): array {
		if ( ! empty( $this->state()['active'] ) ) { throw new RuntimeException( 'Pause ou pare o reparo existente antes de iniciar o reparo global.' ); }
		// Repair cannot flip global delivery policies; with an enabled Worker
		// all newly generated public URLs must remain protected.
		$s = $this->start( 0, 'repair', true, true, $chapter_url, ! $covers );
		$s['quick'] = true; $s['background'] = true; $s['selective'] = true; $s['force_source'] = false; $s['include_covers'] = $covers;
		update_option( 'mbi_media_repair', $s, false );
		update_option( 'mbi_autonomous_media_enabled', true, false );
		$this->ensure_background(); return $s;
	}
	/** Fixed allowlist from the supplied report; never expands to the catalogue. */
	public function start_known_errors(): array {
		if ( ! empty( $this->state()['active'] ) ) { throw new RuntimeException( 'Pause ou pare a execução atual antes deste lote limitado.' ); }
		$counts = $this->db->counts();
		if ( ! $this->importer->is_paused() && ( ! empty( $counts['pending'] ) || ! empty( $counts['processing'] ) || $this->importer->is_serial_active() ) ) { throw new RuntimeException( 'Pause a importação antes deste reparo.' ); }
		if ( ! empty( get_option( 'mbi_cover_refresh', array() )['active'] ) || ! empty( get_option( 'mbi_global_diagnostic', array() )['active'] ) ) { throw new RuntimeException( 'Pause a manutenção atual antes deste lote.' ); }
		$file = __DIR__ . '/known-errors-20261013.json';
		$manifest = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $manifest ) || count( $manifest ) !== 106 ) { throw new RuntimeException( 'Relatório limitado ausente ou inválido.' ); }
		$entries = array(); $skipped = array(); $parents = array();
		foreach ( $manifest as $entry ) {
			$id = (int) url_to_postid( $entry['local_url'] );
			$parent = $id ? (int) wp_get_post_parent_id( $id ) : 0;
			$valid = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( $entry['local_url'], PHP_URL_HOST ) )
				&& $id && get_post_type( $id ) === 'mbi_chapter' && get_post_status( $id ) === 'publish'
				&& self::same_url( (string) get_post_meta( $id, '_mbi_source_url', true ), $entry['source'] )
				&& self::title_key( get_the_title( $id ) ) === self::title_key( $entry['title'] )
				&& MBI_Parser::recovery_number( (string) get_post_meta( $id, '_mbi_chapter_number', true ) ) === MBI_Parser::recovery_number( $entry['number'] )
				&& get_post_type( $parent ) === 'mbi_manga' && get_post_status( $parent ) === 'publish'
				&& self::same_url( (string) get_post_meta( $parent, '_mbi_source_url', true ), $entry['work_source'] );
			if ( ! $valid ) { $skipped[] = $entry['title']; continue; }
			$entry['parent'] = $parent; $entries[$id] = $entry; $parents[$parent] = true;
		}
		if ( ! $entries ) { throw new RuntimeException( 'Nenhum dos 106 capítulos corresponde ao cadastro publicado atual. Nada alterado.' ); }
		$s = array( 'id' => wp_generate_uuid4(), 'active' => true, 'mode' => 'repair', 'chapters' => true, 'ids' => array_keys( $parents ), 'index' => 0, 'phase' => 'chapter', 'cursor' => 0, 'checked' => 0, 'repaired' => 0, 'failed' => 0, 'total' => count( $entries ), 'rows' => array(), 'verify_all' => true, 'only_chapters' => true, 'background' => true, 'quick' => false, 'selective' => false, 'force_source' => true, 'only_errors' => true, 'error_entries' => $entries, 'error_ids' => array_keys( $entries ), 'skipped_errors' => $skipped, 'quarantined' => 0, 'work_errors' => array() );
		// A selected repair must not schedule a catalogue-wide daily pass afterwards.
		update_option( 'mbi_autonomous_media_enabled', false, false ); wp_clear_scheduled_hook( 'mbi_autonomous_media_daily' );
		update_option( 'mbi_media_daily_enabled', 0, false ); delete_option( 'mbi_media_daily_deferred' ); wp_clear_scheduled_hook( 'mbi_media_daily_scan' ); wp_clear_scheduled_hook( 'mbi_media_daily_continue' );
		$legacy = (array) get_option( 'mbi_media_daily', array() );
		if ( ! empty( $legacy['active'] ) ) { $legacy['active'] = false; $legacy['id'] = wp_generate_uuid4(); $legacy['message'] = 'Pausado para o lote limitado de erros; progresso preservado.'; update_option( 'mbi_media_daily', $legacy, false ); }
		update_option( 'mbi_media_repair', $s, false ); $this->schedule_background(); return $s;
	}
	private static function title_key( string $value ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ) ) );
	}
	private static function same_url( string $a, string $b ): bool {
		return $a !== '' && untrailingslashit( $a ) === untrailingslashit( $b );
	}
	private function known_entry( int $id ): array {
		$s = $this->state(); return ! empty( $s['active'] ) && ! empty( $s['only_errors'] ) ? (array) ( $s['error_entries'][$id] ?? array() ) : array();
	}
	/** Cache terminal work errors for this run, not transient network failures. */
	private function scoped_index( array &$s, int $parent ): array {
		if ( isset( $s['work_errors'][$parent] ) ) { throw new RuntimeException( $s['work_errors'][$parent] ); }
		try { return $this->recovery_index( $parent ); }
		catch ( MBI_Resource_Wait $e ) { throw $e; }
		catch ( Throwable $e ) {
			if ( preg_match( '/HTTP (?:404|410)\b|Título da página/', $e->getMessage() ) ) {
				$s['work_errors'][$parent] = $e->getMessage(); update_option( 'mbi_media_repair', $s, false );
			}
			throw $e;
		}
	}
	/** Hide only a listed chapter after a newly confirmed source failure; preserve all content. */
	private function quarantine_known( int $id, array $p, string $run, string $reason ): void {
		$entry = $this->known_entry( $id );
		if ( ! $entry || ! $this->running( $run ) || get_post_status( $id ) !== 'publish' || (int) wp_get_post_parent_id( $id ) !== $p['parent'] || get_post_meta( $id, '_mbi_source_url', true ) !== $p['source'] || get_post_meta( $id, '_mbi_chapter_number', true ) !== $p['number'] || $this->snapshot( $id ) !== $p['before'] || ! self::same_url( $p['source'], $entry['source'] ) || ! self::same_url( (string) get_post_meta( $p['parent'], '_mbi_source_url', true ), $entry['work_source'] ) || get_post_status( $p['parent'] ) !== 'publish' ) { throw new RuntimeException( 'Cadastro mudou durante a conferência; nenhuma retirada autorizada.' ); }
		$backup = array( 'at' => time(), 'run_id' => $run, 'reason' => $reason, 'previous_status' => 'publish', 'source' => $p['source'], 'media' => $p['before'] );
		update_post_meta( $id, '_mbi_known_error_quarantine', $backup );
		if ( get_post_meta( $id, '_mbi_known_error_quarantine', true ) !== $backup ) { throw new RuntimeException( 'Não foi possível guardar a recuperação; publicação preservada.' ); }
		if ( ! $this->running( $run ) ) { throw new MBI_Resource_Wait( 'Lote parado; publicação preservada.', 3 ); }
		$result = wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ), true );
		if ( is_wp_error( $result ) || get_post_status( $id ) !== 'draft' ) { throw new RuntimeException( 'Não foi possível retirar este capítulo; conferir publicação.' ); }
		clean_post_cache( $id ); do_action( 'litespeed_purge_post', $id );
		$this->db->log( 'warning', 'Erro identificado: capítulo indisponível guardado em rascunho.', array( 'post_id' => $id, 'reason' => $reason, 'run_id' => $run, 'source' => $p['source'] ) );
		// Remove an unavailable work from listings only after no published chapter remains.
		$s = $this->state(); $parent = (int) $p['parent'];
		if ( preg_match( '/HTTP (?:404|410)\b/', (string) ( $s['work_errors'][$parent] ?? '' ) ) ) {
			global $wpdb;
			$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='mbi_chapter' AND post_status='publish' AND post_parent=%d", $parent ) );
			if ( $remaining === 0 && $this->running( $run ) && get_post_status( $parent ) === 'publish' && self::same_url( (string) get_post_meta( $parent, '_mbi_source_url', true ), $entry['work_source'] ) ) {
				$work_backup = array( 'previous_status' => 'publish', 'at' => time(), 'source' => $entry['work_source'], 'run_id' => $run, 'reason' => 'Obra 404/410 e nenhum capítulo publicado restante.' );
				update_post_meta( $parent, '_mbi_known_error_quarantine', $work_backup );
				if ( get_post_meta( $parent, '_mbi_known_error_quarantine', true ) === $work_backup ) {
					$result = wp_update_post( array( 'ID' => $parent, 'post_status' => 'draft' ), true );
					if ( ! is_wp_error( $result ) && get_post_status( $parent ) === 'draft' ) { clean_post_cache( $parent ); do_action( 'litespeed_purge_post', $parent ); $this->db->log( 'warning', 'Obra indisponível guardada em rascunho após conferência dos capítulos.', $work_backup + array( 'post_id' => $parent ) ); }
				}
			}
		}
	}

	public function daily(): void {
		if ( ! get_option( 'mbi_autonomous_media_enabled', false ) ) { return; }
		$s = $this->state();
		try { if ( empty( $s['active'] ) && empty( $s['paused'] ) ) { $this->start_background( '', ! empty( $s['include_covers'] ) ); } }
		catch ( Throwable $e ) { $this->db->log( 'warning', 'Reparo diário adiado.', array( 'error' => $e->getMessage() ) ); }
		$this->ensure_background();
	}
	public function background_tick(): void {
		$s = $this->state();
		if ( empty( $s['active'] ) || empty( $s['background'] ) ) { return; }
		try {
			$php = (int) ini_get( 'max_execution_time' );
			$deadline = microtime( true ) + ( $php > 0 ? max( 1, min( 18, $php - 5 ) ) : 18 );
			$this->background_deadline = $deadline; $this->background_network_count = 0;
			for ( $step = 0; $step < 128 && microtime( true ) < $deadline; $step++ ) {
				$live = $this->state();
				if ( empty( $live['active'] ) || empty( $live['background'] ) || ( $live['id'] ?? '' ) !== $s['id'] || ( $live['next_attempt'] ?? 0 ) > time() || $this->background_network_count >= 24 || $deadline - microtime( true ) < 3 ) { break; }
				$this->tick( (string) $s['id'] );
			}
		}
		catch ( Throwable $e ) { $this->db->log( 'error', 'Reparo em segundo plano: falha de execução.', array( 'error' => $e->getMessage() ) ); }
		finally { $this->background_deadline = 0; $this->schedule_background(); }
	}

	public function menu(): void {
		add_submenu_page( 'edit.php?post_type=mbi_manga', 'Diagnóstico e reparo de imagens', 'Reparar imagens', 'manage_options', 'manga-bridge-media', array( $this, 'render' ) );
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'manga-bridge-media' ) ) { return; }
		wp_enqueue_script( 'mbi-media', MBI_URL . 'assets/media-repair.js', array(), MBI_VERSION, true );
		wp_localize_script( 'mbi-media', 'MBIMedia', array( 'url' => admin_url( 'admin-post.php' ), 'nonce' => wp_create_nonce( 'mbi_media_action' ) ) );
	}

	public function resource_hint(): void {
		$settings = $this->importer->settings();
		if ( ! MBI_Image_Proxy::ready( $settings ) ) { return; }
		$url = MBI_Image_Proxy::base_url( $settings );
		echo '<link rel="preconnect" href="' . esc_url( $url ) . '">' . "\n";
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$works = get_posts( array( 'post_type' => 'mbi_manga', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'update_post_meta_cache' => false, 'update_post_term_cache' => false ) );
		?>
		<div class="wrap mbi-wrap" id="mbi-media-panel">
		<h1>Diagnóstico e reparo de imagens <small>v<?php echo esc_html( MBI_VERSION ); ?></small></h1>
		<div class="mbi-panel" style="border-left:4px solid #2271b1"><h2>Correção limitada aos erros do relatório de 13/10</h2><p>Lista fixa de 106 capítulos: 79 de Overlord, 24 com leitor sem imagens e 3 com título divergente. Só aceita registros que ainda correspondam ao mesmo endereço, número e obra. Consulta a origem atual, valida cada endereço novo e mantém os links anteriores se houver erro de rede. Origem confirmada 404/410 ou leitor confirmado vazio: guarda o capítulo em rascunho e o retira do catálogo público, sem apagar conteúdo. Uma obra 404 só é retirada quando nenhum capítulo publicado resta.</p><button type="button" class="button button-primary" id="mbi-media-known-errors">CORRIGIR SOMENTE OS 106 ERROS DO RELATÓRIO</button><p>Não verifica outros capítulos, não inclui capas e não reimporta obras. Ao iniciar, suspende as rotinas de revisão diária e a revisão antiga para manter somente este lote; o progresso antigo é preservado. Requer cron real ativo; pode fechar esta tela. Se algum registro mudou ou já está em rascunho, será excluído deste lote.</p><div class="mbi-actions"><button type="button" class="button" id="mbi-media-pause">Pausar</button><button type="button" class="button" id="mbi-media-stop">Parar reparo</button><button type="button" class="button" id="mbi-media-resume">Continuar execução existente</button></div></div>
		<div class="mbi-panel"><h2>Checar e corrigir capítulos em segundo plano</h2><p>Triagem rápida: reaproveita validações completas recentes e testa até três imagens por capítulo (primeira, central e última). Só consulta a origem quando a triagem encontra falha, bloqueio ou lista inconsistente. A amostra não garante todas as páginas. Links do Worker com origem válida na amostra são classificados como entrega não verificada, sem alterar o post. Aceita imagens públicas HTTPS em outros domínios, inclusive R2. Não apaga publicações.</p><p><label><input type="checkbox" id="mbi-media-background-covers"> Incluir capas (por padrão, somente capítulos)</label></p><button type="button" class="button button-primary" id="mbi-media-background">TRIAGEM RÁPIDA E REPARO DOS CAPÍTULOS</button><p>O campo URL abaixo permite testar somente um capítulo. Deixe vazio para todos. Pode fechar a página; requer cron real ativo. Até 128 passos leves por rodada, no máximo 24 requisições; triagem de até 16 KB por imagem. O reparo mantém a verificação de cada endereço novo, até 64 KB por imagem, e limites de tempo. O andamento existente é preservado ao atualizar. Resultados da versão anterior não comprovam como estavam as imagens antes da troca. Parar reparo desliga também a rotina diária. Não precisa zerar ou reimportar o catálogo.</p></div>
		<details class="mbi-panel"><summary style="cursor:pointer;font-weight:600;padding:12px">Diagnóstico manual completo (opcional)</summary><div><p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=mbi_manga&page=mbi-media-daily' ) ); ?>">REPARO RÁPIDO DE TODAS AS OBRAS / CHECAGEM DIÁRIA</a></p><h2>1. Reparar capítulo específico ou mangá</h2><p><label for="mbi-media-chapter-url">URL do capítulo no nosso site:</label> <input id="mbi-media-chapter-url" type="url" style="width:100%" placeholder="https://lermangaonline.com.br/capitulo/nome-capitulo-2/"></p><p>Preencha a URL para reparar somente esse capítulo, sem percorrer o catálogo. Ou deixe vazio e selecione um mangá abaixo. O reparo confirma todas as imagens no navegador antes de substituir os links.</p>
		<p>O diagnóstico testa as imagens no navegador, pela mesma entrega usada no site, sem chamadas do WordPress ao Worker. O reparo relê a origem e altera somente links de imagens já existentes. Publicações, capítulos, slugs, datas, categorias e SEO são preservados.</p>
		<label for="mbi-media-manga">Mangá:</label> <select id="mbi-media-manga" style="max-width:100%;width:650px">
		<option value="">Selecione primeiro um mangá</option>
		<?php foreach ( $works as $work ) { printf( '<option value="%d">%s</option>', (int) $work->ID, esc_html( $work->post_title ) ); } ?>
		<option value="0">Todas as obras publicadas</option></select>
		<p><label for="mbi-media-scope">Verificar:</label> <select id="mbi-media-scope"><option value="covers">Somente capas</option><option value="chapters">Somente páginas dos capítulos</option><option value="all">Capas e páginas dos capítulos</option></select></p>
		<p><label for="mbi-media-verification">Validação:</label> <select id="mbi-media-verification"><option value="all">Todas as imagens (verificação completa)</option><option value="sample">Primeira e última página (amostra rápida)</option></select></p>
		<div class="mbi-actions"><button type="button" class="button button-secondary" data-media-mode="diagnose">Diagnosticar carregamento</button><button type="button" class="button button-primary" data-media-mode="repair">Reparar links na origem</button></div>
		<p>Comece com um mangá. Na verificação completa, todas as páginas precisam carregar antes de salvar a nova lista. A amostra rápida verifica somente a primeira e a última e não garante as páginas intermediárias. Se a origem falhar, mantém os links anteriores e segue para o próximo registro.</p>
		<p>O processamento acontece nesta página, um registro por vez. No modo manual, fechar a página interrompe os próximos passos; você pode continuar depois. Não é necessário reimportar ou zerar o catálogo.</p></div></details>
		<div class="mbi-panel"><h2>2. Acompanhamento</h2><button type="button" class="button" id="mbi-media-download">Baixar resultados (lote limitado completo; global: últimos 100, CSV)</button><div id="mbi-media-summary" role="status" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:12px 0"></div><p id="mbi-media-current"></p><p><label>Exibir: <select id="mbi-media-filter"><option value="all">Todos os resultados recentes</option><option value="confirmed">Falhas confirmadas antes do reparo</option><option value="unknown">Inconclusivos / entrega não verificada</option><option value="repaired">Links atualizados</option><option value="preserved">Preservados para revisão</option><option value="quarantined">Retirados do catálogo (rascunho)</option></select></label></p><progress id="mbi-media-progress" max="100" value="0" style="width:100%"></progress><p id="mbi-media-state" role="status" aria-live="polite">Aguardando diagnóstico.</p>
		<table class="widefat striped"><thead><tr><th>Capítulo / origem</th><th>Situação anterior</th><th>Resultado</th><th>Página e evidência</th><th>Detalhes</th></tr></thead><tbody id="mbi-media-results"></tbody></table>
		<p>Na triagem rápida, testamos até três imagens e reaproveitamos provas completas recentes. A confirmação completa de reparos verifica cada endereço novo pelo servidor. O diagnóstico manual opcional usa até duas imagens simultâneas no navegador, com quinze segundos por teste. Links já válidos são aproveitados sem reler a origem. Um erro ou timeout preserva os dados anteriores e segue adiante. Uma resposta rápida em outra amostra não garante que todas as imagens estejam disponíveis.</p></div></div>
		<?php
	}

	public function ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 ); }
		check_ajax_referer( 'mbi_media_action', 'nonce' );
		try {
			$command = sanitize_key( (string) ( $_POST['command'] ?? 'status' ) );
			if ( in_array( $command, array( 'pause', 'stop', 'resume' ), true ) ) { wp_send_json_success( $this->view( $this->control( $command ) ) ); }
			if ( 'start_known_errors' === $command ) { wp_send_json_success( $this->view( $this->start_known_errors() ) ); }
			if ( 'start_background' === $command ) { wp_send_json_success( $this->view( $this->start_background( esc_url_raw( (string) ( $_POST['chapter_url'] ?? '' ) ), '1' === ( $_POST['covers'] ?? '' ) ) ) ); }
			if ( 'start' === $command ) {
				wp_send_json_success( $this->view( $this->start( absint( $_POST['manga_id'] ?? 0 ), sanitize_key( $_POST['mode'] ?? '' ), 'covers' !== ( $_POST['scope'] ?? 'covers' ), 'all' === ( $_POST['verification'] ?? 'all' ), esc_url_raw( (string) ( $_POST['chapter_url'] ?? '' ) ), 'chapters' === ( $_POST['scope'] ?? '' ) ) ) );
			}
			if ( in_array( $command, array( 'tick', 'browser_result' ), true ) ) {
				$v = 'browser_result' === $command ? array( 'challenge' => sanitize_text_field( (string) ( $_POST['challenge'] ?? '' ) ), 'ok' => '1' === (string) ( $_POST['ok'] ?? '' ), 'count' => absint( $_POST['count'] ?? 0 ), 'sizes' => json_decode( (string) wp_unslash( $_POST['sizes'] ?? '[]' ), true ), 'failures' => json_decode( (string) wp_unslash( $_POST['failures'] ?? '[]' ), true ) ) : null;
				wp_send_json_success( $this->view( $this->tick( sanitize_text_field( (string) ( $_POST['run_id'] ?? '' ) ), $v ) ) );
			}
			wp_send_json_success( $this->view( $this->state() ) );
		} catch ( Throwable $error ) { wp_send_json_error( array( 'message' => $error->getMessage() ), 400 ); }
	}

	private function view( array $s ): array {
		return array_intersect_key( $s, array_flip( array( 'id', 'active', 'paused', 'stopped', 'mode', 'target', 'current_record', 'media_stage', 'checked', 'total', 'repaired', 'failed', 'rows', 'validation_cursor', 'browser', 'message', 'next_attempt', 'last_request', 'background', 'selective', 'detected', 'image_cursor', 'current_images', 'last_tick', 'quick', 'stats', 'legacy_checked', 'current_url', 'current_origin', 'before_label', 'evidence', 'only_errors', 'quarantined', 'skipped_errors' ) ) );
	}

	public function state(): array { if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( 'mbi_media_repair', 'options' ); } return (array) get_option( 'mbi_media_repair', array() ); }

	public function start( int $manga_id, string $mode, bool $chapters, bool $verify_all = true, string $chapter_url = '', bool $only_chapters = false ): array {
		if ( ! in_array( $mode, array( 'repair', 'diagnose' ), true ) ) { throw new RuntimeException( 'Operação inválida.' ); }
		if ( ! empty( $this->state()['active'] ) ) { throw new RuntimeException( 'Continue ou pare a execução existente antes de iniciar outra.' ); }
		$counts = $this->db->counts();
		if ( ! $this->importer->is_paused() && ( ! empty( $counts['pending'] ) || ! empty( $counts['processing'] ) || $this->importer->is_serial_active() ) ) {
			throw new RuntimeException( 'Pause a importação em andamento antes de reparar imagens.' );
		}
		if ( ! empty( get_option( 'mbi_cover_refresh', array() )['active'] ) || ! empty( get_option( 'mbi_global_diagnostic', array() )['active'] ) ) { throw new RuntimeException( 'Pause a recuperação de capas/diagnóstico global antes de iniciar este reparo.' ); }
		$chapter_id = 0;
		if ( $chapter_url ) {
			if ( strtolower( (string) wp_parse_url( $chapter_url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) { throw new RuntimeException( 'Informe a URL do capítulo deste site.' ); }
			$chapter_id = (int) url_to_postid( $chapter_url );
			if ( ! $chapter_id || get_post_type( $chapter_id ) !== 'mbi_chapter' || get_post_status( $chapter_id ) !== 'publish' ) { throw new RuntimeException( 'A URL não corresponde a um capítulo publicado neste site.' ); }
			$manga_id = (int) wp_get_post_parent_id( $chapter_id ); $chapters = true; $verify_all = true;
		}
		if ( $manga_id && ( 'mbi_manga' !== get_post_type( $manga_id ) || 'publish' !== get_post_status( $manga_id ) ) ) { throw new RuntimeException( 'Selecione uma obra publicada.' ); }
		$ids = $manga_id ? array( $manga_id ) : get_posts( array( 'post_type' => 'mbi_manga', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
		if ( ! $ids ) { throw new RuntimeException( 'Nenhuma obra publicada encontrada.' ); }
		$state = array( 'id' => wp_generate_uuid4(), 'active' => true, 'mode' => $mode, 'chapters' => $chapters, 'ids' => array_map( 'intval', $ids ), 'index' => 0, 'phase' => 'cover', 'cursor' => 0, 'checked' => 0, 'repaired' => 0, 'failed' => 0, 'total' => count( $ids ), 'rows' => array(), 'verify_all' => $verify_all );
		if ( $only_chapters ) { $state['only_chapters'] = true; $state['phase'] = 'chapter'; }
		if ( $chapter_id ) { $state['only_chapter'] = $chapter_id; $state['phase'] = 'chapter'; $state['total'] = 1; $state['force_source'] = true; }
		elseif ( $chapters ) {
			global $wpdb;
			$in = implode( ',', array_map( 'intval', $ids ) );
			$state['total'] = ( $only_chapters ? 0 : $state['total'] ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='mbi_chapter' AND post_status='publish' AND post_parent IN ($in)" );
		}
		update_option( 'mbi_media_repair', $state, false );
		return $state;
	}

	public function control( string $command ): array {
		$s = $this->state();
		if ( 'resume' === $command ) {
			if ( empty( $s['id'] ) || ! empty( $s['stopped'] ) || $s['checked'] >= $s['total'] ) { throw new RuntimeException( 'Inicie um novo reparo.' ); }
			$s['active'] = true; $s['paused'] = false;
		} else {
			$s['active'] = false; $s['paused'] = 'pause' === $command; $s['stopped'] = 'stop' === $command;
			$s['id'] = wp_generate_uuid4();
		}
		unset( $s['browser'] );
		update_option( 'mbi_media_repair', $s, false );
		if ( 'stop' === $command ) { update_option( 'mbi_autonomous_media_enabled', false, false ); wp_clear_scheduled_hook( 'mbi_autonomous_media_daily' ); }
		wp_clear_scheduled_hook( 'mbi_autonomous_media_tick' );
		if ( 'resume' === $command && ! empty( $s['background'] ) ) { $this->schedule_background(); }
		if ( ! empty( $s['repaired'] ) ) { $this->flush_cache(); }
		return $s;
	}

	public function tick( string $run_id, ?array $verification = null ): array {
		$s = $this->state();
		if ( ! $this->running( $run_id ) || ( $s['next_attempt'] ?? 0 ) > time() ) { return $s; }
		if ( ! MBI_Resource_Guard::begin( 'reparo de imagens', true ) ) { $s['message'] = MBI_Resource_Guard::reason(); $s['next_attempt'] = time() + max( 3, MBI_Resource_Guard::wait_seconds() ); if ( $this->running( $run_id ) ) { update_option( 'mbi_media_repair', $s, false ); } return $s; }
		$this->manual_read = true;
		try { return $this->tick_bounded( $run_id, $verification ); } finally { $this->manual_read = false; MBI_Resource_Guard::end(); if ( ! empty( $this->state()['background'] ) ) { $this->schedule_background(); } }
	}

	private function tick_bounded( string $run_id, ?array $verification = null ): array {
		$s = $this->state();
		if ( ! $this->running( $run_id ) ) { return $s; }
		$lock = wp_generate_uuid4();
		if ( ! add_option( 'mbi_media_repair_lock', array( 'token' => $lock, 'until' => time() + 30 ), '', false ) ) {
			$old = (array) get_option( 'mbi_media_repair_lock', array() );
			if ( (int) ( $old['until'] ?? 0 ) < time() ) { delete_option( 'mbi_media_repair_lock' ); }
			return $s;
		}
		try {
			$s = $this->state();
			if ( ! $this->running( $run_id ) ) { return $s; }
			if ( ! empty( $s['background'] ) && ! isset( $s['quick'] ) ) { $s['quick'] = true; $s['legacy_checked'] = (int) ( $s['checked'] ?? 0 ); }
			if ( null !== $verification && ( empty( $s['browser']['challenge'] ) || ! hash_equals( $s['browser']['challenge'], (string) ( $verification['challenge'] ?? '' ) ) ) ) { return $s; }
			$target = (int) ( $s['target'] ?? 0 );
			if ( ! $target ) {
				if ( null !== $verification ) { return $s; }
				$target = $this->next_record( $s );
				if ( ! $target ) { $s['active'] = false; update_option( 'mbi_media_repair', $s, false ); $this->flush_cache(); return $s; }
				$s['target'] = $target; $s['media_stage'] = 'precheck';
				$s['current_record'] = get_the_title( $target );
				$s['current_url'] = function_exists( 'get_permalink' ) ? get_permalink( $target ) : '';
				$s['current_origin'] = (string) get_post_meta( $target, '_mbi_source_url', true );
			}
			$s['last_tick'] = time();
			update_option( 'mbi_media_repair', $s, false );
			try {
				if ( 'publish' !== get_post_status( $target ) ) { throw new RuntimeException( 'Registro deixou de estar publicado; preservado.' ); }
				if ( 'precheck' === $s['media_stage'] ) {
					$s['proposal'] = $this->prepare_current( $target );
					$s['media_stage'] = ! empty( $s['force_source'] ) && $s['mode'] === 'repair' ? 'fetch' : ( $s['proposal']['urls'] ? 'check_current' : 'fetch' );
					$s['validation_cursor'] = 0;
					if ( ! empty( $s['only_errors'] ) ) { $s['before_label'] = 'Falha confirmada no relatório de 13/10'; $s['evidence'] = $s['error_entries'][$target]['evidence']; }
					if ( ! empty( $s['selective'] ) ) {
						if ( ! empty( $s['quick'] ) && $this->recent_full_proof( $target ) ) {
							$s['before_label'] = 'Validação completa recente reaproveitada';
							$this->finish_stage( $s, $target, 'Validação reaproveitada', 'Mesma lista e mesma entrega, verificação completa há menos de 20 horas; nenhuma requisição externa nesta triagem.' );
							if ( $s['checked'] >= $s['total'] ) { $s['active'] = false; }
							if ( $this->running( $run_id ) ) { update_option( 'mbi_media_repair', $s, false ); } return $s;
						}
						$stored = $s['proposal']['cover'] ? array( $s['proposal']['before']['_mbi_cover_source_url'] ) : (array) $s['proposal']['before']['_mbi_chapter_page_urls'];
						$opaque = false; foreach ( $stored as $url ) { if ( MBI_Image_Proxy::is_proxy_url( (string) $url, $this->importer->settings() ) ) { $opaque = true; break; } }
						$s['current_images'] = count( $stored ); $s['image_cursor'] = 0;
						if ( ! empty( $s['quick'] ) && $s['proposal']['urls'] ) {
							$count = count( $s['proposal']['urls'] );
							$s['triage_indices'] = array_values( array_unique( array( 0, (int) floor( ( $count - 1 ) / 2 ), $count - 1 ) ) );
							$s['triage_cursor'] = 0; $s['triage_opaque'] = $opaque; $s['media_stage'] = 'triage';
							$s['before_label'] = $opaque ? 'Entrega não verificada; triagem na origem' : 'Triagem de endereços exibidos';
							$s['message'] = 'Triagem: primeira, central e última imagem; não é validação completa.';
						} elseif ( $opaque || ! $stored || $stored !== $s['proposal']['delivery'] ) {
							$s['before_label'] = $opaque ? 'Entrega não verificada' : 'Lista ausente/inconsistente';
							$s['media_stage'] = 'fetch'; $s['detected'] = (int) ( $s['detected'] ?? 0 ) + 1;
							$s['message'] = $opaque ? 'Entrega pelo Worker: consultando origem para validar entrega direta.' : 'Lista ausente/inconsistente: consultando origem.';
						} else { $s['media_stage'] = 'check_current'; $s['message'] = 'Checando as imagens atuais antes de consultar a origem.'; }
					}
				} elseif ( 'triage' === $s['media_stage'] ) {
					$p = $s['proposal']; $cursor = (int) $s['triage_cursor']; $index = (int) $s['triage_indices'][$cursor];
					$url = (string) ( ! empty( $s['triage_opaque'] ) ? $p['urls'][$index] : $p['delivery'][$index] );
					$result = self::probe( $url, $this->network_timeout( 4 ), 16384 );
					if ( ! $this->running( $run_id ) ) { return $this->state(); }
					$s['image_cursor'] = $cursor + 1; $s['current_images'] = count( $s['triage_indices'] );
					if ( ! $result['ok'] || $result['width'] < 40 || $result['height'] < 40 ) {
						$confirmed = (bool) preg_match( '/HTTP (?:404|410)\b/', $result['message'] ) || ! empty( $result['non_image'] );
						$s['before_label'] = $confirmed ? ( ! empty( $s['triage_opaque'] ) ? 'Falha confirmada na origem' : 'Falha confirmada no endereço exibido' ) : 'Verificação inconclusiva (rede ou bloqueio)';
						$s['evidence'] = array( 'page' => $index + 1, 'url' => $url, 'message' => $result['message'], 'confirmed' => $confirmed );
						if ( $confirmed || preg_match( '/HTTP (?:401|403)\b/', $result['message'] ) ) { $s['detected'] = (int) ( $s['detected'] ?? 0 ) + 1; $s['media_stage'] = 'fetch'; $s['validation_cursor'] = 0; $s['message'] = 'Consultando origem: ' . $s['before_label']; }
						else { $this->finish_stage( $s, $target, 'Inconclusivo', $result['message'] . '. Triagem interrompida, links preservados; não é falha confirmada de imagem.' ); }
					} else {
						$s['triage_cursor']++;
						if ( $s['triage_cursor'] >= count( $s['triage_indices'] ) ) {
							$s['before_label'] = ! empty( $s['triage_opaque'] ) ? 'Origem sem falha na amostra; entrega não verificada' : 'Sem falha na amostra';
							$label = ! empty( $s['triage_opaque'] ) ? 'Entrega não verificada' : 'Sem falha na amostra';
							$this->finish_stage( $s, $target, $label, count( $s['triage_indices'] ) . ' imagens testadas de ' . count( $p['urls'] ) . '; sem consulta ao HTML e sem alteração. Páginas intermediárias fora da amostra não foram verificadas.' );
						}
					}
				} elseif ( in_array( $s['media_stage'], array( 'fetch', 'fetch_source' ), true ) ) {
					if ( 'diagnose' === $s['mode'] ) { throw new RuntimeException( 'Sem imagem disponível na lista atual.' ); }
					$replacement = ''; $map_loaded = false;
					if ( get_post_type( $target ) === 'mbi_chapter' ) {
						$parent = (int) wp_get_post_parent_id( $target );
						if ( ( $s['work_index']['parent'] ?? 0 ) !== $parent ) {
							try { $s['work_index'] = ! empty( $s['only_errors'] ) ? $this->scoped_index( $s, $parent ) : $this->recovery_index( $parent ); }
							catch ( MBI_Resource_Wait $e ) { throw $e; }
							catch ( Throwable $e ) {
								if ( empty( $s['only_errors'] ) || ! preg_match( '/HTTP (?:404|410)\b/', $e->getMessage() ) ) { throw $e; }
								// Parent 404 is insufficient to hide a chapter. Check each listed chapter itself.
								$s['work_index'] = array( 'parent' => $parent, 'missing' => true, 'chapters' => array() );
							}
							unset( $s['message'], $s['next_attempt'] ); $map_loaded = true; update_option( 'mbi_media_repair', $s, false );
						}
						$key = MBI_Parser::recovery_number( (string) get_post_meta( $target, '_mbi_chapter_number', true ) );
						$matches = (array) ( $s['work_index']['chapters'][$key] ?? array() );
						if ( ! empty( $s['only_errors'] ) && ! empty( $s['work_index']['missing'] ) ) { $matches = array( $s['error_entries'][$target]['source'] ); }
						if ( count( $matches ) !== 1 ) { throw new RuntimeException( 'Nenhuma correspondência única para este capítulo na lista atual da origem. Links preservados.' ); }
						$replacement = (string) $matches[0];
					}
					if ( $map_loaded ) { $s['media_stage'] = 'fetch_source'; update_option( 'mbi_media_repair', $s, false ); return $s; }
					$s['proposal'] = $this->prepare_fresh( $target, $replacement ); unset( $s['message'], $s['next_attempt'] );
					$s['proposal']['replacement'] = $replacement;
					$s['media_stage'] = 'check_fresh'; $s['validation_cursor'] = 0; $s['cover_candidate'] = 0;
				} else {
					$p = $s['proposal'];
					if ( ! empty( $s['background'] ) ) {
						$index = (int) $s['validation_cursor'];
						$url = (string) ( $p['delivery'][$index] ?? '' );
						if ( MBI_Image_Proxy::is_proxy_url( $url, $this->importer->settings() ) ) { throw new RuntimeException( 'Entrega pelo Worker não verificável automaticamente. Origem: ' . ( $p['urls'][$index] ?? '' ) . '. Links preservados.' ); }
						$image_attempt = min( 3, 1 + (int) ( $s['image_retries'][md5( $url )] ?? 0 ) );
						$check = self::probe( $url, $this->network_timeout( array( 1 => 8, 2 => 12, 3 => 15 )[$image_attempt] ), 65536 );
						$s['image_cursor'] = $index + 1; $s['current_images'] = count( $p['delivery'] );
						if ( ! $this->running( $run_id ) ) { return $this->state(); }
						if ( ! $check['ok'] || $check['width'] < 40 || $check['height'] < 40 ) {
							if ( 'check_current' === $s['media_stage'] ) {
								$s['media_stage'] = 'fetch'; $s['validation_cursor'] = 0; $s['detected'] = (int) ( $s['detected'] ?? 0 ) + 1;
								$s['message'] = 'Falha na página ' . ( $index + 1 ) . ': ' . $check['message'] . '. Consultando origem. URL: ' . $url;
							} else {
								$key = md5( $url ); $attempt = (int) ( $s['image_retries'][$key] ?? 0 ) + 1;
								$detail = 'Página ' . ( $index + 1 ) . ': ' . $check['message'] . ' URL: ' . $url;
								$s['repair_error'] = array( 'page' => $index + 1, 'url' => $url, 'message' => $check['message'] );
								if ( $attempt < 3 && preg_match( '/erro de rede|HTTP (?:408|425|429|5[0-9][0-9])\b/i', $check['message'] ) ) {
									$s['image_retries'][$key] = $attempt; $s['next_attempt'] = time() + max( 10, MBI_Resource_Guard::wait_seconds() ); $s['message'] = $detail . ' Tentativa ' . $attempt . '/3.';
								} else { throw new RuntimeException( $detail ); }
							}
						} else {
							$s['validation_cursor']++; unset( $s['next_attempt'], $s['message'], $s['image_retries'] );
							if ( $s['validation_cursor'] >= count( $p['delivery'] ) ) {
								if ( 'check_current' === $s['media_stage'] && ! empty( $s['selective'] ) ) { $this->finish_stage( $s, $target, 'Disponível', count( $p['urls'] ) . ' imagens atuais verificadas; nenhuma consulta HTML ou alteração.' ); }
								else { $changed = $this->save_proposal( $target, $p, $run_id ); $this->finish_stage( $s, $target, $changed ? 'Reparado' : 'Disponível', count( $p['urls'] ) . ' endereços de imagem verificados em segundo plano; links anteriores guardados.' ); }
							}
						}
					} elseif ( ! isset( $s['browser'] ) ) {
						$indices = ! empty( $s['verify_all'] ) || $p['cover'] ? array_keys( $p['delivery'] ) : array_values( array_unique( array( 0, count( $p['delivery'] ) - 1 ) ) );
						$batch = array_slice( $indices, (int) $s['validation_cursor'], 8 );
						$s['browser'] = array( 'challenge' => wp_generate_uuid4(), 'urls' => array_map( static fn( $i ) => $p['delivery'][ $i ], $batch ), 'count' => count( $batch ), 'indices' => $batch, 'total' => count( $indices ) );
					} elseif ( null !== $verification ) {
						$sizes = $verification['sizes'] ?? array();
						$ok = ! empty( $verification['ok'] ) && (int) ( $verification['count'] ?? 0 ) === $s['browser']['count'] && is_array( $sizes ) && count( $sizes ) === $s['browser']['count'];
						foreach ( (array) $sizes as $size ) { if ( ! is_array( $size ) || (int) ( $size['width'] ?? 0 ) < 32 || (int) ( $size['height'] ?? 0 ) < 32 ) { $ok = false; } }
						if ( ! $ok ) {
							$f = is_array( $verification['failures'] ?? null ) ? ( $verification['failures'][0] ?? array() ) : array();
							$index = $s['browser']['indices'][ absint( $f['index'] ?? 0 ) ] ?? 0;
							$reason = in_array( $f['reason'] ?? '', array( 'tempo limite', 'imagem pequena', 'erro de carregamento' ), true ) ? $f['reason'] : 'erro, timeout ou imagem inválida';
							$s['last_image_failure'] = ( $p['cover'] ? 'Capa' : 'Página ' . ( $index + 1 ) ) . ': ' . $reason . '.';
						}
						$total = $s['browser']['total']; $batch_count = $s['browser']['count']; unset( $s['browser'] );
						if ( ! $ok ) {
							if ( 'check_current' === $s['media_stage'] && 'repair' === $s['mode'] ) { $s['media_stage'] = 'fetch'; }
							elseif ( 'check_fresh' === $s['media_stage'] && $p['cover'] && isset( $p['alternatives'][ ++$s['cover_candidate'] ] ) ) {
								$origin = $p['alternatives'][ $s['cover_candidate'] ]; $s['proposal']['urls'] = array( $origin ); $s['proposal']['delivery'] = $this->delivery_urls( array( $origin ) ); $s['validation_cursor'] = 0;
							} else { throw new RuntimeException( ( $s['last_image_failure'] ?? 'Imagem não carregou no navegador.' ) . ' Links anteriores preservados.' ); }
						} else {
							$s['validation_cursor'] += $batch_count;
							if ( $s['validation_cursor'] >= $total ) {
								if ( 'repair' === $s['mode'] ) {
									$changed = $this->save_proposal( $target, $p, $run_id );
									$this->finish_stage( $s, $target, $changed ? 'Reparado' : 'Disponível', count( $p['urls'] ) . ' imagem(ns); ' . $total . ' verificada(s) no navegador. ' . ( $changed ? 'Links anteriores guardados.' : 'Links já corretos.' ) );
								} else { $this->finish_stage( $s, $target, 'Disponível', $total . ' imagem(ns) da lista atual validada(s) no navegador. Nenhuma alteração.' ); }
							}
						}
					}
				}
			} catch ( MBI_Known_Source_Unavailable $e ) {
				if ( ! $this->running( $run_id ) ) { return $this->state(); }
				try { $this->quarantine_known( $target, $s['proposal'], $run_id, $e->getMessage() ); $this->finish_stage( $s, $target, 'Retirado do catálogo — rascunho', $e->getMessage() . ' Conteúdo e imagens anteriores preservados em rascunho.' ); }
				catch ( Throwable $q ) { $this->finish_stage( $s, $target, 'Preservado — verificar', $q->getMessage() ); }
			} catch ( MBI_Resource_Wait $e ) {
				if ( ! $this->running( $run_id ) ) { return $this->state(); }
				$s['message'] = $e->getMessage(); $s['next_attempt'] = time() + max( 3, $e->delay ); update_option( 'mbi_media_repair', $s, false ); return $s;
			} catch ( Throwable $e ) {
				if ( ! $this->running( $run_id ) ) { return $this->state(); }
				$live = $this->state(); $request = (array) ( $live['last_request'] ?? array() );
				if ( $request ) { $s['last_request'] = $request; }
				if ( ! empty( $request['failed'] ) && ! empty( $request['retryable'] ) && str_contains( $e->getMessage(), 'Etapa:' ) && str_contains( $e->getMessage(), (string) $request['url'] ) && ( $request['attempt'] ?? 3 ) < 3 ) {
					$key = md5( $request['url'] ); $s['network_retries'][$key] = (int) $request['attempt'];
					$s['next_attempt'] = time() + max( 3, MBI_Resource_Guard::wait_seconds() );
					$s['message'] = 'Tentativa ' . $request['attempt'] . '/3 falhou em ' . $request['stage'] . '. Nova tentativa controlada; progresso e links preservados. ' . $e->getMessage();
					$this->db->log( 'warning', 'Reparo: consulta será tentada novamente.', array( 'post_id' => $target, 'request' => $request ) );
					update_option( 'mbi_media_repair', $s, false ); return $s;
				}
				$this->finish_stage( $s, $target, 'Preservado — verificar', $e->getMessage() );
			}
			if ( ! $this->running( $run_id ) ) { return $this->state(); }
			if ( $s['checked'] >= $s['total'] ) { $s['active'] = false; }
			update_option( 'mbi_media_repair', $s, false );
			if ( ! $s['active'] || ( ! empty( $s['repaired'] ) && empty( $s['cache_flushed'] ) ) ) { $s['cache_flushed'] = true; update_option( 'mbi_media_repair', $s, false ); $this->flush_cache(); }
			return $s;
		} finally { if ( ( get_option( 'mbi_media_repair_lock', array() )['token'] ?? '' ) === $lock ) { delete_option( 'mbi_media_repair_lock' ); } }
	}

	private function running( string $run ): bool { $s = $this->state(); return ! empty( $s['active'] ) && ( $s['id'] ?? '' ) === $run; }
	private function snapshot( int $id ): array { $v = array(); foreach ( self::MEDIA_KEYS as $k ) { $v[ $k ] = get_post_meta( $id, $k, true ); } return $v; }
	private function delivery_urls( array $urls ): array {
		$out = array(); $settings = $this->importer->settings();
		foreach ( $urls as $url ) { if ( ! MBI_Image_Proxy::valid_browser_url( $url ) ) { throw new RuntimeException( 'Endereço de imagem inválido; preservado.' ); } $out[] = ! empty( $this->state()['active'] ) && ! empty( $this->state()['only_errors'] ) && isset( $this->state()['error_entries'][$this->state()['target'] ?? 0] ) && MBI_Image_Proxy::direct_public_url( $url ) ? $url : MBI_Image_Proxy::protect( $url, $settings, false ); }
		if ( count( array_filter( $out ) ) !== count( $urls ) ) { throw new RuntimeException( 'Não foi possível gerar a entrega.' ); }
		return $out;
	}
	private function prepare_current( int $id ): array {
		$cover = 'mbi_manga' === get_post_type( $id ); $before = $this->snapshot( $id ); $settings = $this->importer->settings();
		$raw = $cover ? array( $before['_mbi_cover_original_url'] ?: $before['_mbi_cover_source_url'] ) : (array) ( $before['_mbi_source_image_urls'] ?: $before['_mbi_chapter_page_urls_original'] ?: $before['_mbi_chapter_page_urls'] );
		$urls = array();
		foreach ( $raw as $url ) {
			$url = (string) $url; if ( MBI_Image_Proxy::is_proxy_url( $url, $settings ) ) { $url = MBI_Image_Proxy::unprotect( $url, $settings, false ); }
			$url = MBI_Parser::normalize_media_host( $url );
			if ( ! MBI_Image_Proxy::valid_browser_url( $url ) || MBI_Parser::looks_like_placeholder_url( $url ) || MBI_Parser::looks_like_non_content_asset_url( $url ) ) { $urls = array(); break; }
			$urls[] = $url;
		}
		$expected = $cover ? 1 : max( count( $raw ), count( (array) $before['_mbi_chapter_page_urls'] ), (int) get_post_meta( $id, '_mbi_expected_pages', true ) );
		if ( ! $cover && count( $urls ) < $expected ) { $urls = array(); }
		return array( 'cover' => $cover, 'before' => $before, 'source' => get_post_meta( $id, '_mbi_source_url', true ), 'number' => get_post_meta( $id, '_mbi_chapter_number', true ), 'parent' => (int) wp_get_post_parent_id( $id ), 'expected' => $expected, 'expected_before' => get_post_meta( $id, '_mbi_expected_pages', true ), 'urls' => $urls, 'delivery' => $this->delivery_urls( $urls ) );
	}
	private function prepare_fresh( int $id, string $replacement = '' ): array {
		$p = $this->prepare_current( $id );
		if ( ! $p['source'] ) { throw new RuntimeException( 'Falta a URL original da obra/capítulo.' ); }
		$read_source = $replacement ?: (string) $p['source'];
		try { $html = $this->read_origin_html( $read_source, 4194304, $p['cover'] ? 'página da obra/capa' : 'página do capítulo' ); }
		catch ( MBI_Resource_Wait $e ) { throw $e; }
		catch ( Throwable $e ) {
			if ( $this->known_entry( $id ) && self::same_url( $read_source, $p['source'] ) && ! empty( $this->state()['work_index']['missing'] ) && preg_match( '/HTTP (?:404|410)\b/', $e->getMessage() ) ) { throw new MBI_Known_Source_Unavailable( 'Obra e capítulo conferidos na origem: HTTP 404/410.' ); }
			throw $e;
		}
		$parser = new MBI_Parser(); $parser->load( $html, $read_source );
		if ( $p['cover'] ) {
			$p['alternatives'] = array_slice( $parser->cover_image_candidates( false ), 0, 3 ); $urls = array_slice( $p['alternatives'], 0, 1 );
		} else {
			$data = $parser->parse_chapter( $read_source, false, true ); $urls = $data['image_urls'];
			$number = (string) $p['number'];
			if ( $number && $data['number'] && MBI_Parser::recovery_number( $number ) !== MBI_Parser::recovery_number( (string) $data['number'] ) ) { throw new RuntimeException( 'Número do capítulo na origem não corresponde ao registro; preservado.' ); }
		}
		if ( ! $urls && ( $entry = $this->known_entry( $id ) ) && $parser->confirmed_empty_reader( $entry['work_source'], $read_source ) ) { throw new MBI_Known_Source_Unavailable( 'Leitor da origem confirmado vazio para este capítulo e esta obra.' ); }
		if ( ! $urls ) { throw new RuntimeException( $p['cover'] ? 'A origem não possui capa válida (placeholder ou ausente); capa anterior preservada.' : 'A origem não ofereceu páginas de leitura; links anteriores preservados.' ); }
		$complete = $p['cover'] ? array() : $parser->complete_reader_pages();
		$limit = (int) $this->importer->settings()['max_images_chapter'];
		if ( count( $urls ) > 5000 || ( count( $urls ) > $limit && ( ! $complete || $complete !== $urls ) ) ) { throw new RuntimeException( 'Lista acima do limite sem comprovação de todas as páginas numeradas; links preservados.' ); }
		if ( ! $p['cover'] ) {
			$complete = $parser->complete_reader_pages();
			// The current numbered reader is authoritative. Historical counts may be polluted.
			if ( $parser->has_numbered_reader() && ( ! $complete || $complete !== $urls ) ) {
				throw new RuntimeException( 'Lista numerada da origem incompleta ou inconsistente; links anteriores preservados.' );
			}
			if ( count( $urls ) < $p['expected'] && ( ! $complete || $complete !== $urls ) ) {
				throw new RuntimeException( 'Resposta menor sem comprovação de todas as páginas da origem; links anteriores preservados.' );
			}
			if ( $complete && $complete === $urls && count( $urls ) !== (int) get_post_meta( $id, '_mbi_expected_pages', true ) ) {
				$p['reconciled_page_count'] = true;
			}
		}
		$p['urls'] = $urls; $p['delivery'] = $this->delivery_urls( $urls ); return $p;
	}
	/** One HTML per record. Changed lists use boundary-image validation, not a full image scan. */
	public function refresh_automatic( int $id, callable $allowed, bool $verify_images = true, string $replacement = '', bool $verify_delivery = false ): bool {
		if ( 'publish' !== get_post_status( $id ) || ! in_array( get_post_type( $id ), array( 'mbi_manga', 'mbi_chapter' ), true ) ) { throw new RuntimeException( 'Registro não publicado; preservado.' ); }
		if ( $verify_delivery && get_post_type( $id ) === 'mbi_chapter' ) { return $this->refresh_verified( $id, $allowed, $replacement ); }
		$p = $this->prepare_fresh( $id, $replacement );
		$values = $p['cover'] ? array( '_mbi_cover_original_url' => $p['urls'][0], '_mbi_cover_source_url' => $p['delivery'][0] ) : array( '_mbi_source_image_urls' => $p['urls'], '_mbi_chapter_page_urls_original' => $p['urls'], '_mbi_chapter_page_urls' => $p['delivery'] );
		if ( $replacement && $replacement !== $p['source'] ) {
			if ( $p['cover'] ) { throw new RuntimeException( 'Troca de origem da obra exige revisão manual.' ); }
			$p['before']['_mbi_source_url'] = $p['source'];
			$p['before']['_mbi_source_key'] = get_post_meta( $id, '_mbi_source_key', true );
			$values['_mbi_source_url'] = $replacement;
			$values['_mbi_source_key'] = md5( 'https://' . preg_replace( '/^www\./i', '', strtolower( (string) wp_parse_url( $replacement, PHP_URL_HOST ) ) ) . trailingslashit( (string) wp_parse_url( $replacement, PHP_URL_PATH ) ) );
		}

		if ( ! empty( $p['reconciled_page_count'] ) ) { $p['before']['_mbi_expected_pages'] = $p['expected_before']; $values['_mbi_expected_pages'] = count( $p['urls'] ); }
		$changed = false; foreach ( $values as $k => $v ) { if ( $p['before'][$k] !== $v ) { $changed = true; } }
		$indices = $verify_images ? ( ( $changed || $verify_delivery ) ? array_unique( array( 0, count( $p['urls'] ) - 1 ) ) : array( 0 ) ) : array();
		$seen_checks = array();
		foreach ( $indices as $index ) {
			if ( ! $allowed() ) { throw new RuntimeException( 'Reparo interrompido; links preservados.' ); }
			// Internal maintenance must never call the public Worker URL from WordPress.
			$check_url = $p['urls'][$index];
            if ( isset( $seen_checks[$check_url] ) ) { continue; } $seen_checks[$check_url] = true;
			$result = self::probe( $check_url, $verify_delivery ? 4 : 6, $verify_delivery ? 65536 : 4096 );
			if ( ! $result['ok'] || ( $result['width'] ?? 0 ) < 40 || ( $result['height'] ?? 0 ) < 40 ) {
                throw new RuntimeException( 'Imagem da origem não validada: ' . $result['message'] );
            }
		}
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $id, 'post_meta' ); wp_cache_delete( $id, 'posts' ); if ( $p['parent'] ) { wp_cache_delete( $p['parent'], 'post_meta' ); wp_cache_delete( $p['parent'], 'posts' ); } }
		if ( ! $allowed() || 'publish' !== get_post_status( $id ) || get_post_meta( $id, '_mbi_source_url', true ) !== $p['source'] || get_post_meta( $id, '_mbi_chapter_number', true ) !== $p['number'] || (int) wp_get_post_parent_id( $id ) !== $p['parent'] || get_post_meta( $id, '_mbi_origin_withdrawn', true ) || get_post_meta( $id, '_mbi_root_absent', true ) || ( ! $p['cover'] && ( get_post_status( $p['parent'] ) !== 'publish' || get_post_meta( $p['parent'], '_mbi_origin_withdrawn', true ) ) ) || $this->snapshot( $id ) !== array_intersect_key( $p['before'], array_flip( self::MEDIA_KEYS ) ) || $this->delivery_urls( $p['urls'] ) !== $p['delivery'] ) { throw new RuntimeException( 'Registro/configuração alterados ou execução parada; preservado.' ); }
		if ( ! $changed ) { return false; }
		if ( ! empty( $p['reconciled_page_count'] ) && get_post_meta( $id, '_mbi_expected_pages', true ) !== $p['before']['_mbi_expected_pages'] ) { throw new RuntimeException( 'Contagem editada durante o reparo; preservado.' ); }
		self::backup( $id );
		if ( ! empty( $p['reconciled_page_count'] ) ) { update_post_meta( $id, '_mbi_media_previous_expected_pages', $p['before']['_mbi_expected_pages'] ); }
		if ( $replacement && $replacement !== $p['source'] ) { update_post_meta( $id, '_mbi_media_previous_source_url', $p['source'] ); }
		foreach ( $values as $k => $v ) { update_post_meta( $id, $k, function_exists( 'wp_slash' ) ? wp_slash( $v ) : $v ); }
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $id, 'post_meta' ); }
		foreach ( $values as $k => $v ) { if ( is_array( $v ) ? get_post_meta( $id, $k, true ) !== $v : (string) get_post_meta( $id, $k, true ) !== (string) $v ) { foreach ( $values as $old => $_ ) { update_post_meta( $id, $old, function_exists( 'wp_slash' ) ? wp_slash( $p['before'][$old] ) : $p['before'][$old] ); } throw new RuntimeException( 'Falha de gravação em ' . $k . '; links restaurados.' ); } }
		update_post_meta( $id, '_mbi_media_repaired_at', current_time( 'mysql' ) );
		clean_post_cache( $id );
		try { do_action( 'litespeed_purge_post', $id ); } catch ( Throwable $e ) { $this->db->log( 'warning', 'Reparo rápido: limpe o cache do registro.', array( 'post_id' => $id ) ); }
		return true;
	}

	/** Stage replacement URLs; commit only after every delivered image address passes. */
	private function refresh_verified( int $id, callable $allowed, string $replacement ): bool {
		$c = (array) get_post_meta( $id, '_mbi_repair_candidate', true );
		if ( ! $c || ! empty( $c['failed'] ) || ( $c['created'] ?? 0 ) < time() - DAY_IN_SECONDS || ( $c['base'] ?? '' ) !== MBI_Chapter_Integrity::snapshot( $id ) ) {
			$p = $this->prepare_fresh( $id, $replacement );
			$c = array( 'created' => time(), 'base' => MBI_Chapter_Integrity::snapshot( $id ), 'cursor' => 0, 'urls' => $p['urls'], 'delivery' => $p['delivery'], 'proposal' => $p, 'replacement' => $replacement );
			if ( ! $allowed() ) { throw new RuntimeException( 'Reparo parado; dados preservados.' ); }
			update_post_meta( $id, '_mbi_repair_candidate', wp_slash( $c ) );
		}
		$safe = function() use ( $id, $allowed, $c ): bool {
			$current_snapshot = MBI_Chapter_Integrity::snapshot( $id );
			return $allowed() && get_post_status( $id ) === 'publish' && get_post_status( (int) wp_get_post_parent_id( $id ) ) === 'publish'
				&& ! get_post_meta( $id, '_mbi_origin_withdrawn', true ) && ! get_post_meta( $id, '_mbi_root_absent', true )
				&& ! get_post_meta( (int) wp_get_post_parent_id( $id ), '_mbi_origin_withdrawn', true ) && $current_snapshot === $c['base'];
		};
		$save = function( array $v ) use ( $id, $safe ): void { if ( ! $safe() ) { throw new RuntimeException( 'Reparo parado ou capítulo alterado; links preservados.' ); } update_post_meta( $id, '_mbi_repair_candidate', wp_slash( $v ) ); };
		// For maintenance, validate originals; do not assert successful Worker delivery.
		if ( ! MBI_Chapter_Integrity::check( $id, $c, $safe, $save, true ) ) {
			throw new MBI_Resource_Wait( 'Validando página ' . $c['cursor'] . '/' . count( $c['urls'] ) . ' de ' . get_the_title( $id ) . '; links anteriores preservados até terminar.', 2 );
		}
		if ( ! $safe() ) { throw new RuntimeException( 'Reparo parado ou capítulo alterado; links preservados.' ); }
		$p = $c['proposal']; $values = array( '_mbi_source_image_urls' => $c['urls'], '_mbi_chapter_page_urls_original' => $c['urls'], '_mbi_chapter_page_urls' => $c['delivery'], '_mbi_expected_pages' => count( $c['urls'] ) );
		if ( ! empty( $c['replacement'] ) ) { $values['_mbi_source_url'] = $c['replacement']; $values['_mbi_source_key'] = md5( 'https://' . preg_replace( '/^www\./i', '', strtolower( (string) wp_parse_url( $c['replacement'], PHP_URL_HOST ) ) ) . trailingslashit( (string) wp_parse_url( $c['replacement'], PHP_URL_PATH ) ) ); }
		$before = array(); $changed = false;
		foreach ( $values as $key => $value ) { $before[$key] = get_post_meta( $id, $key, true ); if ( is_array( $value ) ? $before[$key] !== $value : (string) $before[$key] !== (string) $value ) { $changed = true; } }
		self::backup( $id );
		if ( $changed ) {
			foreach ( $values as $key => $value ) { update_post_meta( $id, $key, wp_slash( $value ) ); }
			foreach ( $values as $key => $value ) { if ( is_array( $value ) ? get_post_meta( $id, $key, true ) !== $value : (string) get_post_meta( $id, $key, true ) !== (string) $value ) { foreach ( $before as $old => $value ) { update_post_meta( $id, $old, wp_slash( $value ) ); } throw new RuntimeException( 'Gravação não confirmada; links anteriores restaurados.' ); } }
		}
		// Origin checks cannot generate a receipt claiming all Worker URLs load.
		if ( $changed ) {
			delete_post_meta( $id, '_mbi_delivery_receipt' );
			update_post_meta( $id, '_mbi_delivery_health', 'browser_check_pending' );
		}
		update_post_meta( $id, '_mbi_repair_origin_verified_at', time() );
		delete_post_meta( $id, '_mbi_repair_candidate' );
		update_post_meta( $id, '_mbi_media_repaired_at', current_time( 'mysql' ) ); clean_post_cache( $id ); do_action( 'litespeed_purge_post', $id );
		return $changed;
	}

	/** Bounded validation of the addresses actually displayed. Checks boundary samples, not every image. */
    public function validate_delivery_sample( int $id, callable $allowed ): bool {
        $p = $this->prepare_current( $id );
        if ( ! $p['urls'] || count( $p['urls'] ) !== $p['expected'] ) { return false; }
        $stored = $p['cover'] ? array( $p['before']['_mbi_cover_source_url'] ) : (array) $p['before']['_mbi_chapter_page_urls'];
        if ( $stored !== $p['delivery'] ) { return false; }
        $seen_checks = array();
        foreach ( array_unique( array( 0, count( $p['delivery'] ) - 1 ) ) as $index ) {
            if ( ! $allowed() ) { throw new RuntimeException( 'Validação interrompida.' ); }
            // The browser-facing Worker URL is not reachable through server-side GET.
            $source = $p['urls'][$index];
            if ( isset( $seen_checks[$source] ) ) { continue; } $seen_checks[$source] = true;
            $result = self::probe( $source, 4, 65536 );
            if ( ! $result['ok'] || ( $result['width'] ?? 0 ) < 40 || ( $result['height'] ?? 0 ) < 40 ) { return false; }
        }
        return $allowed() && $this->snapshot( $id ) === $p['before'];
    }
    /** Middle pages in bounded batches. Boundary addresses were validated by the closing step. */
    public function full_delivery_pending( int $id ): array {
        $p = $this->prepare_current( $id );
        $origins = array_values( array_unique( $p['urls'] ) );
        $count = count( $origins );
        $boundary = $count ? array_unique( array( $origins[0], $origins[$count - 1] ) ) : array();
        $items = array();
        foreach ( $origins as $url ) { if ( ! in_array( $url, $boundary, true ) ) { $items[] = array( 'origin' => $url ); } }
        return array( 'post_id' => $id, 'total' => $count, 'boundary' => count( $boundary ), 'items' => $items, 'cursor' => 0, 'snapshot' => hash( 'sha256', serialize( $this->snapshot( $id ) ) ), 'source' => $p['source'], 'parent' => $p['parent'], 'number' => $p['number'] );
    }
    public function validate_delivery_batch( int $id, array &$pending, callable $allowed ): bool {
        for($n=0;$n<2&&isset($pending['items'][$pending['cursor']]);$n++){
            if(!$allowed()||get_post_status($id)!=='publish'||(int)wp_get_post_parent_id($id)!==$pending['parent']||get_post_meta($id,'_mbi_chapter_number',true)!==$pending['number']||get_post_meta($id,'_mbi_source_url',true)!==$pending['source']||hash('sha256',serialize($this->snapshot($id)))!==$pending['snapshot'])throw new RuntimeException('Registro alterado ou execução parada durante a validação; preservado.');
            $item = $pending['items'][$pending['cursor']];
            $r = self::probe( $item['origin'], 4, 65536 );
            if(!$allowed())throw new RuntimeException('Validação interrompida.');
            if(!$r['ok']||($r['width']??0)<40||($r['height']??0)<40){
                throw new RuntimeException('Imagem intermediária não validada: '.$r['message'].'. URL: '.$item['origin']);
            }
            $pending['cursor']++;
        }
        if ( !$allowed() || get_post_status($id)!=='publish' || hash('sha256',serialize($this->snapshot($id)))!==$pending['snapshot'] ) { throw new RuntimeException('Estado alterado antes de concluir a validação; preservado.'); }
        // These are origin-side checks only; a browser must check Worker delivery.
        if ( !isset( $pending['items'][$pending['cursor']] ) ) { update_post_meta( $id, '_mbi_repair_origin_verified_at', time() ); }
        return !isset($pending['items'][$pending['cursor']]);
    }
	/** Fetch the exact parent page once; no catalog-wide search or guessed URLs. */
	private function proof_hash( int $id ): string {
		return hash( 'sha256', serialize( array( $this->snapshot( $id ), get_post_meta( $id, '_mbi_source_url', true ), get_post_meta( $id, '_mbi_chapter_number', true ), wp_get_post_parent_id( $id ), get_post_meta( $id, '_mbi_expected_pages', true ), $this->prepare_current( $id )['delivery'] ) ) );
	}
	private function recent_full_proof( int $id ): bool {
		if ( get_post_meta( $id, '_mbi_delivery_error', true ) || get_post_meta( $id, '_mbi_delivery_health', true ) === 'failed' ) { return false; }
		$proof = (array) get_post_meta( $id, '_mbi_media_full_proof', true );
		$r = (array) get_post_meta( $id, '_mbi_delivery_receipt', true );
		$error = (array) get_post_meta( $id, '_mbi_media_last_error', true );
		if ( ! empty( $error['at'] ) && (int) $error['at'] >= max( (int) ( $proof['at'] ?? 0 ), (int) ( $r['at'] ?? 0 ) ) ) { return false; }
		if ( ! empty( $proof['at'] ) && (int) $proof['at'] > time() - 20 * HOUR_IN_SECONDS && ( $proof['hash'] ?? '' ) === $this->proof_hash( $id ) ) { return true; }
		if ( get_post_type( $id ) !== 'mbi_chapter' ) { return false; }
		$r = (array) get_post_meta( $id, '_mbi_delivery_receipt', true );
		return (int) ( $r['at'] ?? 0 ) > time() - 20 * HOUR_IN_SECONDS && in_array( $r['coverage'] ?? '', array( 'server_image_addresses', 'browser_all_images', 'server_origin_signed_proxy' ), true ) && MBI_Chapter_Integrity::valid( $id );
	}

	private function network_timeout( int $requested ): int {
		if ( ! $this->background_deadline ) { return $requested; }
		if ( $this->background_network_count >= 24 ) { throw new MBI_Resource_Wait( 'Limite de rede atingido; continua na próxima rodada.', 2 ); }
		$remaining = (int) floor( $this->background_deadline - microtime( true ) - 1 );
		if ( $remaining < 3 ) { throw new MBI_Resource_Wait( 'Rodada concluída para liberar recursos; continua no próximo ciclo.', 2 ); }
		$this->background_network_count++; return min( $requested, $remaining );
	}

	private function read_origin_html( string $url, int $max_bytes, string $stage ): string {
		if ( ! $this->manual_read ) { return $this->importer->cover_refresh_html( $url, $max_bytes ); }
		$s = $this->state(); $run = (string) ( $s['id'] ?? '' ); $attempt = min( 3, 1 + (int) ( $s['network_retries'][md5( $url )] ?? 0 ) );
		$timeout = array( 1 => 8, 2 => 12, 3 => 15 )[$attempt];
		$started = microtime( true ); $request = array( 'url' => $url, 'stage' => $stage, 'attempt' => $attempt, 'requested_timeout' => $timeout, 'at' => gmdate( 'c' ) );
		$html = ''; $error = null;
		try { $timeout = $this->network_timeout( $timeout );
			$html = $this->importer->cover_refresh_html( $url, $max_bytes, $timeout ); }
		catch ( MBI_Resource_Wait $e ) { throw $e; }
		catch ( Throwable $e ) { $error = $e; }
		$request['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		$request['bytes'] = strlen( $html ); $request['failed'] = $error !== null;
		$request['error'] = $error ? $error->getMessage() : ''; $request['retryable'] = $error && (bool) preg_match( '/cURL error (?:6|7|28)\b|timed? ?out|timeout|HTTP (?:408|429|5[0-9]{2})\b/iu', $request['error'] );
		$live = $this->state(); if ( ! $this->running( $run ) ) { throw new MBI_Resource_Wait( 'Reparo parado; resposta descartada.', 3 ); }
		$live['last_request'] = $request; update_option( 'mbi_media_repair', $live, false );
		if ( $error ) { throw new RuntimeException( 'Etapa: ' . $stage . '; URL: ' . $url . '; tentativa ' . $attempt . '/3; limite solicitado ' . $timeout . 's; duração ' . $request['elapsed_ms'] . 'ms; ' . $request['error'] . '. Links anteriores preservados.' ); }
		return $html;
	}
	public function recovery_index( int $parent ): array {
		$url = (string) get_post_meta( $parent, '_mbi_source_url', true );
		if ( ! $url ) { throw new RuntimeException( 'Obra sem endereço de origem; exige revisão.' ); }
		$html = $this->read_origin_html( $url, 8388608, 'lista de capítulos do mangá' );
		$limit = trim( (string) ini_get( 'memory_limit' ) ); $unit = strtolower( substr( $limit, -1 ) ); $budget = (int) $limit;
		if ( $unit === 'g' ) { $budget *= 1073741824; } elseif ( $unit === 'm' ) { $budget *= 1048576; } elseif ( $unit === 'k' ) { $budget *= 1024; }
		if ( $budget > 0 && memory_get_usage( true ) + strlen( $html ) * 12 + 16777216 > $budget ) { throw new RuntimeException( 'Memória disponível insuficiente para ler a lista grande com segurança; preservado.' ); }
		$parser = new MBI_Parser(); $parser->load( $html, $url );
		$known = (string) get_post_meta( $parent, '_mbi_original_title', true );
		$entry = $this->known_entry( (int) ( $this->state()['target'] ?? 0 ) );
		if ( $entry && (int) $entry['parent'] === $parent && self::same_url( $url, $entry['work_source'] ) ) { $known = $entry['work_title']; }
		if ( $known !== '' ) {
			$data = $parser->parse_manga( $url );
			$normalize = static function( string $title ): string { $title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ); $title = preg_replace( '/^(?:ler|leia)\s+|\s+online(?:\s+gr[aá]tis)?(?:\s+em\s+portugu[eê]s)?$/iu', '', trim( $title ) ); return sanitize_title( $title ); };
			if ( ! $normalize( $known ) || $normalize( $known ) !== $normalize( $entry ? $parser->work_heading() : (string) $data['title'] ) ) { throw new RuntimeException( 'Título da página da obra não corresponde à origem cadastrada; revisão necessária.' ); }
		}
		return array( 'parent' => $parent, 'source' => $url, 'chapters' => $parser->recovery_chapter_index( $url ) );
	}

	private function save_proposal( int $id, array $p, string $run ): bool {
		if ( ! $this->running( $run ) || 'publish' !== get_post_status( $id ) || (int) wp_get_post_parent_id( $id ) !== $p['parent'] || get_post_meta( $id, '_mbi_chapter_number', true ) !== $p['number'] || get_post_meta( $id, '_mbi_origin_withdrawn', true ) || get_post_meta( $id, '_mbi_root_absent', true ) || ( ! $p['cover'] && ( get_post_status( $p['parent'] ) !== 'publish' || get_post_meta( $p['parent'], '_mbi_origin_withdrawn', true ) ) ) || get_post_meta( $id, '_mbi_source_url', true ) !== $p['source'] || $this->snapshot( $id ) !== $p['before'] || $this->delivery_urls( $p['urls'] ) !== $p['delivery'] ) { throw new RuntimeException( 'Estado, configuração ou links alterados durante a validação; preservados.' ); }
		$values = $p['cover'] ? array( '_mbi_cover_original_url' => $p['urls'][0], '_mbi_cover_source_url' => $p['delivery'][0] ) : array( '_mbi_source_image_urls' => $p['urls'], '_mbi_chapter_page_urls_original' => $p['urls'], '_mbi_chapter_page_urls' => $p['delivery'] );
		if ( ! empty( $p['replacement'] ) && $p['replacement'] !== $p['source'] ) {
			$p['before']['_mbi_source_url'] = $p['source']; $p['before']['_mbi_source_key'] = get_post_meta( $id, '_mbi_source_key', true );
			$values['_mbi_source_url'] = $p['replacement'];
			$values['_mbi_source_key'] = md5( 'https://' . preg_replace( '/^www\./i', '', strtolower( (string) wp_parse_url( $p['replacement'], PHP_URL_HOST ) ) ) . trailingslashit( (string) wp_parse_url( $p['replacement'], PHP_URL_PATH ) ) );
		}
		if ( ! empty( $p['reconciled_page_count'] ) ) {
			if ( get_post_meta( $id, '_mbi_expected_pages', true ) !== $p['expected_before'] ) { throw new RuntimeException( 'Contagem editada durante o reparo; preservado.' ); }
			$p['before']['_mbi_expected_pages'] = $p['expected_before']; $values['_mbi_expected_pages'] = count( $p['urls'] );
		}
		$changed = false; foreach ( $values as $k => $v ) { if ( $p['before'][ $k ] !== $v ) { $changed = true; } } if ( ! $changed ) { if ( ! $p['cover'] && ! empty( $this->state()['verify_all'] ) ) { MBI_Chapter_Integrity::verified( $id, ! empty( $this->state()['background'] ) ? 'server_image_addresses' : 'browser_all_images' ); } return false; }
		if ( ! empty( $p['reconciled_page_count'] ) ) { update_post_meta( $id, '_mbi_media_previous_expected_pages', $p['expected_before'] ); }
		self::backup( $id );
		foreach ( $values as $k => $v ) { update_post_meta( $id, $k, function_exists( 'wp_slash' ) ? wp_slash( $v ) : $v ); }
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $id, 'post_meta' ); }
		foreach ( $values as $k => $v ) { if ( is_array( $v ) ? get_post_meta( $id, $k, true ) !== $v : (string) get_post_meta( $id, $k, true ) !== (string) $v ) { foreach ( $values as $old_key => $_ ) { update_post_meta( $id, $old_key, function_exists( 'wp_slash' ) ? wp_slash( $p['before'][ $old_key ] ) : $p['before'][ $old_key ] ); } throw new RuntimeException( 'Gravação não confirmada. Links anteriores restaurados; confira o log.' ); } }
		if ( ! $p['cover'] && ! empty( $this->state()['verify_all'] ) ) { MBI_Chapter_Integrity::verified( $id, ! empty( $this->state()['background'] ) ? 'server_image_addresses' : 'browser_all_images' ); }
		update_post_meta( $id, '_mbi_media_repaired_at', current_time( 'mysql' ) );
		clean_post_cache( $id );
		try { do_action( 'litespeed_purge_post', $id ); } catch ( Throwable $e ) { $this->db->log( 'warning', 'Limpe o cache do registro reparado.', array( 'post_id' => $id ) ); }
		return true;
	}
	private function finish_stage( array &$s, int $id, string $status, string $detail ): void {
		if ( 'Reparado' === $status ) { $s['repaired']++; }
		if ( 'Retirado do catálogo — rascunho' === $status ) { $s['quarantined'] = (int) ( $s['quarantined'] ?? 0 ) + 1; }
		if ( 'Preservado — verificar' === $status ) { $s['failed']++; }
		$before = (string) ( $s['before_label'] ?? 'Não registrado pela versão anterior' );
		if ( in_array( $status, array( 'Reparado', 'Disponível' ), true ) && ( ! empty( $s['background'] ) || ! empty( $s['verify_all'] ) ) ) {
			update_post_meta( $id, '_mbi_media_full_proof', array( 'at' => time(), 'hash' => $this->proof_hash( $id ) ) );
			delete_post_meta( $id, '_mbi_media_last_error' );
		}
		// Each category is counted separately; candidate != confirmed failure.
		$stats = (array) ( $s['stats'] ?? array() );
		$key = array( 'Validação reaproveitada' => 'cached', 'Sem falha na amostra' => 'sample', 'Entrega não verificada' => 'unknown', 'Inconclusivo' => 'inconclusive', 'Preservado — verificar' => 'preserved', 'Retirado do catálogo — rascunho' => 'quarantined' )[$status] ?? '';
		if ( $key ) { $stats[$key] = (int) ( $stats[$key] ?? 0 ) + 1; }
		if ( ! empty( $s['evidence']['confirmed'] ) ) { $stats['confirmed'] = (int) ( $stats['confirmed'] ?? 0 ) + 1; }
		if ( 'Reparado' === $status ) { $key = ! empty( $s['evidence']['confirmed'] ) ? 'fixed_confirmed' : 'updated_unconfirmed'; $stats[$key] = (int) ( $stats[$key] ?? 0 ) + 1; }
		$s['stats'] = $stats;
		$row = array( 'post_id' => $id, 'record' => get_the_title( $id ), 'url' => function_exists( 'get_permalink' ) ? get_permalink( $id ) : '', 'origin' => (string) ( $s['proposal']['replacement'] ?? get_post_meta( $id, '_mbi_source_url', true ) ), 'before' => $before, 'evidence' => (array) ( $s['evidence'] ?? array() ), 'repair_error' => (array) ( $s['repair_error'] ?? array() ), 'status' => $status, 'detail' => $detail );
		$s['checked']++; $s['rows'][] = $row; $s['rows'] = array_slice( $s['rows'], ! empty( $s['only_errors'] ) ? -106 : -100 );
		
		if ( 'cover' === $s['phase'] ) { $s['phase'] = 'chapter'; } else { $s['cursor'] = $id; }
		if ( 'Preservado — verificar' === $status ) { update_post_meta( $id, '_mbi_media_last_error', array( 'at' => time(), 'detail' => $detail ) ); }
		$this->db->log( 'Preservado — verificar' === $status ? 'error' : 'info', 'Reparo de imagens: ' . $status, array( 'post_id' => $id, 'detail' => $detail, 'row' => $row, 'run_id' => $s['id'] ) );
		unset( $s['next_attempt'], $s['message'], $s['network_retries'], $s['last_request'], $s['image_retries'], $s['image_cursor'], $s['current_images'] );
		unset( $s['target'], $s['proposal'], $s['browser'], $s['validation_cursor'], $s['media_stage'], $s['cover_candidate'], $s['last_image_failure'], $s['before_label'], $s['evidence'], $s['repair_error'], $s['triage_indices'], $s['triage_cursor'], $s['triage_opaque'] );
	}
	private function flush_cache(): void {
		try { do_action( 'litespeed_purge_all' ); if ( function_exists( 'wpo_cache_flush' ) ) { wpo_cache_flush(); } } catch ( Throwable $e ) { $this->db->log( 'warning', 'Reparo concluído; limpeza de cache pendente.', array( 'error' => $e->getMessage() ) ); }
	}

	private function next_record( array &$state ): int {
		global $wpdb;
		if ( ! empty( $state['only_errors'] ) ) { return (int) ( $state['error_ids'][(int) $state['checked']] ?? 0 ); }
		if ( ! empty( $state['only_chapter'] ) ) { return $state['checked'] ? 0 : (int) $state['only_chapter']; }
		while ( $state['index'] < count( $state['ids'] ) ) {
			$id = (int) $state['ids'][ $state['index'] ];
			if ( 'cover' === $state['phase'] ) { return $id; }
			if ( $state['chapters'] ) {
				$chapter = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='mbi_chapter' AND post_status='publish' AND post_parent=%d AND ID>%d ORDER BY ID ASC LIMIT 1", $id, (int) $state['cursor'] ) );
				if ( $chapter ) { return $chapter; }
			}
			$state['index']++; $state['phase'] = ! empty( $state['only_chapters'] ) ? 'chapter' : 'cover'; $state['cursor'] = 0;
		}
		return 0;
	}

	/** Bounded GET: validates actual image bytes, including origins which reject HEAD. */
	public static function probe( string $url, int $timeout = 6, int $max_bytes = 4096 ): array {
		$max_bytes = min( 65536, max( 4096, $max_bytes ) );
		$start = microtime( true );
		$response = wp_safe_remote_get( $url, array( 'timeout' => min( 15, max( 1, $timeout ) ), 'redirection' => 3, 'limit_response_size' => $max_bytes, 'headers' => array( 'Accept' => 'image/*', 'Referer' => home_url( '/' ), 'Range' => 'bytes=0-' . ( $max_bytes - 1 ) ) ) );
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $response ) ) { return array( 'ok' => false, 'message' => 'Tempo/erro de rede: ' . $response->get_error_message(), 'ms' => $ms ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$image = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $body ) : false;
		$ok = in_array( $code, array( 200, 206 ), true ) && ( str_starts_with( $type, 'image/' ) || str_starts_with( $type, 'application/octet-stream' ) ) && false !== $image;
		return array( 'ok' => $ok, 'code' => $code, 'content_type' => $type, 'bytes' => strlen( $body ), 'non_image' => in_array( $code, array( 200, 206 ), true ) && ! $image && (bool) preg_match( '/^\s*(?:<!doctype\s+html|<html\b|<\?xml|\{)/i', $body ), 'message' => 'HTTP ' . $code . ( $ok ? ' — imagem válida' : ' — imagem não validada' ), 'ms' => $ms, 'width' => $image ? (int) $image[0] : 0, 'height' => $image ? (int) $image[1] : 0 );
	}

	public static function backup( int $id ): void {
		$data = array();
		foreach ( self::MEDIA_KEYS as $key ) { $data[ $key ] = get_post_meta( $id, $key, true ); }
		if ( ! array_filter( $data ) ) { return; }
		$history = get_post_meta( $id, '_mbi_media_url_backups', true );
		$history = is_array( $history ) ? array_values( array_filter( $history, static fn( $entry ) => is_array( $entry ) && isset( $entry['values'] ) ) ) : array();
		if ( $history && ( end( $history )['values'] ?? array() ) === $data ) { return; }
		$history[] = array( 'at' => current_time( 'mysql' ), 'values' => $data );
		update_post_meta( $id, '_mbi_media_url_backups', array_slice( $history, -3 ) );
	}

	/** Legacy callers must use the staged browser verification before any write. */
	public function repair_record( int $id, string $run_id ): array {
		throw new RuntimeException( 'Use Mangás → Reparar imagens: os links só são gravados depois da validação no navegador.' );
	}
}
