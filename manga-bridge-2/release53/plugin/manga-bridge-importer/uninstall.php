<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'mbi_process_queue' );
wp_clear_scheduled_hook( 'mbi_daily_sync' );
wp_clear_scheduled_hook( 'mbi_updates_sync' );
wp_clear_scheduled_hook( 'mbi_auto_catalog_fill' );
wp_clear_scheduled_hook( 'mbi_rankmath_backfill' );

delete_option( 'mbi_worker_lock' );
delete_option( 'mbi_worker_token' );
delete_option( 'mbi_updates_due' );
delete_option( 'mbi_suspended_serial_batch' );

// Obras, capítulos, imagens, filas e configurações são preservados por segurança.
// A remoção definitiva deve ser feita conscientemente pelo administrador do banco.

delete_option( 'mbi_process_generation' );
delete_option( 'mbi_generation_enforced' );
delete_option( 'mbi_runtime_status' );
delete_option( 'mbi_worker_lock' );
delete_option( 'mbi_worker_token' );
delete_option( 'mbi_serial_batch' );
delete_option( 'mbi_suspended_serial_batch' );
delete_option( 'mbi_updates_due' );
delete_option( 'mbi_queue_paused' );
