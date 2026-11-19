<?php
/**
 * Plugin Name: Mangá Bridge Importer
 * Description: Importa um catálogo autorizado de mangás e permite detectar e publicar novos capítulos somente por ação manual.
 * Version: 1.7.54
 * Author: BOOOM Criative
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: manga-bridge-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MBI_VERSION', '1.7.54' );
define( 'MBI_FILE', __FILE__ );
define( 'MBI_DIR', plugin_dir_path( __FILE__ ) );
define( 'MBI_URL', plugin_dir_url( __FILE__ ) );

require_once MBI_DIR . 'includes/class-mbi-db.php';
require_once MBI_DIR . 'includes/class-mbi-parser.php';
require_once MBI_DIR . 'includes/class-mbi-resource-guard.php';
require_once MBI_DIR . 'includes/class-mbi-image-proxy.php';
require_once MBI_DIR . 'includes/class-mbi-chapter-integrity.php';
require_once MBI_DIR . 'includes/class-mbi-browser-delivery.php';
require_once MBI_DIR . 'includes/class-mbi-importer.php';
require_once MBI_DIR . 'includes/class-mbi-catalog-recovery.php';
require_once MBI_DIR . 'includes/class-mbi-admin.php';
require_once MBI_DIR . 'includes/class-mbi-media-repair.php';
require_once MBI_DIR . 'includes/class-mbi-global-diagnostic.php';
require_once MBI_DIR . 'includes/class-mbi-cover-refresh.php';
require_once MBI_DIR . 'includes/class-mbi-source-audit.php';
require_once MBI_DIR . 'includes/class-mbi-root-reconcile.php';
require_once MBI_DIR . 'includes/class-mbi-media-monitor.php';

final class MBI_Plugin {
	private static ?MBI_Plugin $instance = null;

	public MBI_DB $db;
	public MBI_Parser $parser;
	public MBI_Importer $importer;
	public MBI_Admin $admin;

	public static function instance(): MBI_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->db       = new MBI_DB();
		MBI_Resource_Guard::boot( $this->db );
		$this->parser   = new MBI_Parser();
		add_filter( 'wp_insert_post_data', array( 'MBI_Chapter_Integrity', 'gate' ), 20, 2 );
		$this->importer = new MBI_Importer( $this->db, $this->parser );
		$this->admin    = new MBI_Admin( $this->db, $this->importer );
		$media = new MBI_Media_Repair( $this->importer, $this->db );
		new MBI_Media_Monitor( $media, $this->db );
		// v1.7.36: preserve the legacy AJAX endpoint for compatibility, but do not
		// enqueue its browser-only validator. New work is checked by the cron worker.
		new MBI_Browser_Delivery( $this->importer );
		new MBI_Global_Diagnostic( $this->importer, $this->db );
		new MBI_Cover_Refresh( $this->importer, $this->db );
		new MBI_Source_Audit( $this->importer, $this->db );
		new MBI_Root_Reconcile( $this->importer, $this->db );

		add_action( 'init', array( $this, 'register_content_types' ) );
		add_action( 'init', array( $this, 'ensure_schedules' ), 20 );
		// Legacy catalogue mutations require explicit administrator action.

		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		// v1.7.40: bounded recovery runs before the existing minute cron worker.
		add_action( 'mbi_process_queue', array( $this->importer, 'auto_recover_manual_network_failures' ), 5 );
		add_action( 'mbi_process_queue', array( $this->importer, 'process_queue' ) );
		add_action( 'mbi_queue_wakeup', array( $this->importer, 'process_queue' ) );
		// Atualizações e novas obras nunca são iniciadas automaticamente.
		// O único cron permanente é o processador de tarefas solicitadas manualmente.
		add_action( 'wp_ajax_mbi_worker_tick', array( $this->importer, 'worker_tick' ) );
		add_action( 'wp_ajax_nopriv_mbi_worker_tick', array( $this->importer, 'worker_tick' ) );
		add_action( 'mbi_rankmath_backfill', array( $this->importer, 'backfill_rankmath_metadata' ) );
		add_action( 'mbi_cleanup_legacy_media', array( $this->importer, 'cleanup_legacy_media_cron' ) );
		add_action( 'mbi_proxy_migration', array( $this->importer, 'proxy_migration_cron' ) );
		add_action( 'mbi_prepare_manual_updates', array( $this->importer, 'prepare_manual_update_cron' ) );
		// v1.7.40: continue an already user-started detection without a browser.
		add_action( 'mbi_update_scan_continue', array( $this->importer, 'update_scan_cron_continue' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
	}

	public function register_content_types(): void {
		register_post_type(
			'mbi_manga',
			array(
				'labels'       => array(
					'name'          => 'Mangás',
					'singular_name' => 'Mangá',
					'add_new_item'  => 'Adicionar mangá',
					'edit_item'     => 'Editar mangá',
				),
				'public'       => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-book-alt',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'comments', 'custom-fields' ),
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'manga', 'with_front' => false ),
			)
		);

		register_post_type(
			'mbi_chapter',
			array(
				'labels'       => array(
					'name'          => 'Capítulos',
					'singular_name' => 'Capítulo',
					'add_new_item'  => 'Adicionar capítulo',
					'edit_item'     => 'Editar capítulo',
				),
				'public'       => true,
				'show_in_rest' => true,
				'show_in_menu' => 'edit.php?post_type=mbi_manga',
				'supports'     => array( 'title', 'editor', 'comments', 'custom-fields' ),
				'has_archive'  => false,
				// A relação com o mangá continua em post_parent, mas o CPT não pode ser hierárquico:
				// o pai é mbi_manga (outro post type) e isso gera permalinks que o WP não resolve.
				'hierarchical' => false,
				'rewrite'      => array( 'slug' => 'capitulo', 'with_front' => false ),
			)
		);

		$this->register_taxonomy( 'mbi_genre', 'Gêneros', 'genero' );
		$this->register_taxonomy( 'mbi_type', 'Tipos', 'tipo' );
		$this->register_taxonomy( 'mbi_status', 'Status', 'status-manga' );
		// Categoria padrão do WordPress: permite classificação e também é compreendida pelo Rank Math.
		register_taxonomy_for_object_type( 'category', 'mbi_manga' );
		register_taxonomy_for_object_type( 'post_tag', 'mbi_manga' );
		$this->importer->ensure_base_categories();

		$meta = array(
			'_mbi_source_url'        => array( 'type' => 'string' ),
			'_mbi_source_key'        => array( 'type' => 'string' ),
			'_mbi_source_hash'       => array( 'type' => 'string' ),
			'_mbi_original_title'    => array( 'type' => 'string' ),
			'_mbi_last_sync'         => array( 'type' => 'string' ),
			'_mbi_year'              => array( 'type' => 'string' ),
			'_mbi_author'            => array( 'type' => 'string' ),
			'_mbi_artist'            => array( 'type' => 'string' ),
			'_mbi_rating'            => array( 'type' => 'number' ),
			'_mbi_views'             => array( 'type' => 'integer' ),
			'_mbi_readers'           => array( 'type' => 'integer' ),
			'_mbi_chapter_number'    => array( 'type' => 'string' ),
			'_mbi_expected_pages'    => array( 'type' => 'integer' ),
			'_mbi_chapter_complete'  => array( 'type' => 'integer' ),
			'_mbi_target_status'     => array( 'type' => 'string' ),
			'_mbi_chapter_page_ids'  => array( 'type' => 'array', 'item_type' => 'integer' ),
			'_mbi_chapter_page_urls' => array( 'type' => 'array', 'item_type' => 'string' ),
			'_mbi_source_image_urls' => array( 'type' => 'array', 'item_type' => 'string', 'private' => true ),
			'_mbi_ready'             => array( 'type' => 'integer' ),
			'_mbi_expected_chapters' => array( 'type' => 'integer' ),
			'_mbi_source_chapter_urls'=> array( 'type' => 'array', 'item_type' => 'string' ),
			'_mbi_serial_batch_id'   => array( 'type' => 'string' ),
			'_mbi_serial_completed_batch' => array( 'type' => 'string' ),
			'_mbi_update_in_progress' => array( 'type' => 'integer' ),
			'_mbi_cover_source_url' => array( 'type' => 'string' ),
			'_mbi_cover_original_url' => array( 'type' => 'string', 'private' => true ),
			'_mbi_chapter_page_urls_original' => array( 'type' => 'array', 'item_type' => 'string', 'private' => true ),
			'_mbi_validation_status' => array( 'type' => 'string' ),
			'_mbi_validation_errors' => array( 'type' => 'array', 'item_type' => 'string' ),
			'_mbi_validation_checked_at' => array( 'type' => 'string' ),
			'_mbi_validation_blocked_batch' => array( 'type' => 'string' ),
			'_mbi_featured'          => array( 'type' => 'integer' ),
			'_mbi_featured_at'       => array( 'type' => 'integer' ),
		);

		foreach ( $meta as $key => $definition ) {
			$type = $definition['type'];
			$is_private = ! empty( $definition['private'] );
			$args = array(
				'type'              => $type,
				'single'            => true,
				'show_in_rest'      => ! $is_private,
				'sanitize_callback' => 'array' === $type ? null : ( in_array( $key, array( '_mbi_source_url', '_mbi_cover_source_url', '_mbi_cover_original_url' ), true ) ? 'esc_url_raw' : 'sanitize_text_field' ),
				'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
			);

			if ( 'array' === $type && ! $is_private ) {
				$args['show_in_rest'] = array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => $definition['item_type'] ),
					),
				);
			}

			foreach ( array( 'mbi_manga', 'mbi_chapter' ) as $post_type ) {
				register_post_meta( $post_type, $key, $args );
			}
		}
	}


	private function register_taxonomy( string $taxonomy, string $label, string $slug ): void {
		register_taxonomy(
			$taxonomy,
			array( 'mbi_manga' ),
			array(
				'label'        => $label,
				'public'       => true,
				'hierarchical' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => $slug, 'with_front' => false ),
			)
		);
	}

	public function cron_schedules( array $schedules ): array {
		$schedules['mbi_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => 'A cada minuto (Mangá Bridge)',
		);
		$schedules['mbi_every_15_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'A cada 15 minutos (Mangá Bridge)',
		);

		return $schedules;
	}

	public function ensure_schedules(): void {
		if ( ! wp_next_scheduled( 'mbi_process_queue' ) ) {
			wp_schedule_event( time() + 10, 'mbi_every_minute', 'mbi_process_queue' );
		}
		wp_clear_scheduled_hook( 'mbi_daily_sync' );
		wp_clear_scheduled_hook( 'mbi_updates_sync' );
		wp_clear_scheduled_hook( 'mbi_auto_catalog_fill' );
		$cleanup = get_option( 'mbi_legacy_media_cleanup', array() );
		if ( is_array( $cleanup ) && ! empty( $cleanup['active'] ) && ! wp_next_scheduled( 'mbi_cleanup_legacy_media' ) ) {
			wp_schedule_single_event( time() + 10, 'mbi_cleanup_legacy_media' );
		}
		$manual_scan = get_option( 'mbi_update_scan', array() );
		if ( is_array( $manual_scan ) && ! empty( $manual_scan['active'] ) && ! wp_next_scheduled( 'mbi_update_scan_continue' ) ) {
			wp_schedule_single_event( time() + 60, 'mbi_update_scan_continue' );
		}
		$proxy_migration = get_option( 'mbi_proxy_migration', array() );
		if ( is_array( $proxy_migration ) && ! empty( $proxy_migration['active'] ) && ! wp_next_scheduled( 'mbi_proxy_migration' ) ) {
			wp_schedule_single_event( time() + 10, 'mbi_proxy_migration' );
		}
	}


	public function maybe_run_dedupe_upgrade(): void {
		if ( ! get_option( 'mbi_run_dedupe_upgrade' ) ) {
			return;
		}
		delete_option( 'mbi_run_dedupe_upgrade' );
		$result = $this->importer->repair_duplicate_state();
		$this->db->log( 'warning', 'v1.5.3: trava atômica por obra ativada e duplicatas não publicadas com a mesma URL reconciliadas.', $result );
	}


	public function maybe_run_validation_upgrade(): void {
		if ( ! get_option( 'mbi_run_validation_upgrade' ) ) {
			return;
		}
		delete_option( 'mbi_run_validation_upgrade' );
		$result = $this->importer->revalidate_suspicious_mangas( 1000 );
		$this->db->log( 'warning', 'v1.5.4: validação anti-corrupção ativada; obras suspeitas foram bloqueadas e agendadas para nova extração.', $result );
	}

	/** Recria regras de URL uma única vez, depois que os CPTs já foram registrados no init. */
	public function maybe_flush_rewrite_rules(): void {
		if ( ! get_option( 'mbi_flush_rewrite_rules' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		delete_option( 'mbi_flush_rewrite_rules' );
	}

	/** v1.7.53: upgrades never replay historical catalogue migrations. */
	public function maybe_upgrade(): void {
		$previous = (string) get_option( 'mbi_version', '0.0.0' );
		if ( $previous === MBI_VERSION ) { return; }
		if ( '0.0.0' === $previous ) { $this->db->install(); }
		$deferred = array( 'dedupe' => get_option( 'mbi_run_dedupe_upgrade', false ), 'validation' => get_option( 'mbi_run_validation_upgrade', false ) );
		update_option( 'mbi_upgrade53', array( 'from' => $previous, 'to' => MBI_VERSION, 'at' => time(), 'catalogue_changed' => false, 'deferred_legacy_flags' => $deferred ), false );
		delete_option( 'mbi_run_dedupe_upgrade' );
		delete_option( 'mbi_run_validation_upgrade' );
		update_option( 'mbi_version', MBI_VERSION, false );
	}


	public static function activate(): void {
		$plugin = self::instance();
		if ( ! get_option( 'mbi_version', false ) ) { $plugin->db->install(); }
		$plugin->register_content_types();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'mbi_process_queue' ) ) {
			wp_schedule_event( time() + 60, 'mbi_every_minute', 'mbi_process_queue' );
		}

		wp_clear_scheduled_hook( 'mbi_daily_sync' );
		wp_clear_scheduled_hook( 'mbi_updates_sync' );
		wp_clear_scheduled_hook( 'mbi_auto_catalog_fill' );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'mbi_media_daily_scan' );
		wp_clear_scheduled_hook( 'mbi_media_daily_continue' );
		$daily = (array) get_option( 'mbi_media_daily', array() );
		$daily['active'] = false; $daily['id'] = wp_generate_uuid4();
		update_option( 'mbi_media_daily', $daily, false );
		delete_option( 'mbi_media_daily_deferred' );
		wp_clear_scheduled_hook( 'mbi_process_queue' );
		wp_clear_scheduled_hook( 'mbi_update_scan_continue' );
		wp_clear_scheduled_hook( 'mbi_queue_wakeup' );
		wp_clear_scheduled_hook( 'mbi_daily_sync' );
		wp_clear_scheduled_hook( 'mbi_updates_sync' );
		wp_clear_scheduled_hook( 'mbi_auto_catalog_fill' );
		wp_clear_scheduled_hook( 'mbi_cleanup_legacy_media' );
		wp_clear_scheduled_hook( 'mbi_proxy_migration' );
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, array( 'MBI_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MBI_Plugin', 'deactivate' ) );

