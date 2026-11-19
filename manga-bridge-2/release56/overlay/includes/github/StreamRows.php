<?php
declare(strict_types=1);
namespace MangaBridge2;

/** Non-autoloaded, indexed intermediate data. Only a job's own scope is ever cleaned. */
final class StreamRows {
    private static function table(): string { return $GLOBALS['wpdb']->prefix.'mbi_stream_v1'; }
    public static function install(): void {
        if (get_option('mbi_stream_schema')==='1') { return; }
        global $wpdb;
        $table=self::table();
        $collate=$wpdb->get_charset_collate();
        if ($wpdb->query("CREATE TABLE IF NOT EXISTS $table (
            scope CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            slot BIGINT UNSIGNED NOT NULL,
            digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            body MEDIUMTEXT NOT NULL,
            PRIMARY KEY (scope,kind,slot),
            UNIQUE KEY source (scope,kind,digest)
        ) ENGINE=InnoDB $collate")===false) { throw new \RuntimeException('stream_storage_unavailable'); }
        update_option('mbi_stream_schema','1',false);
    }
    private static function insert(string $scope,string $kind,int $offset,array $entries): void {
        if (!$entries) { return; }
        global $wpdb;
        $table=self::table(); $args=[]; $values=[];
        foreach ($entries as $i=>[$key,$body]) {
            $values[]='(%s,%s,%d,%s,%s)';
            array_push($args,$scope,$kind,$offset+$i,hash('sha256',(string)$key),wp_json_encode($body,JSON_THROW_ON_ERROR));
        }
        if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (scope,kind,slot,digest,body) VALUES ".implode(',',$values),$args))===false) {
            throw new \RuntimeException('stream_write_failed');
        }
    }
    /** Replaying an acknowledged segment never writes new values. Conflicts are not silently accepted. */
    public static function append(string $scope,string $kind,int $offset,array $entries): void {
        self::insert($scope,$kind,$offset,$entries);
        $rows=self::range($scope,$kind,$offset,count($entries));
        foreach ($entries as $i=>[$key,$body]) {
            if (!isset($rows[$offset+$i]) || $rows[$offset+$i]!==$body) {
                throw new \InvalidArgumentException('duplicate_or_changed_'.$kind);
            }
        }
    }
    /** Previously seen identity from another position is skipped, including catalogue repeats. */
    public static function first(string $scope,string $kind,int $offset,array $items): array {
        $entries=array_map(static fn($x)=>[$x,$x],$items);
        self::insert($scope,$kind,$offset,$entries);
        $rows=self::range($scope,$kind,$offset,count($items)); $fresh=[];
        foreach ($items as $i=>$item) {
            if (isset($rows[$offset+$i]) && $rows[$offset+$i]===$item) { $fresh[]=$item; }
        }
        return $fresh;
    }
    public static function range(string $scope,string $kind,int $start,int $amount): array {
        if ($amount<1) { return []; }
        global $wpdb;
        $table=self::table();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT slot,body FROM $table WHERE scope=%s AND kind=%s
            AND slot >= %d AND slot < %d ORDER BY slot",$scope,$kind,$start,$start+$amount),ARRAY_A);
        if ($wpdb->last_error!=='') { throw new \RuntimeException('stream_read_failed'); }
        $out=[];
        foreach ($rows as $row) { $out[(int)$row['slot']]=json_decode($row['body'],true,16,JSON_THROW_ON_ERROR); }
        return $out;
    }
    public static function retire(string $scope): void {
        if (!wp_next_scheduled('mbi2_stream_cleanup',[$scope])) { wp_schedule_single_event(time()+60,'mbi2_stream_cleanup',[$scope]); }
    }
    public static function clean(string $scope): void {
        if (!Job::uuid($scope) || get_option('mbi_stream_schema')!=='1') { return; }
        global $wpdb;
        $table=self::table();
        $n=$wpdb->query($wpdb->prepare("DELETE FROM $table WHERE scope=%s LIMIT 2000",$scope));
        if ($n===2000) { self::retire($scope); }
    }
}
