<?php
declare(strict_types=1);
namespace MangaBridge2;

final class Protocol {
    public const MAX_BODY = 262144;
    public const MAX_BATCH_BODY = 1572864;
    public static function key(string $base64): string {
        $key = base64_decode($base64, true);
        if (!is_string($key) || strlen($key) !== 32) { throw new \InvalidArgumentException('invalid_key'); }
        return $key;
    }
    public static function canonical(string $method, string $route, string $timestamp, string $nonce, string $body): string {
        if (!in_array($method, ['GET', 'POST'], true) ||
            !preg_match('~^(?:jobs|batches)/[a-f0-9-]{36}(?:/events)?$~D', $route) ||
            !preg_match('/^[0-9]{10}$/D', $timestamp) ||
            !preg_match('/^[a-f0-9]{32}$/D', $nonce) || strlen($body) > (str_starts_with($route, 'batches/') ? self::MAX_BATCH_BODY : self::MAX_BODY)) {
            throw new \InvalidArgumentException('invalid_envelope');
        }
        return implode("\n", ['mbi2.v1', $method, $route, $timestamp, $nonce, hash('sha256', $body)]);
    }
    public static function sign(string $key, string $method, string $route, string $timestamp, string $nonce, string $body): string {
        if (strlen($key) !== 32) { throw new \InvalidArgumentException('invalid_key'); }
        return hash_hmac('sha256', self::canonical($method, $route, $timestamp, $nonce, $body), $key);
    }
    public static function verify(string $key, string $method, string $route, array $headers, string $body, int $now): bool {
        try {
            $stamp = (string) ($headers['timestamp'] ?? '');
            $nonce = (string) ($headers['nonce'] ?? '');
            $signature = (string) ($headers['signature'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/D', $signature) || abs($now - (int) $stamp) > 120) { return false; }
            return hash_equals(self::sign($key, $method, $route, $stamp, $nonce, $body), $signature);
        } catch (\InvalidArgumentException $e) { return false; }
    }
}
