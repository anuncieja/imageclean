<?php
declare(strict_types=1);
if (wp_get_environment_type() !== 'staging' || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Disposable staging only.');
}
global $wpdb;
$state = [];
foreach ([$wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships] as $table) {
    $rows = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
    usort($rows, fn(array $a, array $b): int => strcmp(wp_json_encode($a), wp_json_encode($b)));
    $state[$table] = $rows;
}
if (!hash_equals((string) get_option('mbi2_ci_catalogue_digest'), hash('sha256', wp_json_encode($state)))) {
    throw new RuntimeException('Catalogue changed during transport/install/rollback.');
}
echo "Catalogue preservation verified after transport or plugin lifecycle operation.\n";
