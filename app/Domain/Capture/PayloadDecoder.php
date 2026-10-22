<?php

namespace App\Domain\Capture;

use Illuminate\Http\Request;

final class PayloadDecoder
{
    public function __construct(private readonly int $maxDecodedBytes = 5_000_000) {}

    public function decode(Request $request): CapturePayload
    {
        $raw = $this->rawBody($request);

        if (str_starts_with($raw, "\x1f\x8b")) {
            $raw = @gzdecode($raw, $this->maxDecodedBytes);
            if ($raw === false) {
                throw new InvalidPayload('Invalid or oversized gzip body');
            }
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidPayload('Invalid JSON');
        }

        if (! is_array($data)) {
            throw new InvalidPayload('Payload must be an object or an array');
        }

        $sentAt = $request->query('sent_at');
        $apiKey = null;

        if (array_is_list($data)) {
            $events = $data;
        } elseif (array_key_exists('batch', $data)) {
            if (! is_array($data['batch'])) {
                throw new InvalidPayload('"batch" must be an array');
            }
            $events = $data['batch'];
            $apiKey = $data['api_key'] ?? null;
            $sentAt = $data['sent_at'] ?? $sentAt;
        } else {
            $events = [$data];
            $apiKey = $data['api_key'] ?? null;
            $sentAt = $data['sent_at'] ?? $sentAt;
        }

        $events = array_values(array_filter($events, 'is_array'));
        $apiKey ??= $this->apiKeyFromEvents($events);

        return new CapturePayload(
            is_string($apiKey) ? $apiKey : null,
            $events,
            is_string($sentAt) ? $sentAt : null,
        );
    }

    private function rawBody(Request $request): string
    {
        $contentType = strtolower((string) $request->header('Content-Type'));

        if (! str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return $request->getContent();
        }

        $data = $request->input('data');
        if (! is_string($data) || $data === '') {
            throw new InvalidPayload('Missing "data" field');
        }

        if ($data[0] === '{' || $data[0] === '[') {
            return $data;
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new InvalidPayload('Invalid base64 data');
        }

        return $decoded;
    }

    /** @param list<array<string, mixed>> $events */
    private function apiKeyFromEvents(array $events): ?string
    {
        foreach ($events as $event) {
            $candidate = $event['api_key'] ?? $event['properties']['token'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
