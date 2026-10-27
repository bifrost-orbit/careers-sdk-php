<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers;

/**
 * Checks the website webhook Careers Orbit sends when jobs change, so you can refresh cached pages.
 */
final class Webhook
{
    public const MAX_AGE_MS = 300000;

    /**
     * Whether a message is signed with your webhook secret and at most five minutes old.
     *
     * @param string $timestamp The X-Orbit-Timestamp header, in milliseconds
     * @param string $signature The X-Orbit-Signature header
     * @param string $body The raw request body
     */
    public static function verify(string $secret, string $timestamp, string $body, string $signature, ?int $nowMs = null): bool
    {
        if ($secret === '' || $timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }
        if (!hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)) {
            return false;
        }
        $now = $nowMs ?? (int) floor(microtime(true) * 1000);
        return abs($now - (int) $timestamp) <= self::MAX_AGE_MS;
    }

    /**
     * What to refresh for an event: `careers:jobs` and `careers:job:<slug>` for job changes, `careers` for anything else, nothing for a test.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function tags(string $event, array $data = []): array
    {
        if ($event === 'webhook.test') {
            return [];
        }
        if (str_starts_with($event, 'careers.job.')) {
            return is_string($data['slug'] ?? null) ? ['careers:jobs', 'careers:job:' . $data['slug']] : ['careers:jobs'];
        }
        return ['careers'];
    }
}