function mbi(): MBI_Plugin {
	return MBI_Plugin::instance();
}

/**
 * Retorna os anexos de um capítulo na ordem correta de leitura.
 * O tema pode usar esta função sem conhecer a estrutura interna do importador.
 */
function mbi_get_chapter_pages( int $chapter_id ): array {
	$remote = get_post_meta( $chapter_id, '_mbi_chapter_page_urls', true );
	if ( is_array( $remote ) && $remote ) {
		return array_values( array_filter( array_map( 'esc_url_raw', $remote ) ) );
	}
	$pages = get_post_meta( $chapter_id, '_mbi_chapter_page_ids', true );
	return is_array( $pages ) ? array_values( array_filter( array_map( 'absint', $pages ) ) ) : array();
}

/**
 * Retorna capítulos publicados de uma obra, do mais novo para o mais antigo.
 */
function mbi_get_manga_chapters( int $manga_id, int $limit = -1 ): array {
	return get_posts(
		array(
			'post_type'      => 'mbi_chapter',
			'post_status'    => 'publish',
			'post_parent'    => $manga_id,
			'posts_per_page' => $limit,
			'orderby'        => 'menu_order',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);
}

mbi();


// Load after plugins so an active legacy staging add-on cannot redeclare these classes.
add_action( 'plugins_loaded', static function (): void {
	if ( class_exists( '\MangaBridge2\Plugin', false ) ) {
		add_action( 'admin_notices', static function (): void {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<div class="notice notice-warning"><p>Mangá Bridge: desative o complemento Manga Bridge Control — Staging para usar a integração GitHub desta versão.</p></div>';
			}
		} );
		return;
	}
	foreach ( array( 'Protocol', 'Job', 'Store', 'Publisher', 'Plugin' ) as $component ) {
		require_once MBI_DIR . 'includes/github/' . $component . '.php';
	}
	\MangaBridge2\Plugin::boot();
}, 20 );
