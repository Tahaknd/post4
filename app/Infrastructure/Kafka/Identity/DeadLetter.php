<?php

namespace App\Infrastructure\Kafka\Identity;

/**
 * The message the identity consumer writes to the `events_dlq` topic for an event it cannot process: JSON with the
 * error, where the event came from, and the event itself.
 *
 *   {"failed_at":"2026-10-29 12:00:00.123","consumer":"identity","error_class":"JsonException","error":"Syntax error",
 *    "source_topic":"events","source_partition":1,"source_offset":4711,"source_key":"12:alice",
 *    "payload_bytes":123,"payload_encoding":"utf8","payload_truncated":false,"payload":"{...}"}
 *
 * `payload` is the original message: as text when it is valid UTF-8, else base64 (payload_encoding says which). It is
 * cut to MAX_PAYLOAD_BYTES when the envelope would not fit into a Kafka message (payload_truncated); the original is
 * still on the events topic at source_topic/source_partition/source_offset for as long as that topic keeps it.
 */
final class DeadLetter
{
    /** Broker default message.max.bytes is 1 MB; the envelope adds well under 2 KB, JSON escaping can multiply the rest. */
    private const MAX_ENCODED_BYTES = 900_000;

    private const MAX_PAYLOAD_BYTES = 200_000;

    private const MAX_ERROR_CHARS = 1000;

    /** @return array{key: ?string, payload: string} */
    public static function message(ConsumedMessage $source, string $errorClass, string $error, string $failedAt): array
    {
        $payload = $source->payload;
        $encoding = mb_check_encoding($payload, 'UTF-8') ? 'utf8' : 'base64';
        $envelope = fn (string $body, bool $truncated): string => json_encode([
            'failed_at' => $failedAt,
            'consumer' => 'identity',
            'error_class' => $errorClass,
            'error' => mb_substr(trim((string) preg_replace('/\s+/', ' ', mb_convert_encoding($error, 'UTF-8', 'UTF-8'))), 0, self::MAX_ERROR_CHARS),
            'source_topic' => $source->topic,
            'source_partition' => $source->partition,
            'source_offset' => $source->offset,
            'source_key' => $source->key === null ? null : mb_convert_encoding($source->key, 'UTF-8', 'UTF-8'),
            'payload_bytes' => strlen($source->payload),
            'payload_encoding' => $encoding,
            'payload_truncated' => $truncated,
            'payload' => $body,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        $body = $encoding === 'utf8' ? $payload : base64_encode($payload);
        $json = $envelope($body, false);

        // mb_strcut cuts at a character boundary, so the text stays valid UTF-8; base64 text is ASCII. Escaping can
        // make a cut payload longer than its bytes (control characters become \u00XX), hence the loop.
        for ($cut = self::MAX_PAYLOAD_BYTES; strlen($json) > self::MAX_ENCODED_BYTES && $cut >= 1_000; $cut = intdiv($cut, 2)) {
            $json = $envelope(mb_strcut($body, 0, $cut, 'UTF-8'), true);
        }

        return ['key' => $source->key, 'payload' => $json];
    }
}
