<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MBI_Admin {
	private MBI_DB $db;
	private MBI_Importer $importer;

	public function __construct( MBI_DB $db, MBI_Importer $importer ) {
		$this->db       = $db;
		$this->importer = $importer;

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'featured_notice' ) );
		add_action( 'admin_post_mbi_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_mbi_start_full', array( $this, 'start_full' ) );
		add_action( 'admin_post_mbi_start_30', array( $this, 'start_30' ) ); // compatibilidade
		add_action( 'admin_post_mbi_start_amount', array( $this, 'start_amount' ) );
		add_action( 'admin_post_mbi_resume_quantity52', array( $this, 'resume_quantity52' ) );
		add_action( 'admin_post_mbi_start_url', array( $this, 'start_url' ) );
		add_action( 'admin_post_mbi_history_backfill', array( $this, 'history_backfill' ) );
		add_action( 'admin_post_mbi_cancel_catalog_discovery', array( $this, 'cancel_catalog_discovery' ) );
		add_action( 'admin_post_mbi_toggle_featured', array( $this, 'toggle_featured' ) );
		add_action( 'admin_post_mbi_check_updates', array( $this, 'check_updates' ) );
		add_action( 'admin_post_mbi_apply_updates', array( $this, 'apply_updates' ) );
		add_action( 'admin_post_mbi_stop_all', array( $this, 'stop_all' ) );
		add_action( 'admin_post_mbi_skip_current_manga', array( $this, 'skip_current_manga' ) );
		add_action( 'admin_post_mbi_download_error_log', array( $this, 'download_error_log' ) );
		add_action( 'admin_post_mbi_validate_drafts', array( $this, 'validate_drafts' ) );
		add_action( 'admin_post_mbi_run_now', array( $this, 'run_now' ) );
		add_action( 'admin_post_mbi_force_retry_now', array( $this, 'force_retry_now' ) );
		add_action( 'admin_post_mbi_recover_queue', array( $this, 'recover_queue' ) );
		add_action( 'admin_post_mbi_hard_reset_repair', array( $this, 'hard_reset_repair' ) );
		add_action( 'admin_post_mbi_retry_failed', array( $this, 'retry_failed' ) );
		add_action( 'admin_post_mbi_retry_manual_network', array( $this, 'retry_manual_network' ) );
		add_action( 'admin_post_mbi_pause', array( $this, 'pause' ) );
		add_action( 'admin_post_mbi_resume', array( $this, 'resume' ) );
		add_action( 'admin_post_mbi_cancel_pending', array( $this, 'cancel_pending' ) );
		add_action( 'admin_post_mbi_clear_completed', array( $this, 'clear_completed' ) );
		add_action( 'admin_post_mbi_test_connection', array( $this, 'test_connection' ) );
		add_action( 'admin_post_mbi_cleanup_legacy_media', array( $this, 'cleanup_legacy_media' ) );
		add_action( 'admin_post_mbi_cleanup_duplicates', array( $this, 'cleanup_duplicates' ) );
		add_action( 'admin_post_mbi_revalidate_suspicious', array( $this, 'revalidate_suspicious' ) );
		add_action( 'admin_post_mbi_start_proxy_migration', array( $this, 'start_proxy_migration' ) );
		add_action( 'admin_post_mbi_test_image_proxy', array( $this, 'test_image_proxy' ) );
		add_action( 'admin_post_mbi_repair_invalid_covers', array( $this, 'repair_invalid_covers' ) );
		add_action( 'admin_post_mbi_repair_invalid_chapter_pages', array( $this, 'repair_invalid_chapter_pages' ) );
		add_action( 'admin_post_mbi_catalog_restore_start', array( $this, 'catalog_restore_start' ) );
		add_action( 'admin_post_mbi_catalog_restore_tick', array( $this, 'catalog_restore_tick_fallback' ) );
		add_action( 'wp_ajax_mbi_catalog_restore_tick', array( $this, 'ajax_catalog_restore_tick' ) );
		add_action( 'wp_ajax_mbi_progress', array( $this, 'ajax_progress' ) );
		add_action( 'wp_ajax_mbi_update_progress', array( $this, 'ajax_update_progress' ) );
		add_action( 'wp_ajax_mbi_start_update_scan', array( $this, 'ajax_start_update_scan' ) );
		add_action( 'wp_ajax_mbi_update_scan_tick', array( $this, 'ajax_update_scan_tick' ) );
		// v1.6.19: o detector usa admin-post.php como transporte principal.
		// Alguns WAFs/hospedagens interceptam admin-ajax.php e devolvem HTML, quebrando response.json().
		add_action( 'admin_post_mbi_start_update_scan', array( $this, 'ajax_start_update_scan' ) );
		add_action( 'admin_post_mbi_update_scan_tick', array( $this, 'ajax_update_scan_tick' ) );
		add_action( 'wp_ajax_mbi_prepare_manual_update_tick', array( $this, 'ajax_prepare_manual_update_tick' ) );
		add_action( 'admin_post_mbi_prepare_manual_update_tick', array( $this, 'ajax_prepare_manual_update_tick' ) );
		add_action( 'wp_ajax_mbi_accelerate', array( $this, 'ajax_accelerate' ) );
		add_action( 'wp_ajax_mbi_cleanup_media_tick', array( $this, 'ajax_cleanup_media_tick' ) );
		add_action( 'wp_ajax_mbi_prepare_proxy_test', array( $this, 'ajax_prepare_proxy_test' ) );
		add_action( 'wp_ajax_mbi_prepare_proxy_origin_audit', array( $this, 'ajax_prepare_proxy_origin_audit' ) );
		add_action( 'wp_ajax_mbi_proxy_migration_tick', array( $this, 'ajax_proxy_migration_tick' ) );
		add_filter( 'manage_mbi_manga_posts_columns', array( $this, 'manga_columns' ) );
		add_action( 'manage_mbi_manga_posts_custom_column', array( $this, 'manga_column' ), 10, 2 );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=mbi_manga',
			'Mangá Bridge Importer',
			'Importador',
			'manage_options',
			'manga-bridge-importer',
			array( $this, 'render' )
		);
		add_submenu_page(
			'edit.php?post_type=mbi_manga',
			'Novos capítulos / Atualizações detectadas',
			'Novos capítulos',
			'manage_options',
			'manga-bridge-updates',
			array( $this, 'render_updates' )
		);
		add_submenu_page(
			'edit.php?post_type=mbi_manga',
			'Logs de erros do importador',
			'Logs de erros',
			'manage_options',
			'manga-bridge-errors',
			array( $this, 'render_error_logs' )
		);
		add_submenu_page(
			'edit.php?post_type=mbi_manga',
			'Recuperar catálogo publicado',
			'Recuperar catálogo',
			'manage_options',
			'manga-bridge-recovery',
			array( $this, 'render_catalog_recovery' )
		);
		add_submenu_page(
			'edit.php?post_type=mbi_manga',
			'Categorias de Mangás',
			'Categorias',
			'manage_categories',
			'edit-tags.php?taxonomy=category&post_type=mbi_manga'
		);
	}

	public function assets( string $hook ): void {
		$is_plugin_page = str_contains( $hook, 'manga-bridge-' );
		$is_manga_list  = 'edit.php' === $hook && 'mbi_manga' === sanitize_key( (string) ( $_GET['post_type'] ?? '' ) );
		if ( ! $is_plugin_page && ! $is_manga_list ) {
			return;
		}
		wp_enqueue_style( 'mbi-admin', MBI_URL . 'assets/admin.css', array(), MBI_VERSION );
		if ( $is_manga_list ) {
			return;
		}
		wp_enqueue_script( 'mbi-admin', MBI_URL . 'assets/admin.js', array(), MBI_VERSION, true );
		if ( str_contains( $hook, 'manga-bridge-recovery' ) ) {
			wp_enqueue_script( 'mbi-recovery', MBI_URL . 'assets/recovery.js', array(), MBI_VERSION, true );
			wp_localize_script( 'mbi-recovery', 'MBIRecovery', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'mbi_catalog_restore_tick' ),
			) );
		}

		// v1.6.12: NUNCA prepara diagnóstico de imagens durante a abertura do Importador.
		// A v1.6.10/1.6.11 fazia leituras de catálogo/post_meta no admin_enqueue_scripts;
		// em hospedagens com catálogo grande isso podia consumir o request do painel e
		// resultar em HTTP 500 antes do HTML ser entregue. Diagnósticos agora são
		// estritamente sob demanda, somente após clique do administrador.
		$proxy_test_prepared  = array();
		$proxy_audit_prepared = array();

		wp_localize_script(
			'mbi-admin',
			'MBIProgress',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mbi_progress' ),
				'workerNonce' => wp_create_nonce( 'mbi_accelerate' ),
				'autoAccelerate' => ( ( $this->importer->is_serial_active() || ! empty( $this->importer->manual_update_progress()['active'] ) || ! empty( $this->importer->catalog_discovery_status()['active'] ) ) && ! $this->importer->is_paused() ) ? 1 : 0,
				'cleanupNonce' => wp_create_nonce( 'mbi_cleanup_media_tick' ),
				'cleanupActive' => ! empty( $this->importer->legacy_media_cleanup_status()['active'] ) ? 1 : 0,
				'proxyTestNonce' => wp_create_nonce( 'mbi_prepare_proxy_test' ),
				'proxyAuditNonce' => wp_create_nonce( 'mbi_prepare_proxy_origin_audit' ),
				'proxyTestPrepared' => $proxy_test_prepared,
				'proxyAuditPrepared' => $proxy_audit_prepared,
				'proxyMigrationNonce' => wp_create_nonce( 'mbi_proxy_migration_tick' ),
				'proxyMigrationActive' => ! empty( $this->importer->proxy_migration_status()['active'] ) ? 1 : 0,
				'updateNonce' => wp_create_nonce( 'mbi_update_progress' ),
				'manualActive' => ! empty( $this->importer->manual_update_progress()['active'] ) ? 1 : 0,
				'manualPreparing' => ! empty( $this->importer->manual_update_progress()['preparing'] ) ? 1 : 0,
				'manualPrepareNonce' => wp_create_nonce( 'mbi_prepare_manual_update_tick' ),
				'scanStartNonce' => wp_create_nonce( 'mbi_start_update_scan' ),
				'scanTickNonce' => wp_create_nonce( 'mbi_update_scan_tick' ),
				'scanActive' => ! empty( $this->importer->update_scan_progress()['active'] ) ? 1 : 0,
				'adminPostUrl' => admin_url( 'admin-post.php' ),
				'actionNonce' => wp_create_nonce( 'mbi_admin_action' ),
			)
		);
	}

	public function featured_notice(): void {
		$state = sanitize_key( (string) ( $_GET['mbi_featured'] ?? '' ) );
		if ( ! in_array( $state, array( 'added', 'removed' ), true ) || 'mbi_manga' !== sanitize_key( (string) ( $_GET['post_type'] ?? '' ) ) ) {
			return;
		}
		$message = 'added' === $state
			? 'Mangá adicionado aos destaques da home.'
			: 'Mangá removido dos destaques manuais.';
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acesso negado.' );
		}

		$settings = $this->importer->settings();
		$counts   = $this->db->counts();
		$progress = $this->progress_data();
		$cleanup  = $this->importer->legacy_media_cleanup_status();
		$proxy_migration = $this->importer->proxy_migration_status();
		$proxy_status = $this->importer->image_proxy_status();
		$invalid_covers = $this->importer->invalid_cover_count( true );
		$validation_review = $this->importer->validation_review_count();
		$jobs     = $this->db->recent_jobs( 25 );
		$logs     = $this->db->recent_logs( 20 );
		$notice   = get_transient( 'mbi_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'mbi_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap mbi-wrap">
			<h1>Mangá Bridge Importer <span class="mbi-version">v<?php echo esc_html( MBI_VERSION ); ?></span></h1>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=mbi_manga&page=mbi-cover-refresh' ) ); ?>">Recuperação rápida de todas as capas</a> — sem reimportar capítulos.</p>
			<p class="description">Importação autorizada de catálogo e capítulos com imagens 100% remotas. Nenhuma capa ou página nova é salva na Biblioteca de Mídia.</p>
			<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=mbi_manga&page=manga-bridge-recovery' ) ); ?>">Recuperar catálogo que saiu do ar</a></p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ?? 'info' ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ?? '' ); ?></p></div>
			<?php endif; ?>

			<section class="mbi-panel mbi-progress-panel mbi-state-<?php echo esc_attr( $progress['state'] ); ?>" data-mbi-progress>
				<header class="mbi-progress-head">
					<div><span class="mbi-progress-status" data-progress-status><?php echo esc_html( $progress['status'] ); ?></span><h2 data-progress-title><?php echo esc_html( $progress['title'] ); ?></h2></div>
					<strong data-progress-percent><?php echo (int) $progress['percent']; ?>%</strong>
				</header>
				<div class="mbi-progress-track" role="progressbar" aria-label="Progresso do processamento atual" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $progress['percent']; ?>">
					<span data-progress-bar style="width:<?php echo (int) $progress['percent']; ?>%"></span>
				</div>
				<p class="mbi-progress-message" data-progress-message><?php echo esc_html( $progress['message'] ); ?></p>
				<div class="mbi-progress-facts">
					<div><span data-progress-order-label><?php echo esc_html( $progress['order_label'] ); ?></span><strong data-progress-order><?php echo esc_html( $progress['order'] ); ?></strong></div>
					<div><span data-progress-pending-label><?php echo empty( $progress['pipeline'] ) ? 'Candidatas ignoradas no pedido' : 'Obras pendentes de revisão'; ?></span><strong data-progress-skipped><?php echo (int) $progress['skipped']; ?></strong></div>
					<div><span>Obras no catálogo do site</span><strong data-progress-catalog><?php echo (int) $progress['catalog_total']; ?></strong></div>
					<div><span>Capítulos publicados no site</span><strong data-progress-chapters><?php echo (int) $progress['chapters']; ?></strong></div>
				</div>
				<div data-pipeline <?php if ( empty( $progress['pipeline'] ) ) { echo 'hidden'; } ?>>
					<?php foreach ( array( 'collect' => 'Coleta de candidatas', 'validate' => 'Candidatas analisadas', 'publish' => 'Meta de publicações' ) as $key => $label ) : ?>
						<p><strong><?php echo esc_html( $label ); ?>: <span data-pipeline-count="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $progress['pipeline']['counts'][$key] ?? '0/0' ); ?></span></strong><br><progress data-pipeline-bar="<?php echo esc_attr( $key ); ?>" max="100" value="<?php echo (int) ( $progress['pipeline']['bars'][$key] ?? 0 ); ?>" style="width:100%" aria-label="<?php echo esc_attr( $label ); ?>"></progress></p>
					<?php endforeach; ?>
					<p>A quantidade pedida conta mangás publicados. Uma candidata recusada fica registrada e é substituída no pedido por quantidade. Importação por URL mantém somente a obra informada. Cada seleção é coletada antes de validar; capítulos e imagens continuam obrigatórios.</p>
					<div style="max-height:280px;overflow:auto"><table class="widefat striped"><thead><tr><th>Obra</th><th>Etapa</th><th>Motivo / capítulos</th></tr></thead><tbody data-pipeline-rows>
					<?php foreach ( (array) ( $progress['pipeline']['rows'] ?? array() ) as $row ) : ?><tr><td><?php echo esc_html( $row['title'] ); ?></td><td><?php echo esc_html( $row['status'] ); ?></td><td><?php echo esc_html( $row['detail'] ); ?></td></tr><?php endforeach; ?>
					</tbody></table></div>
					<div data-resume-quantity52 <?php if ( empty( $progress['pipeline']['can_resume'] ) ) { echo 'hidden'; } ?>><?php $this->action_form( 'mbi_resume_quantity52', 'Completar pedido pendente', 'button button-primary' ); ?></div>
				</div>
				<div class="mbi-runtime"><strong>Atividade:</strong> <span data-progress-activity><?php echo esc_html( $progress['activity'] ?? 'Aguardando' ); ?></span> <small>· último pulso: <span data-progress-heartbeat><?php echo esc_html( $progress['heartbeat'] ?? '—' ); ?></span></small></div>
				<footer class="mbi-progress-footer"><span data-progress-queue><?php echo esc_html( $progress['queue'] ); ?></span><span>Painel a cada 10 s durante o trabalho; consultas param ao terminar. Totais do catálogo: até 60 s.</span></footer>
				<p class="description" data-progress-load><?php echo esc_html( $progress['load'] ?? '' ); ?></p>
			</section>

			<div class="mbi-grid mbi-stats">
				<?php $this->stat( 'Pendentes', $counts['pending'], 'pending', 'pending' ); ?>
				<?php $this->stat( 'Processando', $counts['processing'], 'processing', 'processing' ); ?>
				<?php $this->stat( 'Tarefas concluídas', $counts['completed'], 'completed', 'completed' ); ?>
				<?php $this->stat( 'Com falha', $counts['failed'], 'failed', 'failed' ); ?>
			</div>

			<div class="mbi-panel">
				<h2>Finalizar rascunhos incompletos</h2>
				<p>Etapa separada da sincronização. Revalida cada mangá já criado, recria o que estiver faltando e só publica quando <strong>todos os capítulos e todas as páginas</strong> estiverem completos. Depois disso inicia o próximo rascunho.</p>
				<div class="mbi-actions">
					<?php $this->action_form( 'mbi_validate_drafts', 'Validar / finalizar rascunhos', 'button button-primary button-hero', 'A fila atual será separada da sincronização e os mangás incompletos serão finalizados um por vez. Cada obra só será publicada depois de todos os capítulos e páginas estarem completos. Continuar?' ); ?>
					<?php $this->action_form( 'mbi_hard_reset_repair', 'ZERAR PROCESSO E RECOMEÇAR', 'button button-link-delete button-hero', 'RESET CONTROLADO: todos os jobs, locks e estados do processador serão apagados e o reparo dos rascunhos será reconstruído do zero. Mangás, capítulos e imagens já salvos NÃO serão apagados. Use quando a fila estiver travada. Continuar?' ); ?>
					<?php $this->action_form( 'mbi_cleanup_duplicates', 'Corrigir mangás duplicados', 'button button-secondary button-hero', 'Serão mantidas obras publicadas e uma única cópia de cada URL de origem. Somente duplicatas não publicadas serão movidas para a lixeira. A fila atual será reconciliada sem perder o mangá válido. Continuar?' ); ?>
					<?php $this->action_form( 'mbi_revalidate_suspicious', 'Revalidar obras com dados suspeitos', 'button button-secondary button-hero', 'O plugin verificará metadados por HTML/CSS/JS, sinopse incompleta e outros sinais de extração corrompida. Obras suspeitas serão mantidas em rascunho e reprocessadas; nenhuma será publicada sem passar na validação. Continuar?' ); ?>
				</div>
				<p class="mbi-muted"><strong>Validação anti-corrupção:</strong> <?php echo (int) $validation_review; ?> obra(s) aguardando revisão. Obras com código em Autor/Sinopse ou dados estruturalmente inválidos ficam bloqueadas em rascunho.</p>
				<p class="mbi-muted"><strong>Reset controlado:</strong> apaga somente a fila/motor e preserva o conteúdo. Depois recria um lote novo com os rascunhos existentes.</p>
			</div>

			<div class="mbi-panel mbi-manual-url-panel">
				<h2>1. Importar mangá específico por URL</h2>
				<p>Cole o endereço completo da página do mangá na origem autorizada. O plugin importará <strong>somente essa obra</strong> com capa, sinopse, categorias, SEO, capítulos e páginas remotas.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mbi-manual-url-form">
					<input type="hidden" name="action" value="mbi_start_url">
					<?php wp_nonce_field( 'mbi_admin_action' ); ?>
					<label for="mbi_manual_manga_url"><strong>URL do mangá</strong></label>
					<div class="mbi-manual-url-row">
						<input type="url" id="mbi_manual_manga_url" name="manual_manga_url" class="regular-text code" required placeholder="https://mangalivre.blog/manga/one-piece/ ou https://mangalivre.to/manga/one-piece-ptbr/" autocomplete="off" spellcheck="false">
						<button type="submit" class="button button-primary button-hero">Importar este mangá</button>
					</div>
				</form>
				<p class="mbi-muted">Aceita páginas de obra de <code>mangalivre.blog</code> e <code>mangalivre.to</code> no formato <code>/manga/nome-da-obra/</code>. Também revisa uma obra já marcada como pronta, preservando os capítulos completos.</p>
			</div>

			<div class="mbi-panel">
				<h2>Conferir capítulos antigos das obras já publicadas</h2>
				<p>Reabre o histórico de cada obra na origem autorizada, <strong>uma obra por vez</strong>, e adiciona somente os capítulos que ainda faltam. Capítulos já completos são reutilizados. A lista pública cresce conforme novos capítulos forem concluídos.</p>
				<div class="mbi-actions">
					<?php $this->action_form( 'mbi_history_backfill', 'Revisar históricos publicados', 'button button-primary button-hero', 'A revisão pode levar tempo em obras grandes. Ela confere uma obra por vez e não apaga capítulos publicados. Iniciar?' ); ?>
				</div>
				<p class="mbi-muted">Para testar primeiro uma obra, use o campo de URL acima. O botão PARAR TUDO E ZERAR interrompe o lote sem remover os capítulos gravados.</p>
			</div>

			<div class="mbi-panel">
				<h2>2. Importar a quantidade de mangás publicada</h2>
				<p>Se pedir 5, a meta é publicar 5 mangás completos. Obras bloqueadas não consomem vagas: outras candidatas entram no lugar. As demais URLs da página são reaproveitadas e recusas recentes são lembradas. A busca respeita o limite de páginas e a disponibilidade da origem. Para rascunhos existentes, use “Validar / finalizar rascunhos”.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mbi-actions">
					<input type="hidden" name="action" value="mbi_start_amount">
					<?php wp_nonce_field( 'mbi_admin_action' ); ?>
					<input type="hidden" name="catalog_operation" value="new">
					<label><strong>Mangás para publicar:</strong> <input type="number" name="catalog_amount" min="1" max="500" value="1" style="width:90px"></label>
					<button type="submit" class="button button-primary button-hero">Coletar, validar e publicar o lote</button>
				</form>
				<div class="mbi-actions">
					<?php $this->action_form( 'mbi_start_full', 'Preencher todo o catálogo disponível', 'button button-secondary', 'O plugin irá varrer todo o catálogo dentro do limite de páginas configurado e importar apenas obras ainda inexistentes. Continuar?' ); ?>
					<?php if ( ! empty( $this->importer->catalog_discovery_status()['active'] ) || $this->db->active_starter_count() > 0 ) : ?>
						<?php $this->action_form( 'mbi_cancel_catalog_discovery', 'Cancelar somente busca do catálogo', 'button button-link-delete', 'Interrompe apenas a busca por novas obras. Mangás e capítulos já gravados são preservados e importações explícitas por URL voltam a ficar livres. Continuar?' ); ?>
					<?php endif; ?>
				</div>
				<p class="mbi-muted"><strong>Ordem definitiva:</strong> rascunhos menores primeiro → validação completa → publicação → próxima obra. Uma obra com erro fica em revisão e o lote continua, sem bloquear as demais.</p>
			</div>

			<div class="mbi-panel">
				<h2>3. Novos capítulos / atualizações detectadas</h2>
				<p>A detecção e a publicação agora são <strong>100% manuais</strong>. Primeiro confira a lista; depois publique um capítulo, um mangá ou todos os pendentes.</p>
				<div class="mbi-actions">
					<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'edit.php?post_type=mbi_manga&page=manga-bridge-updates' ) ); ?>">Abrir novos capítulos</a>
				</div>
				<p class="mbi-muted">Última detecção manual: <strong><?php echo esc_html( get_option( 'mbi_last_updates_scan', 'ainda não executada' ) ); ?></strong></p>
				<?php
				$manual_batch = (array) get_option( 'mbi_manual_update_batch', array() );
				$manual_failures = $this->db->manual_failure_summary( (string) ( $manual_batch['id'] ?? '' ) );
				if ( $manual_failures['total'] > 0 ) : ?>
					<p class="mbi-muted"><strong>Falhas dos novos capítulos (lote atual): <?php echo (int) $manual_failures['total']; ?></strong> —
						rede/timeout: <?php echo (int) $manual_failures['rede_temporaria']; ?> ·
						número não comprovado: <?php echo (int) $manual_failures['numero_nao_comprovado']; ?> ·
						imagem/origem: <?php echo (int) $manual_failures['imagem_ou_origem']; ?> ·
						outros: <?php echo (int) $manual_failures['outros']; ?>.
						<?php if ( ! empty( $manual_failures['not_examined'] ) ) : ?> Exibição dos primeiros 500 casos.<?php endif; ?></p>
					<p class="mbi-muted">A recuperação só reenfileira timeouts e erros temporários de rede; cada capítulo é validado por inteiro novamente. Erros estruturais continuam para revisão. Máximo de 12 por clique, 2 ciclos extras por job. Não reinicia capítulos concluídos.</p>
					<?php if ( $manual_failures['rede_temporaria'] > 0 ) : ?>
						<div class="mbi-actions"><?php $this->action_form( 'mbi_retry_manual_network', 'RECUPERAR FALHAS DE REDE DOS NOVOS CAPÍTULOS', 'button button-primary', 'Reenfileira apenas até 12 capítulos do lote manual atual com falha comprovadamente transitória de rede; não mexe nos 177 já concluídos nem publica imagens não verificadas. Continuar?' ); ?></div>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="mbi-panel">
				<h2>Controles do processador</h2>
				<p>Velocidade atual: <strong><?php echo esc_html( $this->importer->processing_speed_label() ); ?></strong>. <strong>Remote Images:</strong> capas e páginas usam URLs externas validadas. Nenhuma imagem nova é hospedada neste WordPress.</p><p class="mbi-muted"><strong>Para máxima velocidade durante a carga/reparo, mantenha esta tela do Importador aberta.</strong> O WP-Cron continua como fallback quando você sair do painel.</p>
				<div class="mbi-actions">
					<?php $this->action_form( 'mbi_run_now', 'Processar fila agora', 'button button-secondary' ); ?>
					<?php $this->action_form( 'mbi_force_retry_now', 'FORÇAR TENTATIVA AGORA', 'button button-primary', 'Libera somente a próxima retentativa agendada do lote atual. Jobs já concluídos e capítulos prontos não serão refeitos. Continuar?' ); ?>
					<?php if ( $this->importer->is_serial_active() ) : ?>
						<?php $this->action_form( 'mbi_skip_current_manga', 'PULAR OBRA ATUAL', 'button button-secondary', 'A obra atual permanecerá em rascunho/revisão e o importador avançará para a próxima candidata. Conteúdo já gravado será preservado. Continuar?' ); ?>
					<?php endif; ?>
					<?php $this->action_form( 'mbi_recover_queue', 'Destravar fila agora', 'button button-primary', 'A autocura removerá somente pendências seriais órfãs e recriará o job da obra atual se necessário. O conteúdo já importado será preservado. Continuar?' ); ?>
					<?php $this->action_form( 'mbi_test_connection', 'Testar conexão e extrator', 'button button-secondary' ); ?>
					<?php $this->action_form( 'mbi_retry_failed', 'Tentar falhas novamente', 'button button-secondary' ); ?>
					<?php $this->action_form( 'mbi_stop_all', 'PARAR TUDO E ZERAR', 'button button-link-delete button-hero', 'Isso interrompe qualquer processo do plugin, apaga a fila e volta o motor a zero. Obras e capítulos já gravados serão preservados. Continuar?' ); ?>
					<?php if ( $this->importer->is_paused() ) : ?>
						<?php $this->action_form( 'mbi_resume', 'Retomar importação', 'button button-primary' ); ?>
					<?php else : ?>
						<?php $this->action_form( 'mbi_pause', 'Pausar importação', 'button button-secondary' ); ?>
					<?php endif; ?>
					<?php $this->action_form( 'mbi_cancel_pending', 'Cancelar pendências', 'button button-link-delete', 'Isso cancelará somente os jobs que ainda não começaram. Mangás, capas e capítulos já importados serão preservados. Continuar?' ); ?>
					<?php $this->action_form( 'mbi_clear_completed', 'Limpar histórico concluído', 'button button-link-delete', 'Isso remove somente os jobs concluídos do histórico, não remove obras nem imagens. Continuar?' ); ?>
				</div>
			</div>


			<div class="mbi-panel">
				<h2>Proxy/CDN de imagens — Cloudflare Worker</h2>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=mbi_manga&page=mbi-global-diagnostic' ) ); ?>">Diagnóstico global + baixar relatório</a></p>
				<p>Entrega capas e páginas por <code>img.lermangaonline.com.br</code> sem baixar arquivos no WordPress. O token é gerado localmente com AES-256-GCM; a importação não espera o Worker e não ganha uma etapa de download.</p>
				<p>Status: <strong><?php echo ! empty( $proxy_status['ready'] ) ? 'PRONTO E ATIVO' : ( ! empty( $proxy_status['enabled'] ) ? 'ATIVO, MAS INCOMPLETO' : 'DESATIVADO' ); ?></strong>
					· base: <code><?php echo esc_html( $proxy_status['base'] ?: 'não configurada' ); ?></code>
					· chave: <strong><?php echo ! empty( $proxy_status['key_ok'] ) ? 'OK' : 'ausente/inválida'; ?></strong>
					· OpenSSL: <strong><?php echo ! empty( $proxy_status['openssl'] ) ? 'OK' : 'indisponível'; ?></strong></p>
				<div class="mbi-actions">
					<button type="button" class="button button-secondary" data-mbi-proxy-test>Testar Proxy/CDN agora</button>
					<button type="button" class="button button-secondary" data-mbi-proxy-audit>Auditar origens das imagens</button>
					<?php $this->action_form( 'mbi_repair_invalid_covers', 'Reparar capas inválidas' . ( $invalid_covers >= 0 ? ' (' . (int) $invalid_covers . ')' : '' ), 'button button-secondary', 'Somente obras sem capa ou com placeholder serão reparadas. Se a origem não tiver capa real, a primeira página válida de um capítulo já existente será usada como fallback visual. Capítulos, slugs e datas não serão reimportados. Continuar?' ); ?>
					<?php $this->action_form( 'mbi_repair_invalid_chapter_pages', 'Reparar páginas inválidas', 'button button-secondary', 'Relê somente capítulos que contêm assets indevidos como bandeiras/GIFs de interface. Não altera slug, título ou data do capítulo. Continuar?' ); ?>
					<?php $this->action_form( 'mbi_start_proxy_migration', ! empty( $proxy_migration['active'] ) ? 'Continuar migração existente' : 'Migrar imagens existentes para Proxy/CDN', 'button button-primary button-hero', 'A migração altera somente as URLs de entrega de capas e páginas. As URLs originais ficam preservadas internamente; não há download, reimportação de capítulos, alteração de slug ou data de publicação. Continuar?' ); ?>
				</div>
				<p class="mbi-muted" data-proxy-test-status>Teste seguro: aguardando execução pelo navegador.</p>
				<div class="mbi-muted" data-proxy-audit-status>Auditoria por origem: ainda não executada. O teste usa no máximo uma amostra por host para não consumir o Worker.</div>
				<p class="mbi-muted"><strong>Migração:</strong> <span data-proxy-migration-state><?php echo ! empty( $proxy_migration['active'] ) ? 'EM ANDAMENTO' : ( ! empty( $proxy_migration['done'] ) ? 'CONCLUÍDA' : 'NÃO INICIADA' ); ?></span>
					· posts verificados: <span data-proxy-migration-scanned><?php echo (int) $proxy_migration['posts_scanned']; ?></span>
					· mangás: <span data-proxy-migration-mangas><?php echo (int) $proxy_migration['mangas_migrated']; ?></span>
					· capítulos: <span data-proxy-migration-chapters><?php echo (int) $proxy_migration['chapters_migrated']; ?></span>
					· URLs protegidas: <span data-proxy-migration-urls><?php echo (int) $proxy_migration['urls_migrated']; ?></span>
					· ignorados/já migrados: <span data-proxy-migration-skipped><?php echo (int) $proxy_migration['skipped']; ?></span>.</p>
				<p class="mbi-muted">Execute primeiro o teste do Proxy/CDN. A migração roda em lotes e continua pelo WP-Cron sem bloquear a fila normal do importador.</p>
			</div>

			<div class="mbi-panel" data-mbi-cleanup>
				<h2>Limpeza das imagens antigas do Mangá Bridge</h2>
				<p>Remove do servidor <strong>somente os anexos criados pelas versões antigas do Mangá Bridge</strong>. Antes de apagar cada arquivo, o plugin preserva a capa/páginas como URLs remotas. Imagens de posts normais, tema e outros plugins não entram nesta limpeza.</p>
				<p>
					Status: <strong data-cleanup-state><?php echo ! empty( $cleanup['active'] ) ? 'EM ANDAMENTO' : ( ! empty( $cleanup['done'] ) ? 'CONCLUÍDA' : 'AGUARDANDO' ); ?></strong>
					· removidas: <strong data-cleanup-deleted><?php echo (int) $cleanup['deleted']; ?></strong>
					· restantes: <strong data-cleanup-remaining><?php echo (int) $cleanup['remaining']; ?></strong>
					· protegidas: <strong data-cleanup-protected><?php echo (int) $cleanup['protected']; ?></strong>
				</p>
				<div class="mbi-actions">
					<?php $this->action_form( 'mbi_cleanup_legacy_media', ! empty( $cleanup['active'] ) ? 'Continuar limpeza agora' : 'Remover imagens antigas agora', 'button button-primary button-hero', 'Serão excluídos permanentemente apenas anexos identificados como criados pelo Mangá Bridge. As URLs remotas serão preservadas antes da exclusão. Continuar?' ); ?>
				</div>
				<p class="mbi-muted">A limpeza roda em lotes para não derrubar o WordPress. Com esta tela aberta, o navegador acelera os lotes; o WP-Cron continua a limpeza em segundo plano.</p>
			</div>

			<div class="mbi-columns">
				<div class="mbi-panel">
					<h2>Configurações</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="mbi_save_settings">
						<?php wp_nonce_field( 'mbi_admin_action' ); ?>
						<table class="form-table" role="presentation">
							<tr><th><label for="source_url">Site de origem</label></th><td><input class="regular-text code" type="url" id="source_url" name="settings[source_url]" value="<?php echo esc_attr( $settings['source_url'] ); ?>" required><p class="description">Use somente a origem para a qual você possui autorização.</p></td></tr>
							<tr><th><label for="allowed_hosts">Domínios autorizados</label></th><td><textarea class="large-text code" rows="3" id="allowed_hosts" name="settings[allowed_hosts]"><?php echo esc_textarea( $settings['allowed_hosts'] ); ?></textarea><p class="description">Um domínio por linha. Protege o importador contra requisições indevidas.</p></td></tr>
						<tr><th>Detecção manual rápida</th><td><label>Páginas recentes por origem: <input type="number" min="1" max="30" name="settings[update_pages]" value="<?php echo esc_attr( $settings['update_pages'] ); ?>" style="width:80px"></label><p class="description">Escala para milhares de mangás: somente as obras presentes nesta janela recente são abertas. O detector usa no máximo 6 conexões simultâneas e não acessa imagens/Worker.</p></td></tr>
						<tr><th><label for="processing_speed">Velocidade</label></th><td><select id="processing_speed" name="settings[processing_speed]"><option value="normal" <?php selected( $settings['processing_speed'], 'normal' ); ?>>Normal — 4 requisições paralelas</option><option value="fast" <?php selected( $settings['processing_speed'], 'fast' ); ?>>Rápido — 8 requisições paralelas</option><option value="turbo" <?php selected( $settings['processing_speed'], 'turbo' ); ?>>Turbo — 14 requisições paralelas</option><option value="ultra" <?php selected( $settings['processing_speed'], 'ultra' ); ?>>Ultra — até 24 requisições + capítulos paralelos</option></select><p class="description">Ultra é o padrão desta versão e foi criado para carga inicial/reparo rápido. Usa mais CPU, rede e conexões simultâneas.</p></td></tr>
						<tr><th>Imagens do catálogo</th><td><strong>100% remotas — ativo</strong><input type="hidden" name="settings[page_storage_mode]" value="remote"><p class="description">Capas e páginas dos capítulos usam as URLs da origem. O plugin não cria novos anexos de imagem nem ocupa espaço da Biblioteca de Mídia.</p></td></tr>
						<tr><th>Proxy/CDN de imagens</th><td>
							<label><input type="checkbox" name="settings[image_proxy_enabled]" value="1" <?php checked( ! empty( $settings['image_proxy_enabled'] ) ); ?>> Ativar URLs protegidas pelo Cloudflare Worker</label><br>
							<label>URL pública: <input class="regular-text code" type="url" name="settings[image_proxy_base_url]" value="<?php echo esc_attr( $settings['image_proxy_base_url'] ); ?>" placeholder="https://img.lermangaonline.com.br"></label><br>
							<label>Chave <code>PROXY_KEY_B64</code>: <input class="regular-text code" type="password" autocomplete="new-password" name="settings[image_proxy_key_b64]" value="" placeholder="<?php echo ! empty( $settings['image_proxy_key_b64'] ) ? 'Chave já salva — deixe vazio para manter' : 'Cole a mesma chave usada no Worker'; ?>"></label>
							<p class="description">A chave deve ser exatamente a mesma Secret <code>PROXY_KEY_B64</code> configurada no Worker. Ela não é exibida novamente no painel. Deixar vazio preserva a chave já salva.</p>
						</td></tr>
						<tr><th>Worker contínuo</th><td><label><input type="checkbox" name="settings[continuous_worker]" value="1" <?php checked( $settings['continuous_worker'] ); ?>> Continuar o próximo ciclo imediatamente sem esperar o próximo minuto do WP-Cron</label></td></tr>
							<tr><th><label for="max_catalog_pages">Limite de páginas do catálogo</label></th><td><input type="number" min="1" max="500" id="max_catalog_pages" name="settings[max_catalog_pages]" value="<?php echo esc_attr( $settings['max_catalog_pages'] ); ?>"></td></tr>
							<tr><th><label for="max_images_chapter">Máximo de páginas por capítulo</label></th><td><input type="number" min="1" max="1000" id="max_images_chapter" name="settings[max_images_chapter]" value="<?php echo esc_attr( $settings['max_images_chapter'] ); ?>"></td></tr>
							<tr><th><label for="max_attempts">Tentativas por job</label></th><td><input type="number" min="1" max="10" id="max_attempts" name="settings[max_attempts]" value="<?php echo esc_attr( $settings['max_attempts'] ); ?>"></td></tr>
							<tr><th><label for="publish_status">Status importado</label></th><td><select id="publish_status" name="settings[publish_status]"><option value="publish" <?php selected( $settings['publish_status'], 'publish' ); ?>>Publicado</option><option value="draft" <?php selected( $settings['publish_status'], 'draft' ); ?>>Rascunho</option><option value="private" <?php selected( $settings['publish_status'], 'private' ); ?>>Privado</option></select></td></tr>
							<tr><th>Processamento de imagem</th><td><span class="description">Desativado no modo remoto. Conversão WebP local não se aplica.</span></td></tr>
						</table>
						<?php submit_button( 'Salvar configurações' ); ?>
					</form>
				</div>

				<div class="mbi-panel">
					<h2>Diagnóstico do servidor</h2>
					<table class="widefat striped mbi-diagnostics"><tbody>
						<?php $this->diagnostic_row( 'PHP', PHP_VERSION, version_compare( PHP_VERSION, '8.0', '>=' ) ); ?>
						<?php $this->diagnostic_row( 'Extensão DOM/XML', class_exists( 'DOMDocument' ) ? 'Disponível' : 'Ausente', class_exists( 'DOMDocument' ) ); ?>
						<?php $this->diagnostic_row( 'Extensão mbstring', extension_loaded( 'mbstring' ) ? 'Disponível' : 'Ausente — modo compatível ativo', true ); ?>
						<?php $this->diagnostic_row( 'WebP', wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'Disponível' : 'Indisponível', wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ); ?>
						<?php $this->diagnostic_row( 'Proxy/CDN de imagens', ! empty( $proxy_status['ready'] ) ? 'Pronto e ativo' : ( ! empty( $proxy_status['enabled'] ) ? 'Configuração incompleta' : 'Desativado' ), empty( $proxy_status['enabled'] ) || ! empty( $proxy_status['ready'] ) ); ?>
						<?php $this->diagnostic_row( 'Memória WordPress', WP_MEMORY_LIMIT, wp_convert_hr_to_bytes( WP_MEMORY_LIMIT ) >= 256 * MB_IN_BYTES ); ?>
						<?php $this->diagnostic_row( 'WP-Cron', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Desativado — configure cron do sistema' : 'Ativo', ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ); ?>
						<?php $this->diagnostic_row( 'Próximo processador', wp_next_scheduled( 'mbi_process_queue' ) ? wp_date( 'd/m/Y H:i:s', wp_next_scheduled( 'mbi_process_queue' ) ) : 'Não agendado', (bool) wp_next_scheduled( 'mbi_process_queue' ) ); ?>
						<?php $this->diagnostic_row( 'Atualizações automáticas', 'Desativadas — controle manual', true ); ?>
					</tbody></table>
					<div class="notice notice-warning inline"><p><strong>VPS recomendado:</strong> use cron real chamando o WP-Cron a cada minuto. A fila não depende de uma única execução longa.</p></div>
				</div>
			</div>

			<div class="mbi-panel">
				<h2>Jobs recentes</h2>
				<div class="mbi-table-scroll"><table class="widefat striped"><thead><tr><th>ID</th><th>Tipo</th><th>Status</th><th>Tentativas</th><th>Origem</th><th>Atualizado</th><th>Erro</th></tr></thead><tbody>
				<?php if ( ! $jobs ) : ?><tr><td colspan="7">A fila ainda está vazia.</td></tr><?php endif; ?>
				<?php foreach ( $jobs as $job ) : $payload = json_decode( $job->payload, true ) ?: array(); ?>
					<tr><td><?php echo (int) $job->id; ?></td><td><?php echo esc_html( $job->job_type ); ?></td><td><span class="mbi-badge mbi-<?php echo esc_attr( $job->status ); ?>"><?php echo esc_html( $job->status ); ?></span></td><td><?php echo (int) $job->attempts; ?></td><td class="mbi-url" title="<?php echo esc_attr( $payload['url'] ?? '' ); ?>"><?php echo esc_html( $payload['url'] ?? '' ); ?></td><td><?php echo esc_html( $job->updated_at ); ?></td><td><?php echo esc_html( $job->last_error ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
			</div>

			<details class="mbi-panel"><summary><strong>Logs recentes</strong></summary><div class="mbi-log-list">
				<?php if ( ! $logs ) : ?><p>Nenhum log registrado.</p><?php endif; ?>
				<?php foreach ( $logs as $log ) : ?><p><span class="mbi-badge mbi-<?php echo esc_attr( $log->level ); ?>"><?php echo esc_html( $log->level ); ?></span> <strong><?php echo esc_html( $log->created_at ); ?></strong> — <?php echo esc_html( $log->message ); ?></p><?php endforeach; ?>
			</div></details>
		</div>
		<?php
	}

	public function render_updates(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acesso negado.' );
		}
		$updates  = $this->importer->detected_updates();
		$progress = $this->manual_progress_data();
		$scan     = $this->importer->update_scan_progress();
		$notice   = get_transient( 'mbi_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'mbi_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap mbi-wrap">
			<h1>Novos capítulos / Atualizações detectadas <span class="mbi-version">manual</span></h1>
			<p class="description"><strong>Detecção rápida para catálogos grandes:</strong> o plugin lê somente as páginas recentes das origens, cruza com as obras locais e verifica em pequenos lotes paralelos. O custo não cresce junto com os milhares de mangás já cadastrados. A detecção não publica nem altera capítulos.</p>
			<?php if ( $notice ) : ?><div class="notice notice-<?php echo esc_attr( $notice['type'] ?? 'info' ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ?? '' ); ?></p></div><?php endif; ?>

			<div class="mbi-panel">
				<h2>1. Detectar novidades</h2>
				<p>Inicie a leitura manual. Em vez de abrir todos os mangás do site, o motor consulta a janela recente configurada e só verifica as obras que realmente apareceram nela.</p>
				<div class="mbi-actions">
					<button class="button button-primary button-hero" type="button" data-mbi-scan-start<?php disabled( ! empty( $scan['active'] ) ); ?>><?php echo ! empty( $scan['active'] ) ? 'Verificação em andamento…' : 'Detectar novos capítulos agora'; ?></button>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Interromper qualquer processo do plugin e zerar a fila? O conteúdo já gravado será preservado.');"><?php wp_nonce_field( 'mbi_admin_action' ); ?><input type="hidden" name="action" value="mbi_stop_all"><input type="hidden" name="return_page" value="manga-bridge-updates"><button class="button button-link-delete button-hero" type="submit">PARAR TUDO E ZERAR</button></form>
				</div>
				<p class="mbi-muted">Última detecção: <strong><?php echo esc_html( get_option( 'mbi_last_updates_scan', 'ainda não executada' ) ); ?></strong>. Não existe verificação nem publicação automática.</p>
			</div>

			<section class="mbi-panel mbi-progress-panel mbi-state-<?php echo esc_attr( $scan['visual'] ); ?>" data-mbi-update-scan>
				<header class="mbi-progress-head"><div><span class="mbi-progress-status" data-scan-status><?php echo esc_html( $scan['status'] ); ?></span><h2 data-scan-title><?php echo esc_html( $scan['title'] ); ?></h2></div><strong data-scan-percent><?php echo (int) $scan['percent']; ?>%</strong></header>
				<div class="mbi-progress-track" role="progressbar" aria-label="Progresso da detecção de novos capítulos" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $scan['percent']; ?>"><span data-scan-bar style="width:<?php echo (int) $scan['percent']; ?>%"></span></div>
				<p data-scan-message><?php echo esc_html( $scan['message'] ); ?></p>
				<div class="mbi-progress-facts mbi-scan-facts">
					<div><span>Obras recentes</span><strong data-scan-total><?php echo (int) $scan['total']; ?></strong></div>
					<div><span>Verificados</span><strong data-scan-checked><?php echo (int) $scan['checked']; ?></strong></div>
					<div><span>Com novidades</span><strong data-scan-with><?php echo (int) $scan['with_updates']; ?></strong></div>
					<div><span>Sem novidades</span><strong data-scan-without><?php echo (int) $scan['without_updates']; ?></strong></div>
					<div><span>Capítulos encontrados</span><strong data-scan-chapters><?php echo (int) $scan['found_chapters']; ?></strong></div>
					<div><span>Listas parciais</span><strong data-scan-partial><?php echo (int) ( $scan['partial'] ?? 0 ); ?></strong></div>
					<div><span>Falhas</span><strong data-scan-failed><?php echo (int) $scan['failed']; ?></strong></div>
				</div>
				<div class="mbi-runtime"><strong>Checando agora:</strong> <span data-scan-current><?php echo esc_html( (string) ( $scan['current_manga'] ?: '—' ) ); ?></span></div>
				<div class="mbi-scan-list-wrap">
					<h3>Resultado mangá por mangá</h3>
					<div class="mbi-table-scroll"><table class="widefat striped mbi-scan-table"><thead><tr><th>#</th><th>Mangá</th><th>Situação</th><th>Novos capítulos</th><th>Horário</th></tr></thead><tbody data-scan-results>
					<?php if ( empty( $scan['items'] ) ) : ?><tr data-scan-empty><td colspan="5">A lista aparecerá aqui quando a verificação começar.</td></tr><?php endif; ?>
					<?php foreach ( (array) $scan['items'] as $position => $item ) : ?>
						<tr data-scan-manga-id="<?php echo (int) ( $item['manga_id'] ?? 0 ); ?>"><td><?php echo (int) $position + 1; ?></td><td><strong><?php echo esc_html( (string) ( $item['manga_name'] ?? '' ) ); ?></strong></td><td><span class="mbi-badge mbi-scan-<?php echo esc_attr( (string) ( $item['status'] ?? 'pending' ) ); ?>"><?php echo esc_html( $this->scan_status_label( (string) ( $item['status'] ?? 'pending' ), (int) ( $item['new_count'] ?? 0 ) ) ); ?></span><?php if ( ! empty( $item['error'] ) ) : ?><small class="mbi-scan-error"><?php echo esc_html( (string) $item['error'] ); ?></small><?php endif; ?><?php if ( ! empty( $item['note'] ) ) : ?><small class="mbi-scan-note"><?php echo esc_html( (string) $item['note'] ); ?></small><?php endif; ?></td><td><?php echo esc_html( $this->scan_chapters_label( (array) ( $item['chapter_numbers'] ?? array() ) ) ); ?></td><td><?php echo esc_html( (string) ( $item['checked_at'] ?? '—' ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table></div>
				</div>
			</section>

			<div class="mbi-panel" data-mbi-detected-updates>
				<header class="mbi-section-head"><div><h2>2. Novos capítulos encontrados</h2><p>Esta lista é preenchida enquanto os mangás são verificados. Nada é publicado até você escolher uma ação.</p></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mbi-apply-all<?php echo $updates ? '' : ' hidden'; ?>><?php wp_nonce_field( 'mbi_admin_action' ); ?><input type="hidden" name="action" value="mbi_apply_updates"><input type="hidden" name="apply_mode" value="all"><button class="button button-primary" type="submit">Atualizar todos os capítulos pendentes</button></form></header>
				<div data-mbi-updates-list><?php $this->render_detected_update_groups( $updates ); ?></div>
			</div>

			<?php if ( $progress['total'] > 0 || $progress['active'] ) : ?>
			<section class="mbi-panel mbi-progress-panel mbi-state-<?php echo esc_attr( $progress['state'] ); ?>" data-mbi-update-progress>
				<header class="mbi-progress-head"><div><span class="mbi-progress-status" data-update-status><?php echo esc_html( $progress['status'] ); ?></span><h2 data-update-title><?php echo esc_html( $progress['title'] ); ?></h2></div><strong data-update-percent><?php echo (int) $progress['percent']; ?>%</strong></header>
				<div class="mbi-progress-track" role="progressbar" aria-label="Progresso da publicação selecionada" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $progress['percent']; ?>"><span data-update-bar style="width:<?php echo (int) $progress['percent']; ?>%"></span></div>
				<p data-update-message><?php echo esc_html( $progress['message'] ); ?></p>
				<div class="mbi-progress-facts"><div><span>Total selecionado</span><strong data-update-total><?php echo (int) $progress['total']; ?></strong></div><div><span>Concluídos</span><strong data-update-completed><?php echo (int) $progress['completed']; ?></strong></div><div><span>Falhas</span><strong data-update-failed><?php echo (int) $progress['failed']; ?></strong></div><div><span>Restantes</span><strong data-update-pending><?php echo (int) $progress['pending']; ?></strong></div></div>
				<div class="mbi-runtime"><strong>Mangá:</strong> <span data-update-manga><?php echo esc_html( $progress['manga'] ); ?></span> · <strong>Capítulo:</strong> <span data-update-chapter><?php echo esc_html( $progress['chapter'] ); ?></span></div>
			</section>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_catalog_recovery(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acesso negado.' );
		}
		$recovery = new MBI_Catalog_Recovery( $this->importer, $this->db );
		$counts = $recovery->counts();
		$state = $recovery->state();
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$candidates = $recovery->candidates( $page );
		$notice = get_transient( 'mbi_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'mbi_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap mbi-wrap">
			<h1>Recuperar catálogo <span class="mbi-version">v<?php echo esc_html( MBI_VERSION ); ?></span></h1>
			<p>Esta ferramenta restaura somente registros que ainda existem no WordPress. Ela <strong>não importa obras novas</strong>, não acessa a origem e não altera URLs, capas, sinopses ou datas.</p>
			<?php if ( $notice ) : ?><div class="notice notice-<?php echo esc_attr( $notice['type'] ?? 'info' ); ?>"><p><?php echo esc_html( $notice['message'] ?? '' ); ?></p></div><?php endif; ?>
			<div class="mbi-panel"><h2>Estado atual do banco</h2>
				<p><strong>Mangás:</strong> <?php echo (int) $counts['manga_published']; ?> publicados · <?php echo (int) $counts['manga_draft']; ?> rascunhos · <?php echo (int) $counts['manga_trash']; ?> na lixeira.</p>
				<p><strong>Capítulos:</strong> <?php echo (int) $counts['chapter_published']; ?> publicados · <?php echo (int) $counts['chapter_draft']; ?> rascunhos · <?php echo (int) $counts['chapter_trash']; ?> na lixeira.</p>
				<p>Faça um <strong>backup do banco</strong> antes de restaurar. Se obras ou capítulos foram realmente excluídos do banco, esta função não consegue recriá-los: será necessário restaurar o backup anterior ao incidente.</p>
				<?php if ( ! $this->importer->is_paused() ) : ?><p><strong>Pause o importador em Mangás → Importador antes de começar.</strong> A restauração não altera ou apaga a fila.</p><?php endif; ?>
			</div>
			<?php if ( $state ) : ?>
			<div class="mbi-panel" data-mbi-catalog-restore data-active="<?php echo ! empty( $state['active'] ) ? '1' : '0'; ?>">
				<h2><?php echo ! empty( $state['active'] ) ? 'Restauração em andamento' : 'Última restauração'; ?></h2>
				<p data-restore-status><?php echo (int) ( $state['percent'] ?? 0 ); ?>% · <?php echo (int) ( $state['index'] ?? 0 ); ?> de <?php echo count( (array) ( $state['ids'] ?? array() ) ); ?> obras verificadas · <?php echo (int) ( $state['current_draft_done'] ?? 0 ); ?>/<?php echo (int) ( $state['current_draft_total'] ?? 0 ); ?> capítulos da obra atual · <?php echo (int) ( $state['mangas_restored'] ?? 0 ); ?> mangás e <?php echo (int) ( $state['chapters_restored'] ?? 0 ); ?> capítulos publicados. <?php echo (int) ( $state['mangas_skipped'] ?? 0 ); ?> obras e <?php echo (int) ( $state['chapters_skipped'] ?? 0 ); ?> capítulos ignorados.</p>
				<?php if ( ! empty( $state['active'] ) ) : ?>
				<div class="mbi-progress-track" role="progressbar" aria-label="Progresso da recuperação" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) ( $state['percent'] ?? 0 ); ?>"><span data-restore-bar style="width:<?php echo (int) ( $state['percent'] ?? 0 ); ?>%"></span></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mbi_admin_action' ); ?><input type="hidden" name="action" value="mbi_catalog_restore_tick"><button class="button" type="submit">Processar próximo lote manualmente</button></form>
				<?php endif; ?>
				<?php foreach ( array_slice( (array) ( $state['errors'] ?? array() ), -10 ) as $error ) : ?><p class="notice notice-warning" style="padding:8px"><?php echo esc_html( (string) $error ); ?></p><?php endforeach; ?>
			</div>
			<?php endif; ?>
			<div class="mbi-panel"><h2>Obras em rascunho com destino de publicação (<?php echo (int) $candidates['total']; ?>)</h2>
				<p>O campo “conclusão anterior” é um indício salvo pelo importador; os outros rascunhos exigem conferência manual. Um capítulo só volta ao ar se tiver páginas cadastradas. A existência da URL não comprova que a imagem remota responde agora.</p>
				<?php if ( $candidates['rows'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Restaurar apenas as obras selecionadas e seus capítulos com páginas cadastradas? Confira o backup e a seleção.');">
					<?php wp_nonce_field( 'mbi_admin_action' ); ?><input type="hidden" name="action" value="mbi_catalog_restore_start">
					<p><label><input type="checkbox" data-mbi-select-all> Selecionar todas as obras elegíveis desta página (até 50)</label></p>
					<div class="mbi-table-scroll"><table class="widefat striped"><thead><tr><th>Selecionar</th><th>Mangá</th><th>Capítulos no banco</th><th>Indício</th></tr></thead><tbody>
					<?php foreach ( $candidates['rows'] as $row ) : ?><tr>
					<td><input type="checkbox" name="manga_ids[]" value="<?php echo (int) $row['id']; ?>" <?php checked( $row['proof'] && $row['eligible'] ); ?> <?php disabled( ! $row['eligible'] ); ?>></td>
					<td><strong><?php echo esc_html( $row['title'] ); ?></strong> (ID <?php echo (int) $row['id']; ?>) <?php if ( ! $row['eligible'] ) : ?><br><small>Sem capa/sinopse ou sem capítulos; não restaurável por esta ferramenta.</small><?php endif; ?></td>
					<td><?php echo (int) $row['chapters']['published']; ?> públicos · <?php echo (int) $row['chapters']['draft']; ?> rascunhos</td>
					<td><?php echo $row['proof'] ? 'Conclusão ou destaque anterior' : ( $row['review'] ? 'Marcada para revisão' : 'Sem registro de publicação anterior' ); ?></td>
					</tr><?php endforeach; ?>
					</tbody></table></div>
					<p><label><input type="checkbox" name="backup_confirmed" value="1" required> Fiz um backup do banco e conferi as obras selecionadas.</label></p>
					<button class="button button-primary" type="submit" <?php disabled( ! $this->importer->is_paused() || ! empty( $state['active'] ) ); ?>>Restaurar obras selecionadas</button>
				</form>
				<?php else : ?><p>Nenhum rascunho com destino de publicação nesta página. Se o contador de Mangás também está em zero, recupere o backup do banco anterior à atualização.</p><?php endif; ?>
				<?php if ( $candidates['pages'] > 1 ) : ?><p>Página <?php echo (int) $page; ?> de <?php echo (int) $candidates['pages']; ?>. <?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>">Anterior</a> · <?php endif; ?><?php if ( $page < $candidates['pages'] ) : ?><a href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>">Próxima</a><?php endif; ?></p><?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function catalog_restore_start(): void {
		$this->guard();
		if ( '1' !== (string) ( $_POST['backup_confirmed'] ?? '' ) ) {
			$this->redirect_notice( 'error', 'Faça um backup do banco antes de restaurar.', 'manga-bridge-recovery' );
		}
		$ids = isset( $_POST['manga_ids'] ) && is_array( $_POST['manga_ids'] ) ? wp_unslash( $_POST['manga_ids'] ) : array();
		$result = ( new MBI_Catalog_Recovery( $this->importer, $this->db ) )->start( $ids );
		$this->redirect_notice( ! empty( $result['error'] ) ? 'error' : 'success', (string) ( $result['error'] ?? 'Restauração iniciada. Acompanhe as obras e capítulos nesta tela.' ), 'manga-bridge-recovery' );
	}

	public function ajax_catalog_restore_tick(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		check_ajax_referer( 'mbi_catalog_restore_tick', 'nonce' );
		wp_send_json_success( ( new MBI_Catalog_Recovery( $this->importer, $this->db ) )->tick() );
	}

	public function catalog_restore_tick_fallback(): void {
		$this->guard();
		( new MBI_Catalog_Recovery( $this->importer, $this->db ) )->tick();
		$this->redirect_notice( 'info', 'Um lote local foi processado.', 'manga-bridge-recovery' );
	}

	public function render_error_logs(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acesso negado.' );
		}
		$logs = $this->db->error_logs( 500 );
		?>
		<div class="wrap mbi-wrap">
			<h1>Logs de erros</h1>
			<p class="description">Falhas do importador com data, operação, URL e contexto técnico para diagnóstico.</p>
			<div class="mbi-panel"><div class="mbi-actions"><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mbi_download_error_log' ), 'mbi_download_error_log' ) ); ?>">Baixar log em CSV</a></div><p><strong><?php echo number_format_i18n( $this->db->error_log_count() ); ?></strong> erro(s)/aviso(s) registrados.</p></div>
			<div class="mbi-panel"><div class="mbi-table-scroll"><table class="widefat striped"><thead><tr><th>Data</th><th>Erro</th><th>Operação / conteúdo</th></tr></thead><tbody>
			<?php if ( ! $logs ) : ?><tr><td colspan="3">Nenhum erro registrado.</td></tr><?php endif; ?>
			<?php foreach ( $logs as $log ) : $context = json_decode( (string) $log->context, true ) ?: array(); ?><tr><td><?php echo esc_html( $log->created_at ); ?></td><td><?php echo esc_html( $log->message ); ?></td><td><code><?php echo esc_html( (string) ( $context['url'] ?? $context['chapter_url'] ?? '' ) ); ?></code><?php if ( $context ) : ?><details><summary>Ver contexto</summary><pre class="mbi-log-context"><?php echo esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></details><?php endif; ?></td></tr><?php endforeach; ?>
			</tbody></table></div></div>
		</div>
		<?php
	}

	private function render_detected_update_groups( array $updates ): void {
		$groups = array();
		foreach ( $updates as $row ) {
			$groups[ absint( $row['manga_id'] ?? 0 ) ][] = $row;
		}
		if ( ! $groups ) {
			echo '<p data-updates-empty>Nenhum capítulo novo detectado até agora.</p>';
			return;
		}
		foreach ( $groups as $manga_id => $rows ) {
			?>
			<section class="mbi-update-group">
				<header><div><strong><?php echo esc_html( (string) ( $rows[0]['manga_name'] ?? get_the_title( $manga_id ) ) ); ?></strong><span><?php echo count( $rows ); ?> capítulo(s) novo(s)</span></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mbi_admin_action' ); ?><input type="hidden" name="action" value="mbi_apply_updates"><input type="hidden" name="manga_id" value="<?php echo (int) $manga_id; ?>"><button class="button button-primary" type="submit">Atualizar este mangá</button></form></header>
				<div class="mbi-table-scroll"><table class="widefat striped"><thead><tr><th>Ordem</th><th>Capítulo</th><th>Detectado em</th><th>Ação</th></tr></thead><tbody>
				<?php foreach ( $rows as $position => $row ) : ?><tr><td><?php echo (int) $position + 1; ?></td><td><strong>Capítulo <?php echo esc_html( (string) ( $row['chapter_number'] ?? 'novo' ) ); ?></strong><br><small class="mbi-url"><?php echo esc_html( (string) ( $row['chapter_url'] ?? '' ) ); ?></small></td><td><?php echo esc_html( (string) ( $row['detected_at'] ?? '' ) ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'mbi_admin_action' ); ?><input type="hidden" name="action" value="mbi_apply_updates"><input type="hidden" name="update_key" value="<?php echo esc_attr( (string) ( $row['key'] ?? '' ) ); ?>"><button class="button button-secondary" type="submit">Atualizar novo capítulo</button></form></td></tr><?php endforeach; ?>
				</tbody></table></div>
			</section>
			<?php
		}
	}

	private function scan_status_label( string $status, int $count = 0 ): string {
		$labels = array(
			'pending'     => 'Aguardando',
			'processing'  => 'Verificando agora',
			'clean'       => 'Sem novos capítulos',
			'has_updates' => sprintf( '%d novo(s) encontrado(s)', $count ),
			'partial' => 'LISTA PARCIAL — conferência limitada',
			'partial_updates' => sprintf( '%d novo(s) em lista parcial', $count ),
			'error'       => 'Falha na verificação',
		);
		return $labels[ $status ] ?? 'Aguardando';
	}

	private function scan_chapters_label( array $numbers ): string {
		$numbers = array_values( array_filter( array_map( 'sanitize_text_field', $numbers ) ) );
		if ( ! $numbers ) {
			return '—';
		}
		$visible = array_slice( $numbers, 0, 12 );
		$label   = 'Cap. ' . implode( ', ', $visible );
		if ( count( $numbers ) > count( $visible ) ) {
			$label .= sprintf( ' e mais %d', count( $numbers ) - count( $visible ) );
		}
		return $label;
	}

	public function save_settings(): void {
		$this->guard();
		$raw = wp_unslash( $_POST['settings'] ?? array() );
		$existing = $this->importer->settings();
		$source_url = untrailingslashit( esc_url_raw( $raw['source_url'] ?? '' ) );
		if ( 'https' !== wp_parse_url( $source_url, PHP_URL_SCHEME ) ) {
			$this->redirect_notice( 'error', 'O site de origem precisa usar HTTPS.' );
		}

		$proxy_base = untrailingslashit( esc_url_raw( $raw['image_proxy_base_url'] ?? ( $existing['image_proxy_base_url'] ?? 'https://img.lermangaonline.com.br' ) ) );
		if ( $proxy_base && 'https' !== wp_parse_url( $proxy_base, PHP_URL_SCHEME ) ) {
			$this->redirect_notice( 'error', 'A URL pública do Proxy/CDN precisa usar HTTPS.' );
		}
		$proxy_key = trim( (string) ( $raw['image_proxy_key_b64'] ?? '' ) );
		if ( '' === $proxy_key ) {
			$proxy_key = (string) ( $existing['image_proxy_key_b64'] ?? '' );
		}
		if ( $proxy_key ) {
			$decoded = base64_decode( $proxy_key, true );
			if ( ! is_string( $decoded ) || 32 !== strlen( $decoded ) ) {
				$this->redirect_notice( 'error', 'A chave PROXY_KEY_B64 precisa ser Base64 válida de exatamente 32 bytes.' );
			}
		}

		$settings = array(
			'source_url'          => $source_url,
			'allowed_hosts'       => sanitize_textarea_field( $raw['allowed_hosts'] ?? '' ),
			'daily_pages'         => max( 1, min( 30, absint( $raw['update_pages'] ?? 5 ) ) ),
			'update_pages'        => max( 1, min( 30, absint( $raw['update_pages'] ?? 5 ) ) ),
			'updates_enabled'     => 0,
			'auto_catalog_enabled'=> 0,
			'auto_catalog_limit'  => max( 1, min( 500, absint( $raw['auto_catalog_limit'] ?? 10 ) ) ),
			'processing_speed'    => in_array( $raw['processing_speed'] ?? '', array( 'normal', 'fast', 'turbo', 'ultra' ), true ) ? $raw['processing_speed'] : 'ultra',
			'page_storage_mode'   => 'remote',
			'continuous_worker'   => empty( $raw['continuous_worker'] ) ? 0 : 1,
			'jobs_per_run'        => 30,
			'max_attempts'        => max( 1, min( 10, absint( $raw['max_attempts'] ?? 5 ) ) ),
			'convert_webp'        => 0,
			'webp_quality'        => max( 60, min( 95, absint( $raw['webp_quality'] ?? 84 ) ) ),
			'publish_status'      => in_array( $raw['publish_status'] ?? '', array( 'publish', 'draft', 'private' ), true ) ? $raw['publish_status'] : 'publish',
			'max_catalog_pages'   => max( 1, min( 500, absint( $raw['max_catalog_pages'] ?? 80 ) ) ),
			'max_images_chapter'  => max( 1, min( 1000, absint( $raw['max_images_chapter'] ?? 250 ) ) ),
			'image_proxy_enabled'  => empty( $raw['image_proxy_enabled'] ) ? 0 : 1,
			'image_proxy_base_url' => $proxy_base ?: 'https://img.lermangaonline.com.br',
			'image_proxy_key_b64'  => $proxy_key,
		);
		update_option( 'mbi_settings', $settings, false );
		$this->redirect_notice( 'success', 'Configurações salvas.' );
	}

	public function start_full(): void {
		$this->guard();
		$this->importer->enqueue_full_import();
		$this->redirect_notice( 'success', 'Importação completa adicionada à fila.' );
	}

	public function start_30(): void {
		$this->guard();
		$this->importer->enqueue_catalog_import( 30 );
		$this->redirect_notice( 'success', '30 novas obras foram solicitadas para preenchimento do catálogo.' );
	}

	public function start_amount(): void {
		$this->guard();
		$amount = max( 1, min( 500, absint( wp_unslash( $_POST['catalog_amount'] ?? 10 ) ) ) );
		$new_only = 'new' === sanitize_key( wp_unslash( $_POST['catalog_operation'] ?? 'complete' ) );
		$queued = $new_only ? $this->importer->enqueue_new_catalog_import( $amount ) : $this->importer->enqueue_catalog_import( $amount );
		if ( ! $queued ) {
			$this->redirect_notice( 'warning', 'Já existe outro processo na fila. Encerre-o em PARAR TUDO E ZERAR antes de iniciar esta operação. O conteúdo salvo é preservado.' );
		}
		$this->redirect_notice( 'success', sprintf( 'Pedido iniciado: meta de %d mangá(s) publicados. Candidatas bloqueadas serão substituídas sem contar como publicação.', $amount ) );
	}

	public function resume_quantity52(): void {
		$this->guard(); $result = $this->importer->resume_quantity_goal52();
		$this->redirect_notice( ! empty( $result['queued'] ) ? 'success' : 'warning', (string) $result['message'] );
	}

	public function start_url(): void {
		$this->guard();
		$url    = trim( (string) wp_unslash( $_POST['manual_manga_url'] ?? '' ) );
		$result = $this->importer->enqueue_manual_manga_url( $url );
		if ( ! empty( $result['error'] ) ) {
			$this->redirect_notice( 'error', (string) $result['error'] );
		}
		if ( ! empty( $result['already_complete'] ) ) {
			$this->redirect_notice( 'info', 'Esse mangá já existe e está completo. Nenhuma cópia foi criada.' );
		}
		$message = ! empty( $result['existing'] )
			? 'A obra existente foi colocada na fila para ser finalizada, sem criar duplicata.'
			: 'O mangá solicitado foi adicionado à fila. Ele será importado integralmente e publicado quando estiver completo.';
		$this->redirect_notice( 'success', $message );
	}

	public function history_backfill(): void {
		$this->guard();
		$result = $this->importer->enqueue_history_backfill();
		if ( empty( $result['queued'] ) ) {
			$this->redirect_notice( 'error', (string) ( $result['error'] ?? 'Não foi possível iniciar a revisão dos históricos.' ) );
		}
		$this->redirect_notice( 'success', sprintf( 'Revisão iniciada para %d obra(s) publicadas, uma por vez. Acompanhe a obra atual e os erros neste painel.', (int) $result['count'] ) );
	}


	public function cancel_catalog_discovery(): void {
		$this->guard();
		$result = $this->importer->cancel_catalog_discovery( 'Busca de catálogo cancelada pelo administrador para liberar ações manuais.' );
		$this->redirect_notice(
			'success',
			sprintf( 'Busca do catálogo interrompida. %d job(s) de descoberta foram cancelados. Obras e capítulos já gravados foram preservados.', (int) ( $result['cancelled_jobs'] ?? 0 ) )
		);
	}

	/** Adiciona ou remove uma obra da seleção manual de destaques da home. */
	public function toggle_featured(): void {
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id || 'mbi_manga' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Você não tem permissão para alterar este mangá.' );
		}
		check_admin_referer( 'mbi_toggle_featured_' . $post_id );

		$is_featured = '1' === (string) get_post_meta( $post_id, '_mbi_featured', true );
		if ( $is_featured ) {
			delete_post_meta( $post_id, '_mbi_featured' );
			delete_post_meta( $post_id, '_mbi_featured_at' );
		} else {
			update_post_meta( $post_id, '_mbi_featured', 1 );
			update_post_meta( $post_id, '_mbi_featured_at', time() );
			$featured_ids = get_posts(
				array(
					'post_type'      => 'mbi_manga',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_mbi_featured_at',
					'meta_query'     => array(
						array( 'key' => '_mbi_featured', 'value' => '1', 'compare' => '=' ),
					),
					'orderby'        => 'meta_value_num',
					'order'          => 'DESC',
				)
			);
			foreach ( array_slice( $featured_ids, 5 ) as $old_featured_id ) {
				delete_post_meta( $old_featured_id, '_mbi_featured' );
				delete_post_meta( $old_featured_id, '_mbi_featured_at' );
			}
		}

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'edit.php?post_type=mbi_manga' );
		}
		wp_safe_redirect( add_query_arg( 'mbi_featured', $is_featured ? 'removed' : 'added', $redirect ) );
		exit;
	}

	/** Insere a coluna de controle visual na listagem de mangás. */
	public function manga_columns( array $columns ): array {
		$output = array();
		foreach ( $columns as $key => $label ) {
			$output[ $key ] = $label;
			if ( 'title' === $key ) {
				$output['mbi_featured'] = 'Destaque';
			}
		}
		return $output;
	}

	/** Exibe um botão sempre visível para destacar ou remover a obra da home. */
	public function manga_column( string $column, int $post_id ): void {
		if ( 'mbi_featured' !== $column ) {
			return;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			echo '<span class="mbi-featured-unavailable">Publique primeiro</span>';
			return;
		}
		$is_featured = '1' === (string) get_post_meta( $post_id, '_mbi_featured', true );
		?>
		<form class="mbi-featured-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mbi_toggle_featured">
			<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>">
			<?php wp_nonce_field( 'mbi_toggle_featured_' . $post_id ); ?>
			<?php if ( $is_featured ) : ?>
				<span class="mbi-featured-status">Em destaque</span>
				<button type="submit" class="button mbi-featured-remove">Remover</button>
			<?php else : ?>
				<button type="submit" class="button button-primary mbi-featured-add">Destacar</button>
			<?php endif; ?>
		</form>
		<?php
	}

	public function check_updates(): void {
		$this->guard();
		try {
			$result = $this->importer->detect_updates_now();
		} catch ( Throwable $exception ) {
			$this->db->log( 'error', 'Falha na detecção manual de novos capítulos: ' . $exception->getMessage() );
			$this->redirect_notice( 'error', 'A detecção falhou: ' . $exception->getMessage(), 'manga-bridge-updates' );
		}
		$this->redirect_notice( 'success', sprintf( 'Detecção concluída: %d mangás verificados, %d capítulos pendentes (%d novos nesta leitura). Nada foi publicado automaticamente.', (int) $result['mangas'], (int) $result['pending'], (int) $result['new'] ), 'manga-bridge-updates' );
	}

	public function apply_updates(): void {
		$this->guard();
		$key      = sanitize_key( (string) wp_unslash( $_POST['update_key'] ?? '' ) );
		$manga_id = absint( wp_unslash( $_POST['manga_id'] ?? 0 ) );
		$mode     = sanitize_key( (string) wp_unslash( $_POST['apply_mode'] ?? '' ) );
		$keys     = $key ? array( $key ) : array();
		$result   = $this->importer->enqueue_detected_updates( $keys, 'all' === $mode ? 0 : $manga_id );
		if ( ! empty( $result['error'] ) ) {
			$this->redirect_notice( 'warning', (string) $result['error'], 'manga-bridge-updates' );
		}
		$this->redirect_notice( 'success', sprintf( '%d capítulo(s) selecionado(s). O painel exibirá mangá, capítulo e porcentagem em tempo real.', (int) $result['queued'] ), 'manga-bridge-updates' );
	}

	public function stop_all(): void {
		$this->guard();
		$result = $this->importer->stop_all_and_reset();
		$page = sanitize_key( (string) wp_unslash( $_POST['return_page'] ?? 'manga-bridge-importer' ) );
		if ( ! in_array( $page, array( 'manga-bridge-importer', 'manga-bridge-updates' ), true ) ) {
			$page = 'manga-bridge-importer';
		}
		$this->redirect_notice( 'success', sprintf( 'Processo interrompido e motor zerado. %d jobs foram removidos; obras e capítulos já gravados foram preservados.', (int) $result['removed_jobs'] ), $page );
	}

	public function skip_current_manga(): void {
		$this->guard();
		$result = $this->importer->skip_current_serial_manga();
		if ( empty( $result['skipped'] ) ) {
			$this->redirect_notice( 'warning', (string) ( $result['error'] ?? 'Nenhuma obra serial está ativa para ser pulada.' ) );
		}
		$this->redirect_notice( 'success', sprintf( 'Obra atual pulada e mantida em revisão. %d job(s) dela foram encerrados; o importador avançou para a próxima.', (int) ( $result['removed_jobs'] ?? 0 ) ) );
	}


	public function validate_drafts(): void {
		$this->guard();
		$result = $this->importer->enqueue_validate_drafts();
		if ( empty( $result['queued'] ) ) {
			$this->redirect_notice( 'info', 'Nenhum mangá incompleto com URL de origem foi encontrado para finalizar.' );
		}
		$this->redirect_notice( 'success', sprintf( '%d mangás incompletos serão validados e finalizados, um por vez. A sincronização normal ficou separada deste processo.', (int) $result['queued'] ) );
	}

	public function run_now(): void {
		$this->guard();
		$summary = $this->importer->process_queue();
		if ( ! empty( $summary['errors'] ) ) {
			$message = sprintf(
				'O lote tentou %d jobs: %d concluídos, %d aguardando nova tentativa e %d com falha. Erro: %s',
				(int) $summary['claimed'],
				(int) $summary['completed'],
				(int) $summary['retrying'],
				(int) $summary['failed'],
				(string) $summary['errors'][0]
			);
			$this->redirect_notice( 'warning', $message );
		}
		if ( empty( $summary['claimed'] ) ) {
			$this->redirect_notice( 'info', 'Nenhum job estava disponível neste momento. Veja abaixo a próxima tentativa agendada.' );
		}
		$this->redirect_notice( 'success', sprintf( '%d jobs foram concluídos neste lote.', (int) $summary['completed'] ) );
	}

	public function force_retry_now(): void {
		$this->guard();
		$result = $this->importer->force_next_retry_now();
		if ( empty( $result['woken'] ) ) {
			$this->redirect_notice( 'info', 'Não existe retentativa agendada no lote atual para liberar.' );
		}
		$this->redirect_notice( 'success', 'A próxima retentativa foi liberada agora. Somente o job pendente será reexecutado; o conteúdo já concluído foi preservado.' );
	}

	public function recover_queue(): void {
		$this->guard();
		$result = $this->importer->recover_queue( true );
		$this->importer->kick_worker();
		$this->redirect_notice(
			'success',
			sprintf(
				'Autocura executada: %d pendências órfãs removidas, %d jobs acordados e job atual recriado: %s. O conteúdo existente foi preservado.',
				(int) ( $result['orphan_pending_removed'] ?? 0 ),
				(int) ( $result['fresh_jobs_woken'] ?? 0 ),
				! empty( $result['current_job_recreated'] ) ? 'sim' : 'não'
			)
		);
	}


	public function hard_reset_repair(): void {
		$this->guard();
		$result = $this->importer->hard_reset_and_restart_drafts();
		if ( empty( $result['queued'] ) ) {
			$this->redirect_notice( 'warning', sprintf( 'Motor zerado (%d jobs removidos), mas nenhum rascunho com URL de origem foi encontrado.', (int) $result['removed_jobs'] ) );
		}
		$this->redirect_notice(
			'success',
			sprintf(
				'RESET concluído: %d jobs antigos removidos. Novo lote criado com %d rascunhos. %d sem URL foram ignorados. A primeira obra foi liberada imediatamente.',
				(int) $result['removed_jobs'],
				(int) $result['queued'],
				(int) $result['skipped']
			)
		);
	}


	public function cleanup_duplicates(): void {
		$this->guard();
		$result = $this->importer->repair_duplicate_state();
		$this->redirect_notice(
			'success',
			sprintf(
				'Deduplicação concluída: %d grupos corrigidos, %d mangás duplicados movidos para a lixeira, %d capítulos reaproveitados/reassociados. O estado de pausa da importação foi preservado.',
				(int) ( $result['groups'] ?? 0 ),
				(int) ( $result['manga_trashed'] ?? 0 ),
				(int) ( ( $result['chapters_reparented'] ?? 0 ) + ( $result['chapters_trashed'] ?? 0 ) )
			)
		);
	}


	public function revalidate_suspicious(): void {
		$this->guard();
		$result = $this->importer->revalidate_suspicious_mangas( 1000 );
		$this->redirect_notice(
			'success',
			sprintf(
				'Validação concluída: %d obras verificadas, %d suspeitas bloqueadas para revisão e %d reprocessamentos agendados.',
				(int) ( $result['scanned'] ?? 0 ),
				(int) ( $result['flagged'] ?? 0 ),
				(int) ( $result['queued'] ?? 0 )
			)
		);
	}

	public function cleanup_legacy_media(): void {
		$this->guard();
		$status = $this->importer->start_legacy_media_cleanup( false );
		$this->importer->cleanup_legacy_media_batch( 80 );
		$status = $this->importer->legacy_media_cleanup_status();
		$this->redirect_notice(
			'success',
			sprintf(
				'Limpeza iniciada: %d imagens antigas já removidas; %d ainda aguardam os próximos lotes. Somente mídia identificada como criada pelo Mangá Bridge será excluída.',
				(int) $status['deleted'],
				(int) $status['remaining']
			)
		);
	}

	public function ajax_cleanup_media_tick(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		check_ajax_referer( 'mbi_cleanup_media_tick', 'nonce' );
		@ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 30 );
		}
		$status = $this->importer->cleanup_legacy_media_batch( 60 );
		wp_send_json_success( array( 'cleanup' => $status ) );
	}

	public function start_proxy_migration(): void {
		$this->guard();
		try {
			$status = $this->importer->start_proxy_migration( false );
		} catch ( Throwable $e ) {
			$this->redirect_notice( 'error', $e->getMessage() );
		}
		$this->redirect_notice(
			'success',
			sprintf(
				'Migração Proxy/CDN agendada em segundo plano: %d posts verificados até agora, %d mangás e %d capítulos atualizados, %d URLs protegidas. O painel continuará em lotes leves sem baixar imagens.',
				(int) ( $status['posts_scanned'] ?? 0 ),
				(int) ( $status['mangas_migrated'] ?? 0 ),
				(int) ( $status['chapters_migrated'] ?? 0 ),
				(int) ( $status['urls_migrated'] ?? 0 )
			)
		);
	}

	public function test_image_proxy(): void {
		$this->guard();
		$result = $this->importer->test_image_proxy();
		$this->redirect_notice( ! empty( $result['ok'] ) ? 'success' : 'error', (string) ( $result['message'] ?? 'Teste sem resposta.' ) );
	}

	public function repair_invalid_covers(): void {
		$this->guard();
		$result = $this->importer->queue_invalid_cover_repairs( 1000 );
		if ( ! empty( $result['error'] ) ) {
			$this->redirect_notice( 'error', (string) $result['error'] );
		}
		$this->redirect_notice(
			'success',
			sprintf(
				'Reparo de capas preparado: %d obras verificadas e %d capas inválidas enfileiradas. Capítulos existentes foram preservados.',
				(int) ( $result['scanned'] ?? 0 ),
				(int) ( $result['queued'] ?? 0 )
			)
		);
	}


	public function repair_invalid_chapter_pages(): void {
		$this->guard();
		$result = $this->importer->queue_invalid_chapter_page_repairs( 500 );
		if ( ! empty( $result['error'] ) ) {
			$this->redirect_notice( 'error', (string) $result['error'] );
		}
		$this->redirect_notice(
			'success',
			sprintf(
				'Reparo de páginas preparado: %d capítulos suspeitos localizados e %d enfileirados para releitura segura da origem.',
				(int) ( $result['scanned'] ?? 0 ),
				(int) ( $result['queued'] ?? 0 )
			)
		);
	}

	public function ajax_prepare_proxy_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		check_ajax_referer( 'mbi_prepare_proxy_test', 'nonce' );
		$result = $this->importer->prepare_image_proxy_test();
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? 'Não foi possível preparar o teste.' ) ) );
		}
		wp_send_json_success( array(
			'message'   => (string) ( $result['message'] ?? '' ),
			'proxy_url' => esc_url_raw( (string) ( $result['proxy_url'] ?? '' ) ),
		) );
	}

	public function ajax_prepare_proxy_origin_audit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		check_ajax_referer( 'mbi_prepare_proxy_origin_audit', 'nonce' );
		$result = $this->importer->prepare_image_proxy_origin_audit( 12 );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? 'Não foi possível preparar a auditoria.' ) ) );
		}
		wp_send_json_success( array( 'samples' => array_values( (array) ( $result['samples'] ?? array() ) ) ) );
	}

	public function ajax_proxy_migration_tick(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		check_ajax_referer( 'mbi_proxy_migration_tick', 'nonce' );
		@ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 20 );
		}
		$status = $this->importer->migrate_proxy_batch( 25 );
		wp_send_json_success( array( 'migration' => $status ) );
	}

	/** Retentativa limitada das falhas de rede nos capítulos NOVOS do lote manual. */
	public function retry_manual_network(): void {
		$this->guard();
		$state = (array) get_option( 'mbi_manual_update_batch', array() );
		$batch = sanitize_text_field( (string) ( $state['id'] ?? '' ) );
		if ( '' === $batch || ! empty( $state['preparing'] ) ) {
			$this->redirect_notice( 'warning', 'Não há lote manual preparado para recuperar.' );
		}
		$result = $this->db->retry_manual_network_failures( $batch, 12 );
		if ( $result['requeued'] > 0 ) {
			// A seleção anterior permanece igual: não executamos novamente a detecção.
			$state['active'] = true;
			$state['finished_at'] = '';
			$state['updated_at'] = current_time( 'mysql' );
			update_option( 'mbi_manual_update_batch', $state, false );
			if ( ! $this->importer->is_paused() ) { $this->importer->kick_worker(); }
		}
		$this->redirect_notice( 'success', sprintf(
			'%d falhas transitórias reenfileiradas (máximo 12 por clique); %d elegíveis neste exame; %d atingiram limite de 2 recuperações. Falhas de estrutura/imagem inválida continuam bloqueadas. %s',
			(int) $result['requeued'], (int) $result['eligible'], (int) $result['already_retried'],
			$this->importer->is_paused() ? 'A fila está pausada; retome quando desejar.' : 'O Cron retomará o processamento.'
		) );
	}

	public function retry_failed(): void {
		$this->guard();
		$count = $this->db->retry_failed();
		$this->redirect_notice( 'success', sprintf( '%d jobs retornaram para a fila.', $count ) );
	}

	public function pause(): void {
		$this->guard();
		$this->importer->pause();
		$this->redirect_notice( 'success', 'Importação pausada. Nenhum novo job será iniciado até você clicar em “Retomar importação”.' );
	}

	public function resume(): void {
		$this->guard();
		$this->importer->resume();
		$this->importer->kick_worker();
		$this->redirect_notice( 'success', 'Processamento retomado.' );
	}

	public function cancel_pending(): void {
		$this->guard();
		$count = $this->importer->cancel_pending();
		$this->redirect_notice( 'success', sprintf( '%d jobs pendentes foram cancelados. O conteúdo já importado foi preservado.', $count ) );
	}

	public function clear_completed(): void {
		$this->guard();
		$count = $this->db->clear_completed();
		$this->redirect_notice( 'success', sprintf( '%d registros concluídos foram removidos do histórico.', $count ) );
	}

	public function test_connection(): void {
		$this->guard();
		$result = $this->importer->test_connection();
		$message = $result['message'];
		if ( $result['ok'] ) {
			$message .= sprintf( ' Mangás reconhecidos nesta página: %d. Última página detectada: %d.', $result['mangas'], $result['max_page'] );
		}
		$this->redirect_notice( $result['ok'] ? 'success' : 'error', $message );
	}

	public function ajax_progress(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		check_ajax_referer( 'mbi_progress', 'nonce' );
		$data = $this->progress_data();
		// Sincronização solicitada após a publicação terminar, sem recarregar o HTML.
		if ( isset( $_POST['include_updates'] ) && '1' === (string) $_POST['include_updates'] ) {
			$data['updates'] = $this->importer->detected_updates();
		}
		wp_send_json_success( $data );
	}

	public function ajax_update_progress(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		check_ajax_referer( 'mbi_update_progress', 'nonce' );
		wp_send_json_success( $this->manual_progress_data() );
	}

	public function ajax_start_update_scan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		check_ajax_referer( 'mbi_start_update_scan', 'nonce' );
		$scan = $this->importer->start_update_scan();
		if ( ! empty( $scan['error'] ) ) {
			wp_send_json_error( array( 'message' => (string) $scan['error'] ) );
		}
		wp_send_json_success( array( 'scan' => $scan, 'updates' => $this->importer->detected_updates() ) );
	}

	public function ajax_update_scan_tick(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		check_ajax_referer( 'mbi_update_scan_tick', 'nonce' );
		@ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 50 );
		}
		$scan = $this->importer->process_update_scan_tick();
		wp_send_json_success( array( 'scan' => $scan, 'updates' => $this->importer->detected_updates() ) );
	}


	public function ajax_prepare_manual_update_tick(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		check_ajax_referer( 'mbi_prepare_manual_update_tick', 'nonce' );
		@ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 15 );
		}
		$this->importer->prepare_manual_update_tick( 40, 180 );
		wp_send_json_success( array( 'manual' => $this->manual_progress_data() ) );
	}

	public function download_error_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acesso negado.' );
		}
		check_admin_referer( 'mbi_download_error_log' );
		$filename = 'manga-bridge-erros-' . gmdate( 'Y-m-d-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$out = fopen( 'php://output', 'wb' );
		if ( false === $out ) {
			wp_die( 'Não foi possível gerar o arquivo.' );
		}
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'data_utc', 'mensagem', 'url', 'contexto_json' ), ';', '"', '' );
		foreach ( array_reverse( $this->db->error_logs( 10000 ) ) as $log ) {
			$context = json_decode( (string) $log->context, true ) ?: array();
			fputcsv( $out, array( $log->created_at, $log->message, (string) ( $context['url'] ?? $context['chapter_url'] ?? '' ), wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), ';', '"', '' );
		}
		fclose( $out );
		exit;
	}

	public function ajax_accelerate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		check_ajax_referer( 'mbi_accelerate', 'nonce' );
		@ignore_user_abort( true );
		$summary = $this->importer->process_queue();
		wp_send_json_success( array( 'summary' => $summary, 'progress' => $this->progress_data() ) );
	}

	private function order_display( array $serial ): array {
		$has_order = ! empty( $serial['id'] ) && (int) ( $serial['target'] ?? 0 ) > 0;
		$target = $has_order ? (int) $serial['target'] : 0;
		$done = min( $target, max( 0, (int) ( $serial['completed'] ?? 0 ) ) );
		return array( 'label' => ! empty( $serial['active'] ) ? 'Pedido de mangás' : 'Último pedido de mangás',
			'text' => $has_order ? sprintf( '%d/%d concluídos', $done, $target ) : 'Nenhum pedido',
			'skipped' => $has_order ? max( 0, (int) ( $serial['skipped'] ?? 0 ) ) : 0 );
	}

	/** Read the compact batch checkpoints; no post, image or queue scans. */
	private function pipeline_display51( array $s ): array {
		if ( 'collect51' !== ( $s['workflow'] ?? '' ) ) { return array(); }
		$total = count( (array) ( $s['items'] ?? array() ) );
		$collected = min( $total, (int) ( $s['capture_done'] ?? 0 ) );
		$verified = min( $total, (int) ( $s['verified'] ?? 0 ) );
		$published = min( $total, (int) ( $s['completed'] ?? 0 ) );
		$phase = (string) ( $s['phase'] ?? 'collect' );
		if ( ! empty( $s['waiting_catalog'] ) ) { $phase = 'select'; }
		elseif ( empty( $s['active'] ) && 'done' !== $phase ) { $phase = 'stopped'; }
		$pending = 0; $rows = array();
		$labels = array( 'selected' => 'Aguardando coleta', 'collected' => 'Coletada', 'validating' => 'Validando metadados e imagens', 'published' => 'Publicada', 'pending' => 'Pendente de revisão' );
		foreach ( (array) ( $s['items'] ?? array() ) as $n => $item ) {
			$row = (array) ( $s['rows'][$n] ?? array() );
			$status = (string) ( $row['status'] ?? 'selected' );
			if ( 'pending' === $status ) { $pending++; }
			$rows[] = array( 'title' => (string) ( $row['title'] ?? '' ) ?: (string) $item['url'], 'status' => $labels[$status] ?? $status, 'detail' => (string) ( $row['reason'] ?? '' ) ?: ( isset( $row['chapters'] ) ? sprintf( '%d capítulos coletados', (int) $row['chapters'] ) : '—' ) );
		}
		$bars = array(); $counts = array();
		foreach ( array( 'collect' => $collected, 'validate' => $verified, 'publish' => $published ) as $key => $n ) { $bars[$key] = (int) floor( 100 * $n / max( 1, $total ) ); $counts[$key] = $n . '/' . $total; }
		$requested = max( 1, (int) ( $s['target'] ?? $total ) );
		$bars['publish'] = min( 100, (int) floor( 100 * $published / $requested ) ); $counts['publish'] = $published . '/' . $requested;
		return array( 'can_resume' => empty( $s['active'] ) && empty( $s['waiting_catalog'] ) && $published < $requested && in_array( $s['mode'] ?? '', array( 'catalog', 'auto_catalog' ), true ), 'mode' => (string) ( $s['mode'] ?? '' ), 'phase' => $phase, 'total' => $total, 'requested' => (int) ( $s['target'] ?? $total ), 'captured' => (int) ( $s['captured'] ?? 0 ), 'verified' => $verified, 'published' => $published, 'pending' => $pending, 'rows' => $rows, 'bars' => $bars, 'counts' => $counts );
	}

	/** Display totals only: never used by the publication gate or queue claims. */
	private function catalog_display_totals(): array {
		$cached = get_transient( 'mbi_catalog_display46' );
		if ( is_array( $cached ) ) { return $cached; }
		$manga = wp_count_posts( 'mbi_manga' ); $chapter = wp_count_posts( 'mbi_chapter' );
		$ready = new WP_Query( array( 'post_type' => 'mbi_manga', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_mbi_ready', 'meta_value' => '1', 'update_post_meta_cache' => false, 'update_post_term_cache' => false ) );
		$out = array( 'created' => $manga ? array_sum( array_map( 'intval', (array) $manga ) ) - (int) ( $manga->trash ?? 0 ) - (int) ( $manga->{'auto-draft'} ?? 0 ) : 0,
			'ready' => max( (int) ( $manga->publish ?? 0 ), (int) $ready->found_posts ), 'chapters' => (int) ( $chapter->publish ?? 0 ) );
		wp_reset_postdata(); set_transient( 'mbi_catalog_display46', $out, MINUTE_IN_SECONDS );
		return $out;
	}

	private function progress_data(): array {
		$dashboard = $this->db->dashboard_counts();
		$counts = $dashboard['counts']; $type_counts = $dashboard['types'];
		$totals = $this->catalog_display_totals();
		$created_total = $totals['created']; $ready_total = $totals['ready']; $chapters = $totals['chapters'];
		$images = (int) ( $type_counts['image']['completed'] ?? 0 );
		$active     = (int) $counts['pending'] + (int) $counts['processing'];
		$failed     = (int) $counts['failed'];
		$paused     = $this->importer->is_paused();
		$serial     = $this->importer->serial_state();
		$serial_on  = ! empty( $serial['active'] );
		$retry = $this->db->latest_retry( $serial_on ? (string) ( $serial['id'] ?? '' ) : '', $serial_on ? (int) ( $serial['current_manga_id'] ?? 0 ) : 0 );
		$catalog_discovery = $this->importer->catalog_discovery_status();
		$catalog_discovery_on = ! empty( $catalog_discovery['active'] );
		$runtime    = $this->importer->runtime_status();

		$target  = max( 1, $created_total );
		$ready   = min( $target, $ready_total );
		$created = $created_total;
		$percent = $created_total > 0 ? min( 100, (int) floor( ( $ready_total / $created_total ) * 100 ) ) : 0;
		$current_chapter_total = 0;
		$current_chapter_done  = 0;

		if ( $serial_on ) {
			$target  = max( 1, (int) ( $serial['target'] ?? 1 ) );
			$ready   = min( $target, (int) ( $serial['completed'] ?? 0 ) );
			$created = min( $target, count( (array) ( $serial['items'] ?? array() ) ) );
			$percent = min( 100, (int) floor( ( $ready / $target ) * 100 ) );
			$current_manga_id = absint( $serial['current_manga_id'] ?? 0 );
			if ( $current_manga_id && ! empty( $serial['id'] ) ) {
				$current_counts = $this->db->batch_chapter_counts( (string) $serial['id'], $current_manga_id );
				$current_chapter_total = array_sum( array_map( 'intval', $current_counts ) );
				$current_chapter_done  = (int) ( $current_counts['completed'] ?? 0 );
				if ( $current_chapter_total > 0 ) {
					$fraction = min( 1, $current_chapter_done / $current_chapter_total );
					$percent = min( 99, (int) floor( ( ( $ready + $fraction ) / $target ) * 100 ) );
				}
			}
			if ( 'validation' === (string) ( $serial['mode'] ?? '' ) ) {
				$checked = min( count( (array) ( $serial['items'] ?? array() ) ), absint( $serial['cursor'] ?? 0 ) );
				$percent = min( 99, (int) floor( 100 * $checked / max( 1, count( (array) ( $serial['items'] ?? array() ) ) ) ) );
			}
		}
		if ( ( $active > 0 || $failed > 0 ) && $percent >= 100 ) {
			$percent = 99;
		}

		if ( $serial_on ) {
			$mode = (string) ( $serial['mode'] ?? 'serial' );
			$labels = array( 'validation' => 'REPARO', 'validation_amount' => 'CONCLUSÃO PRIORITÁRIA', 'catalog' => 'CATÁLOGO', 'catalog_full' => 'CATÁLOGO COMPLETO', 'auto_catalog' => 'CATÁLOGO AUTOMÁTICO', 'updates' => 'ATUALIZAÇÕES', 'manual_url' => 'IMPORTAÇÃO POR URL', 'history_backfill' => 'HISTÓRICO DE CAPÍTULOS' );
			$status = $labels[ $mode ] ?? 'PROCESSAMENTO SERIAL';
			$state  = 'running';
			if ( 'updates' === $mode ) {
				$title = 'Atualizando apenas obras que já existem';
				$message = sprintf( '%d de %d obras recentes verificadas. Capítulos novos só são publicados depois que todas as páginas chegarem.', $ready, $target );
			} else {
				$title = 'history_backfill' === $mode ? 'Conferindo capítulos antigos, 1 mangá por vez' : 'Finalizando 1 mangá completo por vez';
				$message = $current_chapter_total > 0
					? sprintf( '%d de %d capítulos da obra atual processados. %d mangá(s) concluído(s) de %d.', $current_chapter_done, $current_chapter_total, $ready, $target )
					: sprintf( '%d concluídos de %d. Preparando a lista de capítulos da obra atual.', $ready, $target );
				if ( 'validation' === $mode ) {
					$title = 'Revisando rascunhos existentes, 1 mangá por vez';
					$message = sprintf( '%d de %d candidatos verificados; %d obra(s) concluída(s). A barra mostra a revisão dos candidatos, não a quantidade publicada. ', absint( $serial['cursor'] ?? 0 ), count( (array) ( $serial['items'] ?? array() ) ), $ready ) . $message;
				}
				if ( $retry ) {
					$message .= sprintf( ' Retentativa da tarefa #%d (%s), tentativa %d, para %s. Motivo: %s.', (int) $retry->id, (string) $retry->job_type, (int) $retry->attempts, get_date_from_gmt( $retry->available_at, 'd/m/Y H:i:s' ), (string) $retry->last_error );
				}
			}
		} elseif ( $catalog_discovery_on ) {
			$page = max( 1, (int) ( $catalog_discovery['page'] ?? 1 ) );
			$page_limit = max( 1, (int) ( $catalog_discovery['page_limit'] ?? 1 ) );
			$done_pages = max( 0, min( $page_limit, $page - 1 ) );
			$percent = min( 99, (int) floor( ( $done_pages / $page_limit ) * 100 ) );
			$status = 'BUSCA DO CATÁLOGO';
			$state = 'running';
			$title = 'Procurando novas obras sem bloquear o importador';
			$message = sprintf(
				'Página %d de até %d; %d obra(s) nova(s) candidatas encontradas. A busca lê só uma página por rodada. Importar por URL tem prioridade e pode interromper apenas esta busca.',
				$page,
				$page_limit,
				count( (array) ( $catalog_discovery['items'] ?? array() ) )
			);
			if ( ! empty( $serial['waiting_catalog'] ) && (string) ( $catalog_discovery['resume_serial_batch'] ?? '' ) === (string) ( $serial['id'] ?? '' ) ) {
				$target = max( 1, (int) $serial['target'] );
				$ready = min( $target, (int) ( $serial['completed'] ?? 0 ) );
				$created = min( $target, count( (array) ( $serial['items'] ?? array() ) ) );
				$percent = min( 99, (int) floor( 100 * $ready / $target ) );
				$status = 'BUSCANDO SUBSTITUTOS';
				$title = 'A meta de importação continua em andamento';
				$message = sprintf( '%d de %d mangás concluídos. As obras bloqueadas não contam para a meta. Buscando outras candidatas na página %d de até %d.', $ready, $target, $page, $page_limit );
			}
		} elseif ( $paused ) {
			$state = 'warning'; $status = 'PAUSADA'; $title = 'Processamento pausado';
			$message = 'A fila foi preservada. Clique em “Retomar importação” para continuar.';
		} elseif ( $active > 0 ) {
			$state = $failed > 0 ? 'warning' : 'running';
			$status = $retry ? 'NOVA TENTATIVA AGENDADA' : 'EM ANDAMENTO';
			$title = $retry ? 'Um job falhou e voltará a ser executado' : 'Processador trabalhando';
			$message = $retry ? sprintf( 'Tentativa %d. Próxima execução após %s. Motivo: %s', (int) $retry->attempts, get_date_from_gmt( $retry->available_at, 'd/m/Y H:i:s' ), (string) $retry->last_error ) : 'O worker contínuo está processando a fila; o WP-Cron de 1 minuto permanece como segurança.';
		} elseif ( $failed > 0 ) {
			// Sem trabalho ativo, a porcentagem de obras prontas não é progresso da fila.
			// Mostrar 71%, 78% etc. aqui fazia parecer que o mesmo processo estava travado.
			$percent = 0;
			$state = 'failed'; $status = 'AÇÃO NECESSÁRIA'; $title = 'Existe uma falha isolada na fila';
			$message = sprintf( 'Existem %d job(s) com falha e nenhum job em execução. O catálogo tem %d de %d obras prontas; esse número é uma métrica do catálogo, não o progresso desta falha.', $failed, $ready_total, $created_total );
		} elseif ( $created_total > 0 ) {
			$percent = 0;
			$state = 'idle'; $status = 'ESTÁGIO ZERO'; $title = 'Nenhum processo em andamento';
			$message = sprintf( 'Catálogo atual: %d obras criadas e %d prontas/publicadas. Novos capítulos só são detectados e publicados por ação manual.', $created_total, $ready_total );
		} else {
			$state = 'idle'; $status = 'AGUARDANDO'; $title = 'Catálogo ainda vazio';
			$message = 'Escolha a quantidade em “Preencher catálogo” para importar as primeiras obras completas.';
		}

		if ( $serial_on && ! empty( $serial['skipped'] ) ) {
			$message .= sprintf( ' %d candidata(s) ignorada(s) neste pedido.', (int) $serial['skipped'] );
			if ( ! empty( $serial['last_skipped']['error'] ) ) { $message .= ' Último motivo: ' . (string) $serial['last_skipped']['error']; }
		}
		if ( ! $serial_on && ! $catalog_discovery_on && ! $active && ! $paused && ! empty( $serial['finished_at'] ) && ! empty( $serial['skipped'] ) ) {
			$state = 'warning'; $status = 'ENCERRADO COM OBRAS IGNORADAS'; $title = 'Lote encerrado; algumas obras exigem revisão';
			$message = sprintf( '%d obra(s) concluída(s), %d ignorada(s). Meta solicitada: %d. Nenhum conteúdo existente foi apagado. Motivo da última obra ignorada: %s', (int) ( $serial['completed'] ?? 0 ), (int) $serial['skipped'], (int) ( $serial['target'] ?? 0 ), (string) ( $serial['last_skipped']['error'] ?? '' ) );
		}
		if ( ! $serial_on && ! $catalog_discovery_on && ! $active && ! $paused && ! empty( $serial['finish_reason'] ) && in_array( (string) ( $serial['mode'] ?? '' ), array( 'catalog', 'auto_catalog', 'validation_amount' ), true ) ) {
			$target = max( 1, (int) ( $serial['target'] ?? 1 ) );
			$ready = min( $target, (int) ( $serial['completed'] ?? 0 ) );
			$percent = (int) floor( 100 * $ready / $target );
			$reached = 'target_reached' === $serial['finish_reason'];
			$state = $reached ? 'completed' : 'warning';
			$status = $reached ? 'META ATINGIDA' : 'META NÃO ATINGIDA';
			$title = $reached ? 'Importação solicitada concluída' : 'A busca terminou com vagas não preenchidas';
			$reason = 'source_unavailable' === $serial['finish_reason'] ? 'A origem ficou indisponível; tente novamente mais tarde.' : 'Não foram encontradas mais candidatas utilizáveis nas páginas configuradas.';
			$message = sprintf( '%d de %d mangás concluídos; %d vaga(s) restante(s). %d obra(s) ignorada(s). ', $ready, $target, max( 0, $target - $ready ), (int) ( $serial['skipped'] ?? 0 ) ) . ( $reached ? 'As obras bloqueadas foram substituídas sem consumir vagas.' : $reason );
		}
		if ( $paused && ( $serial_on || $catalog_discovery_on || $active ) ) {
			$state = 'warning'; $status = 'PAUSADA'; $title = 'Processamento pausado';
			$message = 'hosting-dns-pressure' === ( $runtime['stage'] ?? '' ) ? 'O resolvedor DNS da hospedagem falhou repetidamente. Conteúdo e tarefa foram preservados. Baixe o diagnóstico global antes de retomar.' : 'A fila foi preservada. Clique em “Retomar importação” para continuar.';
			if ( 'source-compatibility' === ( $runtime['stage'] ?? '' ) ) { $title = 'Carregador da origem incompatível'; $message = (string) $runtime['message'] . ' Nenhuma outra candidata será descartada enquanto a fila estiver pausada.'; }
		}
		$current_title = '';
		if ( $serial_on && ! empty( $serial['current_manga_id'] ) ) {
			$current_title = get_the_title( absint( $serial['current_manga_id'] ) );
		}
		$activity = (string) ( $runtime['message'] ?? '' );
		if ( $current_title ) {
			$activity = $current_title . ( $activity ? ' — ' . $activity : '' );
		}
		$image_progress = (array) get_option( 'mbi_image_validation_progress', array() );
		if ( ! $paused && ! empty( $image_progress['post_id'] ) && ( $image_progress['generation'] ?? '' ) === (string) get_option( 'mbi_process_generation', '' ) && ( $image_progress['at'] ?? 0 ) > time() - 120 && ( ! $serial_on || (int) ( $serial['current_manga_id'] ?? 0 ) === (int) ( $image_progress['parent'] ?? 0 ) ) && get_post_meta( (int) $image_progress['post_id'], '_mbi_delivery_candidate', true ) ) {
			$activity = 'Validando ' . get_the_title( (int) $image_progress['post_id'] ) . ': ' . (int) $image_progress['cursor'] . '/' . (int) $image_progress['pages'] . ' imagens conferidas. ' . $activity;
		}
		$heartbeat = ! empty( $runtime['updated_at'] ) ? wp_date( 'H:i:s', (int) $runtime['updated_at'] ) : '—';
		$worker_wait = $this->importer->worker_wait_seconds();
		if ( ! $paused && $worker_wait > 0 && ( $serial_on || $catalog_discovery_on || $active > 0 ) ) {
			$state = 'warning';
			$status = $retry ? 'NOVA TENTATIVA AGENDADA' : 'INTERVALO ENTRE RODADAS';
			$activity = sprintf( 'Próxima rodada em %d s (%s). Última atividade: %s', $worker_wait, wp_date( 'H:i:s', time() + $worker_wait ), $activity );
		}

		$metrics = (array) get_option( 'mbi_worker_metrics46', array() );
		$load = $metrics ? sprintf( 'Última rodada: %s s · %d consultas · pico de %s MB', number_format( (float) $metrics['seconds'], 1, ',', '.' ), (int) $metrics['queries'], number_format( (float) $metrics['peak_mb'], 1, ',', '.' ) ) : 'Medição disponível após a próxima rodada com trabalho.';
		if ( isset( $metrics['http'] ) ) { $load .= sprintf( ' · %d chamadas HTTP · %d chamadas ao Worker bloqueadas', (int) $metrics['http'], (int) ( $metrics['worker_blocked'] ?? 0 ) ); }
		$order = $this->order_display( $serial );
		$related_catalog = $catalog_discovery_on && ( $catalog_discovery['resume_serial_batch'] ?? '' ) === ( $serial['id'] ?? '' );
		$pipeline = $catalog_discovery_on && ! $related_catalog ? array() : $this->pipeline_display51( $serial );
		if ( $pipeline ) {
			if ( 'validate' === $pipeline['phase'] && $current_chapter_total > 0 ) {
				$idx = (int) ( $serial['cursor'] ?? 0 );
				$pipeline['rows'][$idx]['detail'] = sprintf( '%d/%d capítulos conferidos', $current_chapter_done, $current_chapter_total );
				$pipeline['counts']['validate'] .= sprintf( ' obras; atual %d/%d capítulos', $current_chapter_done, $current_chapter_total );
				$pipeline['bars']['validate'] = min( 99, (int) floor( 100 * ( $pipeline['verified'] + min( 1, $current_chapter_done / $current_chapter_total ) ) / max( 1, $pipeline['total'] ) ) );
			}
			$collecting = 'collect' === $pipeline['phase'];
			$done = 'done' === $pipeline['phase'];
			$stopped = 'stopped' === $pipeline['phase'];
			$percent = $pipeline['bars']['publish'];
			$reached = $pipeline['published'] >= $pipeline['requested'];
			$title = $done ? ( $reached ? 'Quantidade solicitada publicada' : 'Pedido não atendido: faltam publicações' ) : ( $collecting ? 'Coletando dados da obra atual' : 'Validando a obra atual para publicação' );
			$status = $paused && ( $serial_on || $related_catalog ) ? 'PAUSADA' : ( $done ? ( $reached ? 'PEDIDO ATENDIDO' : 'PEDIDO NÃO ATENDIDO' ) : ( $collecting ? 'COLETANDO CANDIDATAS' : 'VALIDANDO PARA PUBLICAR' ) );
			if ( 'select' === $pipeline['phase'] ) { $title = 'Buscando substitutas para completar o pedido'; $status = $paused ? 'PAUSADA' : 'BUSCANDO SUBSTITUTAS'; }
			if ( $stopped ) { $title = 'Lote interrompido'; $status = 'INTERROMPIDO'; }
			$state = $paused || ( $done && ! $reached ) ? 'warning' : ( $done ? 'completed' : 'running' );
			if ( $stopped ) { $state = 'warning'; }
			$message = sprintf( '%d solicitadas; %d selecionadas; %d com dados coletados; %d publicadas; %d pendentes. ', $pipeline['requested'], $pipeline['total'], $pipeline['captured'], $pipeline['published'], $pipeline['pending'] );
			$message .= $done ? ( $reached ? 'A meta foi atingida com publicações. Recusas não consumiram vagas.' : ( 'source_unavailable' === ( $serial['finish_reason'] ?? '' ) ? 'A origem impediu continuar. A quantidade não foi atingida; use Completar pedido pendente após recuperar o acesso.' : 'Não restam candidatas nas páginas configuradas. A quantidade não foi atingida. Confira o limite de páginas e os motivos registrados.' ) ) : 'Só publicações contam para a meta. Candidatas recusadas são substituídas sem repetir as já analisadas. Capítulos e imagens continuam sendo conferidos.';
			if ( $done && ! $reached && 'manual_url' === ( $pipeline['mode'] ?? '' ) ) { $title = 'Obra informada aguardando correção'; $status = 'PUBLICAÇÃO BLOQUEADA'; $message = 'A URL informada não passou na validação. Consulte o motivo na tabela. Uma importação por URL não troca a obra escolhida por outra.'; }
			if ( $stopped ) { $message .= ' Este lote não está mais executando. Conteúdo publicado foi preservado.'; }
			if ( isset( $serial['first_publication_seconds'] ) ) { $message .= sprintf( ' Tempo até a primeira publicação: %d s.', (int) $serial['first_publication_seconds'] ); }
			if ( $worker_wait > 0 && ! $done ) { $message .= sprintf( ' Próxima rodada em %d s.', $worker_wait ); }
			if ( $retry && ! $done ) { $message .= ' Aguardando nova tentativa: ' . (string) $retry->last_error; }
			$order = array( 'label' => 'Publicadas no pedido', 'text' => $pipeline['published'] . '/' . $pipeline['requested'], 'skipped' => $pipeline['pending'] );
		}
		$data = array(
			'target' => $target, 'ready' => $ready, 'created' => $created, 'chapters' => $chapters, 'images' => $images,
			'percent' => $percent, 'state' => $state, 'status' => $status, 'title' => $title, 'message' => $message,
			'queue' => sprintf( 'Tarefas da fila: %d pendentes%s · %d processando · %d concluídos · %d falhas', $counts['pending'], $retry ? ' (há nova tentativa agendada)' : '', $counts['processing'], $counts['completed'], $counts['failed'] ),
			'counts' => array_map( 'intval', $counts ),
			'activity' => $activity ?: 'Aguardando próxima ação do processador.',
			'heartbeat' => $heartbeat, 'load' => $load, 'order' => $order['text'], 'order_label' => $order['label'], 'skipped' => $order['skipped'], 'catalog_total' => $created_total,
			'accelerate' => ! $paused && ( $serial_on || $catalog_discovery_on || $active > 0 ),
			'worker_wait' => $worker_wait,
			'pipeline' => $pipeline,
		);
		$data['manual'] = $this->manual_progress_data();
		$data['watch'] = ! $paused && ( $serial_on || $catalog_discovery_on || $active > 0 || ! empty( $data['manual']['active'] ) );
		return $data;
	}

	private function manual_progress_data(): array {
		$state     = $this->importer->manual_update_progress();
		$total     = max( 0, (int) $state['total'] );
		$completed = max( 0, (int) $state['completed'] );
		$failed    = max( 0, (int) $state['failed'] );
		$done      = min( $total, $completed + $failed );
		$pending   = max( 0, $total - $done );
		$percent   = $total > 0 ? min( 100, (int) floor( ( $done / $total ) * 100 ) ) : 0;
		$active    = ! empty( $state['active'] );
		$preparing = $active && ! empty( $state['preparing'] );
		if ( $preparing ) {
			$target = max( 1, (int) ( $state['target_total'] ?? $total ) );
			$queued = max( 0, (int) ( $state['queued'] ?? 0 ) );
			$percent = min( 20, (int) floor( ( min( $target, $queued ) / $target ) * 20 ) );
			$status = 'PREPARANDO';
			$title = 'Montando a fila sem bloquear o servidor';
			$message = sprintf( '%d de %d capítulos preparados em pequenos lotes. O clique inicial não executa mais milhares de consultas dentro do admin-post.php.', $queued, $target );
			$visual = 'running';
		} elseif ( $active ) {
			$status = 'EM ANDAMENTO';
			$title  = 'Publicando capítulos selecionados';
			$message = sprintf( '%d de %d processados. As páginas são vinculadas remotamente em uma única etapa.', $done, $total );
			$visual = 'running';
		} elseif ( $total > 0 && $failed > 0 ) {
			$status = 'CONCLUÍDO COM FALHAS';
			$title  = 'A atualização manual terminou';
			$message = sprintf( '%d concluídos e %d falhas. Consulte “Logs de erros”.', $completed, $failed );
			$visual = 'failed';
		} elseif ( $total > 0 ) {
			$status = 'CONCLUÍDO';
			$title  = 'Capítulos selecionados publicados';
			$message = sprintf( '%d capítulo(s) concluído(s).', $completed );
			$visual = 'completed';
		} else {
			$status = 'AGUARDANDO';
			$title  = 'Nenhuma atualização em execução';
			$message = ! empty( $state['error'] ) ? (string) $state['error'] : 'Detecte os capítulos e escolha exatamente o que deseja publicar.';
			$visual = 'idle';
		}
		$resources = MBI_Resource_Guard::status();
		$paused = $this->importer->is_paused();
		if ( $active && $paused ) {
			$status = 'PAUSADA'; $title = 'Publicação pausada; tarefas preservadas';
			$message = 'Clique em Retomar importação para continuar. Se houve erro fatal, consulte Logs de erros antes de retomar.'; $visual = 'paused';
		} elseif ( $active && $resources['wait'] > 0 ) {
			$status = 'AGUARDANDO REDE'; $title = 'Publicação aguardando a conexão recuperar';
			$message = sprintf( 'Nova tentativa em %d s. %s. Capítulos e tarefas preservados.', $resources['wait'], $resources['reason'] ); $visual = 'waiting';
		} elseif ( $active && $resources['lane'] && $resources['lane'] !== 'publicação' ) {
			$message .= ' Aguardando a rodada atual: ' . $resources['lane'] . '.';
		}
		return array(
			'active' => $active, 'preparing' => $preparing, 'paused' => $paused, 'worker_wait' => $resources['wait'], 'state' => $visual, 'status' => $status, 'title' => $title, 'message' => $message,
			'total' => $total, 'completed' => $completed, 'failed' => $failed, 'pending' => $pending, 'percent' => $percent,
			'manga' => (string) ( $state['current_manga'] ?: '—' ), 'chapter' => (string) ( $state['current_chapter'] ?: '—' ),
		);
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Acesso negado.' );
		}
		check_admin_referer( 'mbi_admin_action' );
	}

	private function redirect_notice( string $type, string $message, string $page = 'manga-bridge-importer' ): void {
		set_transient( 'mbi_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=mbi_manga&page=' . sanitize_key( $page ) ) );
		exit;
	}

	private function stat( string $label, int $value, string $class, string $key ): void {
		printf( '<div class="mbi-stat mbi-stat-%s"><span>%s</span><strong data-queue-%s>%s</strong></div>', esc_attr( $class ), esc_html( $label ), esc_attr( $key ), number_format_i18n( $value ) );
	}

	private function action_form( string $action, string $label, string $class, string $confirm = '' ): void {
		$confirm_script = $confirm ? 'return window.confirm(' . wp_json_encode( $confirm ) . ');' : '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php if ( $confirm_script ) : ?> onsubmit="<?php echo esc_attr( $confirm_script ); ?>"<?php endif; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( 'mbi_admin_action' ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function diagnostic_row( string $label, string $value, bool $ok ): void {
		printf( '<tr><th>%s</th><td><span class="mbi-check %s">%s</span> %s</td></tr>', esc_html( $label ), $ok ? 'ok' : 'bad', $ok ? '●' : '●', esc_html( $value ) );
	}
}
