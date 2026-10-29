<?php

namespace App\Domain\Capture;

use Illuminate\Http\Request;

/** Not final only so a test can spy on whether a request was decoded (tests/Feature/SdkStubsTest.php). */
class PayloadDecoder
{
    /**
     * @param  int  $maxDecodedBytes  Upper bound for a gunzipped body. json_decode amplifies memory
     *                                roughly 33x, so keep this well below memory_limit / 33.
     * @param  int|null  $maxEvents  Maximum number of top-level events per request (null = unlimited).
     * @param  int  $maxContainers  Upper bound for "[" plus "{" characters in the decoded body. json_decode
     *                              allocates a whole array per container (~60x the input for "[0],[0],..."),
     *                              so the byte limit alone cannot bound its memory.
     */
    public function __construct(
        private readonly int $maxDecodedBytes = 2_000_000,
        private readonly ?int $maxEvents = null,
        private readonly int $maxContainers = 100_000,
    ) {
        // gzdecode(..., 0) means "unlimited": a misconfiguration must not disable the bomb protection.
        if ($maxDecodedBytes <= 0) {
            throw new \InvalidArgumentException('maxDecodedBytes must be greater than zero');
        }
        if ($maxEvents !== null && $maxEvents <= 0) {
            throw new \InvalidArgumentException('maxEvents must be greater than zero');
        }
        if ($maxContainers <= 0) {
            throw new \InvalidArgumentException('maxContainers must be greater than zero');
        }
    }

    /**
     * The body of a posthog-js feature flag request (`/flags`, `/decide`): one JSON object, sent the same ways as an
     * event (plain JSON, gzip with or without `compression=gzip-js`, a `data=` form field, base64), with the same
     * size and complexity caps. Anything that is not an object (a list, a scalar) is an InvalidPayload.
     *
     * @return array<string, mixed>
     */
    public function decodeObject(Request $request): array
    {
        $data = $this->decodeJson($request);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidPayload('Payload must be an object');
        }

        return $data;
    }

    public function decode(Request $request): CapturePayload
    {
        $data = $this->decodeJson($request);

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

        if ($this->maxEvents !== null && count($events) > $this->maxEvents) {
            throw new TooManyEvents('Too many events in one request');
        }

        $events = array_values(array_filter($events, 'is_array'));
        $apiKey = is_string($apiKey) && $apiKey !== '' ? $apiKey : $this->apiKeyFromEvents($events);

        return new CapturePayload(
            $apiKey,
            $events,
            is_string($sentAt) ? $sentAt : null,
        );
    }

    /** The request body as decoded JSON: unwrapped from form/base64, gunzipped, capped, parsed. */
    private function decodeJson(Request $request): mixed
    {
        $raw = $this->rawBody($request);

        if (str_starts_with($raw, "\x1f\x8b")) {
            $raw = @gzdecode($raw, $this->maxDecodedBytes);
            if ($raw === false) {
                throw new InvalidPayload('Invalid or oversized gzip body');
            }
        }

        // Cheap structural cap before json_decode: it over-counts brackets inside strings, which is fine.
        if (substr_count($raw, '[') + substr_count($raw, '{') > $this->maxContainers) {
            throw new InvalidPayload('Payload too complex');
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidPayload('Invalid JSON');
        }
    }

    private function rawBody(Request $request): string
    {
        $contentType = strtolower((string) $request->header('Content-Type'));

        if (! str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return $request->getContent();
        }

        $data = $request->input('data');
        if (! is_string($data) || $data === '') {
            // $_POST is empty when PHP did not parse the body (limits, non-SAPI requests): read the raw body.
            parse_str($request->getContent(), $form);
            $data = $form['data'] ?? null;
        }
        if (! is_string($data) || $data === '') {
            throw new InvalidPayload('Missing "data" field');
        }

        if ($data[0] === '{' || $data[0] === '[') {
            return $data;
        }

        // A "+" that was not URL-encoded arrives as a space; also accept URL-safe base64.
        $data = strtr($data, [' ' => '+', '-' => '+', '_' => '/']);

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
            $properties = is_array($event['properties'] ?? null) ? $event['properties'] : [];

            foreach ([$event['api_key'] ?? null, $properties['token'] ?? null] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
