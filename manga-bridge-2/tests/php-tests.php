<?php
declare(strict_types=1);
require __DIR__ . '/../control/manga-bridge-control/includes/Protocol.php';
require __DIR__ . '/../control/manga-bridge-control/includes/Job.php';
use MangaBridge2\Protocol;
use MangaBridge2\Job;
$count = 0;
function check(bool $ok, string $name): void { global $count; if (!$ok) { throw new RuntimeException($name); } $count++; }
function rejects(callable $f, string $name): void { $bad = false; try { $f(); } catch (InvalidArgumentException | DomainException $e) { $bad = true; } check($bad, $name); }
$id = '11111111-1111-4111-8111-111111111111'; $generation = '22222222-2222-4222-8222-222222222222';
$attempt = '33333333-3333-4333-8333-333333333333';
$hosts = ['mangalivre.blog'];
$job = Job::create($id, $generation, 'https://mangalivre.blog/manga/test/', $hosts, 1800000000);
check($job['published'] === 0 && $job['state'] === 'queued', 'create does not publish');
foreach (['http://mangalivre.blog/manga/a/', 'https://mangalivre.blog@127.0.0.1/manga/a/', 'https://evil.test/manga/a/', 'https://mangalivre.blog/manga/a/?x=1', 'https://mangalivre.blog:444/manga/a/'] as $url) {
    rejects(fn() => Job::create($id, $generation, $url, $hosts, 1800000000), 'reject unsafe source');
}
$key = str_repeat('k', 32); // PUBLIC test vector, never a runtime credential.
$route = 'jobs/' . $id . '/events'; $body = '{"kind":"claim"}'; $stamp = '1800000000'; $nonce = str_repeat('a', 32);
$sig = Protocol::sign($key, 'POST', $route, $stamp, $nonce, $body);
$headers = ['timestamp' => $stamp, 'nonce' => $nonce, 'signature' => $sig];
check(Protocol::verify($key, 'POST', $route, $headers, $body, 1800000000), 'valid HMAC');
check(!Protocol::verify($key, 'POST', $route, $headers, $body . ' ', 1800000000), 'body tamper');
check(!Protocol::verify($key, 'GET', $route, $headers, $body, 1800000000), 'method tamper');
check(!Protocol::verify($key, 'POST', $route, $headers, $body, 1800000121), 'expired request');
$headers['signature'] = str_repeat('0', 64);
check(!Protocol::verify($key, 'POST', $route, $headers, $body, 1800000000), 'wrong signature');
rejects(fn() => Protocol::key(base64_encode('short')), 'short keys rejected');
$claim = ['kind' => 'claim', 'sequence' => 1, 'generation' => $generation, 'attempt' => $attempt];
$raw = json_encode($claim); $running = Job::event($job, $claim, $raw, 1800000001);
check($running['state'] === 'running' && $running['attempts'] === 1, 'claim acquired');
check(Job::event($running, $claim, $raw, 1800000002) === $running, 'same event retries idempotently');
$other = $claim; $other['sequence'] = 2; $other['attempt'] = '44444444-4444-4444-8444-444444444444';
rejects(fn() => Job::event($running, $other, json_encode($other), 1800000002), 'concurrent claim refused');
$progress = ['kind' => 'progress', 'sequence' => 2, 'generation' => $generation, 'attempt' => $attempt, 'checkpoint' => ['page' => 4]];
$current = Job::event($running, $progress, json_encode($progress), 1800000010);
check($current['checkpoint']['page'] === 4 && $current['revision'] === 2, 'checkpoint advances');
rejects(fn() => Job::event($current, $progress, json_encode($progress + ['different' => true]), 1800000011), 'same revision with changed body refused');
$old = $progress; $old['sequence'] = 1;
rejects(fn() => Job::event($current, $old, json_encode($old), 1800000011), 'old sequence refused');
$forged = $progress; $forged['sequence'] = 3; $forged['kind'] = 'published';
rejects(fn() => Job::event($current, $forged, json_encode($forged), 1800000011), 'executor cannot publish');
$ready = $progress; $ready['sequence'] = 3; $ready['kind'] = 'ready';
$done = Job::event($current, $ready, json_encode($ready), 1800000012);
check($done['state'] === 'ready' && $done['published'] === 0, 'ready is staging, never publication');
rejects(fn() => Job::event($running, $progress, json_encode($progress), 1800000200), 'expired lease refused');
$cancelled = Job::admin($current, 'cancel', '55555555-5555-4555-8555-555555555555', 1800000011);
check($cancelled['state'] === 'cancelled' && $cancelled['checkpoint']['page'] === 4, 'cancel retains checkpoint');
rejects(fn() => Job::event($cancelled, $ready, json_encode($ready), 1800000012), 'cancel blocks old executor');
$resumed = Job::admin($cancelled, 'resume', '66666666-6666-4666-8666-666666666666', 1800000013);
check($resumed['state'] === 'queued' && $resumed['generation'] !== $generation && $resumed['checkpoint']['page'] === 4, 'resume changes generation and retains checkpoint');
rejects(fn() => Job::event($resumed, $claim, $raw, 1800000014), 'old generation refused after resume');
$takeover = $claim; $takeover['sequence'] = 3; $takeover['attempt'] = '44444444-4444-4444-8444-444444444444';
$newOwner = Job::event($current, $takeover, json_encode($takeover), 1800000200);
check($newOwner['attempt'] !== $attempt, 'expired lease can be reclaimed');
$oldOwner = $ready; $oldOwner['sequence'] = 4;
rejects(fn() => Job::event($newOwner, $oldOwner, json_encode($oldOwner), 1800000201), 'previous owner cannot commit');
$limit = $job; $limit['attempts'] = 4;
rejects(fn() => Job::event($limit, $claim, $raw, 1800000001), 'bounded attempts');
echo "PHP state/protocol checks: $count\n";
if (getenv('MBI2_VECTOR_OUTPUT')) {
    file_put_contents(getenv('MBI2_VECTOR_OUTPUT'), json_encode(['signature' => $sig, 'body' => $body]));
}
