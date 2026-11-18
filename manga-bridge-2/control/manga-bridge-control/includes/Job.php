<?php
declare(strict_types=1);
namespace MangaBridge2;

/** Pure state machine. It cannot write to the WordPress catalogue. */
final class Job {
    public static function uuid(string $value): bool {
        return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value);
    }
    public static function source(string $url, array $hosts): bool {
        $p = parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' &&
            !isset($p['user'], $p['pass']) && !isset($p['user']) && !isset($p['pass']) &&
            !isset($p['fragment']) && !isset($p['query']) && (!isset($p['port']) || $p['port'] === 443) &&
            in_array(strtolower($p['host'] ?? ''), $hosts, true) &&
            (bool) preg_match('~^/manga/[^/]+/$~D', $p['path'] ?? '');
    }
    public static function create(string $id, string $generation, string $url, array $hosts, int $now): array {
        if (!self::uuid($id) || !self::uuid($generation) || !self::source($url, $hosts)) {
            throw new \InvalidArgumentException('invalid_job');
        }
        return ['schema' => 1, 'id' => $id, 'generation' => $generation, 'source_url' => $url,
            'state' => 'queued', 'revision' => 0, 'created_at' => $now, 'updated_at' => $now,
            'attempt' => '', 'lease_until' => 0, 'attempts' => 0, 'checkpoint' => [],
            'last_event_hash' => '', 'reason' => '', 'published' => 0];
    }
    public static function admin(array $job, string $action, string $generation, int $now): array {
        if (!self::uuid($generation) || !in_array($action, ['cancel', 'resume'], true)) {
            throw new \InvalidArgumentException('invalid_control');
        }
        if ($action === 'resume' && !in_array($job['state'], ['blocked', 'waiting_origin', 'cancelled'], true)) {
            throw new \DomainException('job_not_resumable');
        }
        $job['state'] = $action === 'cancel' ? 'cancelled' : 'queued';
        if ($action === 'resume') { $job['attempts'] = 0; $job['reason'] = ''; unset($job['github_run_id']); }
        $job['generation'] = $generation; $job['attempt'] = ''; $job['lease_until'] = 0;
        $job['revision']++; $job['updated_at'] = $now; $job['last_event_hash'] = '';
        return $job;
    }
    public static function event(array $job, array $event, string $rawBody, int $now): array {
        $fingerprint = hash('sha256', $rawBody);
        if (($event['generation'] ?? '') !== $job['generation'] || $job['state'] === 'cancelled') {
            throw new \DomainException('stale_generation');
        }
        $seq = $event['sequence'] ?? null;
        if (!is_int($seq)) { throw new \InvalidArgumentException('invalid_sequence'); }
        if ($seq === $job['revision'] && hash_equals($job['last_event_hash'], $fingerprint)) { return $job; }
        if ($seq !== $job['revision'] + 1) { throw new \DomainException('revision_conflict'); }
        $kind = $event['kind'] ?? '';
        $attempt = (string) ($event['attempt'] ?? '');
        if (!self::uuid($attempt)) { throw new \InvalidArgumentException('invalid_attempt'); }
        if ($kind === 'claim') {
            if (!in_array($job['state'], ['queued', 'dispatching', 'waiting_origin', 'running'], true) ||
                ($job['state'] === 'running' && $job['lease_until'] > $now)) {
                throw new \DomainException('job_already_claimed');
            }
            if ($job['attempts'] >= 4) { throw new \DomainException('attempt_budget_exhausted'); }
            $job['state'] = 'running'; $job['attempt'] = $attempt; $job['lease_until'] = $now + 90; $job['attempts']++;
        } else {
            if ($job['state'] !== 'running' || $job['attempt'] !== $attempt || $job['lease_until'] <= $now) {
                throw new \DomainException('lease_lost');
            }
            if (!in_array($kind, ['progress', 'ready', 'waiting_origin', 'blocked'], true)) {
                throw new \InvalidArgumentException('unsupported_event');
            }
            $checkpoint = $event['checkpoint'] ?? [];
            if (!is_array($checkpoint) || strlen(json_encode($checkpoint, JSON_THROW_ON_ERROR)) > 220000) {
                throw new \InvalidArgumentException('checkpoint_too_large');
            }
            $job['checkpoint'] = $checkpoint;
            $job['reason'] = substr(preg_replace('/[^a-z0-9_.-]/', '', (string) ($event['reason'] ?? '')), 0, 120);
            // READY is only a staging result; never a public WordPress publication.
            $job['state'] = $kind === 'progress' ? 'running' : $kind;
            $job['lease_until'] = $kind === 'progress' ? $now + 90 : 0;
        }
        $job['revision'] = $seq; $job['updated_at'] = $now; $job['last_event_hash'] = $fingerprint;
        return $job;
    }
}
