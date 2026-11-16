<?php
declare(strict_types=1);
namespace MangaBridge2;

/** Non-autoloaded, namespaced records. CAS never touches posts, postmeta or terms. */
final class Store {
    private static function name(string $id): string {
        if (!Job::uuid($id)) { throw new \InvalidArgumentException('invalid_id'); }
        return 'mbi2_job_' . hash('sha256', $id);
    }
    public static function create(array $job): bool {
        return add_option(self::name($job['id']), wp_json_encode($job), '', false);
    }
    public static function read(string $id): ?array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::name($id)));
        if (!is_string($raw)) { return null; }
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['id'] ?? '') !== $id) { throw new \RuntimeException('corrupt_record'); }
        return $data;
    }
    public static function swap(array $before, array $after): bool {
        global $wpdb;
        $name = self::name($before['id']);
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",
            wp_json_encode($after), $name, wp_json_encode($before)
        ));
        wp_cache_delete($name, 'options');
        return $changed === 1;
    }
}
