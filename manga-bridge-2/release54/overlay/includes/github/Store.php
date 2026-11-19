<?php
declare(strict_types=1);
namespace MangaBridge2;

/** Options are indexed by name and never autoloaded. Neither jobs nor the slot touch catalogue rows. */
final class Store {
    private const SLOT = 'mbi2_active_job_v1';
    private static function name(string $id): string {
        if (!Job::uuid($id)) { throw new \InvalidArgumentException('invalid_id'); }
        return 'mbi2_job_' . hash('sha256', $id);
    }
    private static function insert(string $name, string $value): bool {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",
            $name,$value
        )) === 1;
    }
    private static function raw(string $name): ?string {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name));
        return is_string($raw) ? $raw : null;
    }
    private static function compare(string $name, string $old, string $new): bool {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s",
            $new,$name,$old
        )) === 1;
    }
    public static function create(array $job): bool {
        return self::insert(self::name($job['id']), wp_json_encode($job, JSON_THROW_ON_ERROR));
    }
    public static function read(string $id): ?array {
        $raw = self::raw(self::name($id));
        if ($raw === null) { return null; }
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['id'] ?? '') !== $id) { throw new \RuntimeException('corrupt_record'); }
        return $data;
    }
    public static function swap(array $before, array $after): bool {
        return self::compare(self::name($before['id']), wp_json_encode($before, JSON_THROW_ON_ERROR), wp_json_encode($after, JSON_THROW_ON_ERROR));
    }

    /** Keep the latest job visible. A new request replaces only a terminal slot. */
    public static function latest(): ?string {
        $slot = self::raw(self::SLOT);
        if ($slot === null) { return null; }
        $id = explode(':',$slot,2)[0];
        return Job::uuid($id) ? $id : null;
    }
    public static function reserve(string $id): ?string {
        if (!Job::uuid($id)) { return null; }
        $slot = self::raw(self::SLOT);
        $value = $id . ':' . time() . ':' . wp_generate_uuid4();
        if ($slot === null) { return self::insert(self::SLOT,$value) ? $value : null; }
        [$oldId,$when,$pending] = array_pad(explode(':',$slot,3),3,'');
        $job = Job::uuid($oldId) ? self::read($oldId) : null;
        // If an interrupted request never created its record, unlock after two minutes.
        if (($job && !in_array($job['state'],['blocked','cancelled','published'],true))
            || ($pending !== '' && (int)$when > time()-120)) { return null; }
        return self::compare(self::SLOT,$slot,$value) ? $value : null;
    }
    public static function finish(string $ticket): void {
        self::compare(self::SLOT,$ticket,explode(':',$ticket,2)[0]);
    }
}
