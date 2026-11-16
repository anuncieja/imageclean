<?php
/** Executed only against the disposable CI WordPress database. */
declare(strict_types=1);
use MangaBridge2\Job;
use MangaBridge2\Store;
use MangaBridge2\Plugin;
use MangaBridge2\Protocol;

if (wp_get_environment_type() !== 'staging' || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Disposable CLI staging only.');
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$checks = 0;
function ensure(bool $condition, string $name): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($name); }
    $checks++;
}
function catalogue_digest(): string {
    global $wpdb;
    $snapshot = [];
    foreach ([$wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships] as $table) {
        $rows = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
        usort($rows, fn(array $a, array $b): int => strcmp(wp_json_encode($a), wp_json_encode($b)));
        $snapshot[$table] = $rows;
    }
    return hash('sha256', wp_json_encode($snapshot));
}
register_post_type('mbi_manga', ['public' => true]);
register_post_type('mbi_chapter', ['public' => true]);
$manga = wp_insert_post(['post_type' => 'mbi_manga', 'post_status' => 'publish',
    'post_title' => 'CI preserved work', 'post_name' => 'ci-preserved-work'], true);
$chapter = wp_insert_post(['post_type' => 'mbi_chapter', 'post_status' => 'publish',
    'post_title' => 'CI preserved chapter', 'post_parent' => $manga], true);
ensure(is_int($manga) && is_int($chapter), 'seed fixture created');
update_post_meta($manga, '_mbi_cover_url', 'https://example.org/retained-cover.jpg');
update_post_meta($chapter, '_mbi_manga_id', $manga);
$before = catalogue_digest();
$activation = activate_plugin('manga-bridge-control/manga-bridge-control.php');
ensure(!is_wp_error($activation), 'activation succeeds');
ensure(catalogue_digest() === $before, 'activation preserves catalogue byte values');
update_option('mbi2_ci_catalogue_digest', $before, false);
ensure(Plugin::enabled(), 'staging explicitly enabled');
$id = wp_generate_uuid4(); $generation = wp_generate_uuid4(); $attempt = wp_generate_uuid4();
$job = Job::create($id, $generation, 'https://mangalivre.blog/manga/ci-demo/', Plugin::hosts(), time());
ensure(Store::create($job), 'job inserted');
ensure(!Store::create($job), 'duplicate identifier cannot insert twice');
ensure(Store::read($id) === $job, 'database read contract');
ensure(get_option('mbi2_job_' . hash('sha256', $id)) === wp_json_encode($job), 'option JSON remains stable');
$stale = $job; $next = $job; $next['reason'] = 'cas_test'; $next['revision']++;
ensure(Store::swap($job, $next), 'CAS writes once');
ensure(!Store::swap($stale, $next), 'CAS refuses stale revision');
$job = $next;
function signed_request(string $method, string $id, ?array $event = null, bool $valid = true): WP_REST_Response {
    $suffix = $method === 'POST' ? '/events' : '';
    $route = 'jobs/' . $id . $suffix;
    $request = new WP_REST_Request($method, '/mbi2/v1/' . $route);
    $body = $event === null ? '' : wp_json_encode($event, JSON_UNESCAPED_SLASHES);
    $stamp = (string) time(); $nonce = bin2hex(random_bytes(16));
    $request->set_body($body); $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-MBI2-Timestamp', $stamp);
    $request->set_header('X-MBI2-Nonce', $nonce);
    $request->set_header('X-MBI2-Signature', $valid ?
        Protocol::sign(Protocol::key(MBI2_HMAC_KEY_B64), $method, $route, $stamp, $nonce, $body) : str_repeat('0', 64));
    return rest_do_request($request);
}
ensure(signed_request('GET', $id, null, false)->get_status() === 401, 'REST rejects unauthenticated caller');
ensure(signed_request('GET', $id)->get_status() === 200, 'REST accepts signed GET');
$claim = ['kind' => 'claim', 'generation' => $generation, 'sequence' => $job['revision'] + 1, 'attempt' => $attempt];
ensure(signed_request('POST', $id, $claim)->get_status() === 200, 'REST claim succeeds');
$claimed = Store::read($id);
ensure(signed_request('POST', $id, $claim)->get_status() === 200 && Store::read($id)['attempts'] === 1, 'REST retry idempotent');
ensure(wp_next_scheduled('mbi2_watchdog', [$id, $generation, $attempt]) !== false, 'claim installs lease watchdog');
$future = $claimed; $future['lease_until'] = time() + 200; $future['revision']++;
ensure(Store::swap($claimed, $future), 'lease fixture extended');
Plugin::watchdog($id, $generation, $attempt);
ensure(Store::read($id)['state'] === 'running', 'watchdog does not interrupt valid owner');
$expired = $future; $expired['lease_until'] = time() - 1; $expired['revision']++;
ensure(Store::swap($future, $expired), 'lease fixture expired');
Plugin::watchdog($id, $generation, $attempt);
$waiting = Store::read($id);
ensure($waiting['state'] === 'waiting_origin', 'expired executor becomes retryable');
ensure(wp_next_scheduled('mbi2_dispatch', [$id]) !== false, 'recovery is scheduled without panel');
$cancelled = Job::admin($waiting, 'cancel', wp_generate_uuid4(), time());
ensure(Store::swap($waiting, $cancelled), 'cancellation persisted');
ensure(signed_request('POST', $id, $claim)->get_status() === 409, 'old callback rejected after cancel');
$resumed = Job::admin($cancelled, 'resume', wp_generate_uuid4(), time());
ensure(Store::swap($cancelled, $resumed), 'resume persisted');
Plugin::dispatch($id);
ensure(Store::read($id)['reason'] === 'github_configuration_required', 'missing secrets blocks safely without network');
ensure(catalogue_digest() === $before, 'jobs REST recovery cancel resume preserve catalogue');
$httpId = wp_generate_uuid4();
ensure(Store::create(Job::create($httpId, wp_generate_uuid4(), 'https://mangalivre.blog/manga/http-fixture/', Plugin::hosts(), time())), 'loopback communication fixture created');
file_put_contents('/tmp/mbi2-http-job-id', $httpId);
echo "WordPress/MariaDB integration checks: $checks\n";
echo 'Environment: WordPress ' . get_bloginfo('version') . '; PHP ' . PHP_VERSION . '; MariaDB ' . $GLOBALS['wpdb']->db_version() . "\n";
