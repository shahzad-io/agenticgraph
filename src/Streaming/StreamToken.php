<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Illuminate\Encryption\MissingAppKeyException;

/**
 * A stream token: base64url JSON payload (stream id, execution id, expiry) and an HMAC-SHA256 signature. The key is
 * derived from the app key with a fixed context string, so the raw app key never signs anything sent to a browser.
 */
final class StreamToken
{
    /** Null only with no app key and `streaming.token.required` false: the routes then need no token. */
    public static function issue(string $streamId, ?string $executionId, int $ttl): ?string
    {
        $payload = self::encode((string) json_encode(['s' => $streamId, 'e' => $executionId, 'x' => now()->getTimestamp() + $ttl]));
        $signature = self::sign($payload);

        return $signature === null ? null : $payload.'.'.$signature;
    }

    public static function verify(string $token, string $streamId): TokenResult
    {
        $parts = explode('.', $token);
        $signature = count($parts) === 2 ? self::sign($parts[0]) : null;
        if ($signature === null || ! hash_equals($signature, $parts[1])) {
            return TokenResult::invalid();
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($data) || ($data['s'] ?? null) !== $streamId || ! is_int($data['x'] ?? null)) {
            return TokenResult::invalid();
        }
        if ($data['x'] < now()->getTimestamp()) {
            return TokenResult::expired();
        }

        return TokenResult::valid(is_string($data['e'] ?? null) ? $data['e'] : null);
    }

    private static function sign(string $payload): ?string
    {
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            if ((bool) config('agenticgraph.streaming.token.required', true)) {
                throw new MissingAppKeyException;
            }

            return null;
        }

        return self::encode(hash_hmac('sha256', $payload, hash_hmac('sha256', 'agenticgraph.stream-token', $appKey), true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
