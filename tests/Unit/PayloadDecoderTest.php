<?php

use App\Domain\Capture\InvalidPayload;
use App\Domain\Capture\PayloadDecoder;
use App\Domain\Capture\TooManyEvents;
use Illuminate\Http\Request;

function formRequest(array $parameters): Request
{
    return Request::create('/e/', 'POST', $parameters, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
}

beforeEach(function () {
    $this->decoder = new PayloadDecoder(maxDecodedBytes: 100_000);
});

it('decodes a single event with api_key', function () {
    $p = $this->decoder->decode(jsonRequest('{"api_key":"phc_x","event":"e","distinct_id":"u"}'));

    expect($p->apiKey)->toBe('phc_x')->and($p->events)->toHaveCount(1)->and($p->events[0]['event'])->toBe('e');
});

it('decodes a batch wrapper with sent_at', function () {
    $p = $this->decoder->decode(jsonRequest('{"api_key":"phc_x","sent_at":"2026-10-22T12:00:00Z","batch":[{"event":"a","distinct_id":"u"},{"event":"b","distinct_id":"u"}]}'));

    expect($p->events)->toHaveCount(2)->and($p->sentAt)->toBe('2026-10-22T12:00:00Z');
});

it('decodes a plain array of events and takes the token from properties', function () {
    $p = $this->decoder->decode(jsonRequest('[{"event":"a","properties":{"token":"phc_t","distinct_id":"u"}}]'));

    expect($p->apiKey)->toBe('phc_t')->and($p->events)->toHaveCount(1);
});

it('decodes gzip bodies by magic bytes', function () {
    $p = $this->decoder->decode(jsonRequest(gzencode('{"api_key":"phc_x","event":"e","distinct_id":"u"}')));

    expect($p->apiKey)->toBe('phc_x');
});

it('decodes base64 form data', function () {
    $json = '{"api_key":"phc_x","event":"e","distinct_id":"u"}';
    $request = Request::create('/e/', 'POST', ['data' => base64_encode($json)], server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);

    expect($this->decoder->decode($request)->apiKey)->toBe('phc_x');
});

it('decodes raw json form data', function () {
    $json = '{"api_key":"phc_x","event":"e","distinct_id":"u"}';
    $request = Request::create('/e/', 'POST', ['data' => $json], server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);

    expect($this->decoder->decode($request)->events)->toHaveCount(1);
});

it('rejects malformed payloads', function (string $body) {
    $this->decoder->decode(jsonRequest($body));
})->with(['not json', '"a string"', '{"api_key":"k","batch":"nope"}'])->throws(InvalidPayload::class);

it('rejects gzip bombs above the decoded limit', function () {
    $this->decoder->decode(jsonRequest(gzencode(str_repeat('a', 200_000))));
})->throws(InvalidPayload::class);

it('rejects a non-positive decoded size limit', function (int $limit) {
    new PayloadDecoder($limit);
})->with(['zero (gzdecode would be unlimited)' => 0, 'negative' => -1])->throws(InvalidArgumentException::class);

it('rejects a non-positive event limit', function (int $limit) {
    new PayloadDecoder(100_000, $limit);
})->with(['zero' => 0, 'negative' => -5])->throws(InvalidArgumentException::class);

it('defaults to a 2 MB decoded limit', function () {
    $limit = (new ReflectionProperty(PayloadDecoder::class, 'maxDecodedBytes'))->getValue(new PayloadDecoder());

    expect($limit)->toBe(2_000_000);
});

it('rejects more events than allowed in a list', function () {
    $decoder = new PayloadDecoder(100_000, maxEvents: 2);

    $decoder->decode(jsonRequest('[{"event":"a"},{"event":"b"},{"event":"c"}]'));
})->throws(TooManyEvents::class);

it('rejects more events than allowed in a batch', function () {
    $decoder = new PayloadDecoder(100_000, maxEvents: 2);

    $decoder->decode(jsonRequest('{"api_key":"k","batch":[{"event":"a"},{"event":"b"},{"event":"c"}]}'));
})->throws(TooManyEvents::class);

it('counts events before filtering out non-array entries', function () {
    $decoder = new PayloadDecoder(100_000, maxEvents: 2);

    $decoder->decode(jsonRequest('[1,2,3]'));
})->throws(TooManyEvents::class);

it('accepts exactly the allowed number of events', function () {
    $decoder = new PayloadDecoder(100_000, maxEvents: 2);

    expect($decoder->decode(jsonRequest('[{"event":"a"},{"event":"b"}]'))->events)->toHaveCount(2);
});

it('treats TooManyEvents as an InvalidPayload', function () {
    expect(new TooManyEvents('x'))->toBeInstanceOf(InvalidPayload::class);
});

it('rejects an array in the form data field', function () {
    $this->decoder->decode(formRequest(['data' => ['x']]));
})->throws(InvalidPayload::class);

it('ignores a non-string api_key and falls back to properties.token', function (mixed $apiKey) {
    $body = json_encode(['api_key' => $apiKey, 'event' => 'e', 'distinct_id' => 'u', 'properties' => ['token' => 'phc_t']]);

    expect($this->decoder->decode(jsonRequest($body))->apiKey)->toBe('phc_t');
})->with([
    'empty string' => '',
    'integer' => 123,
    'array' => [['x']],
    'bool' => true,
]);

it('ignores an empty batch api_key and falls back to the first event token', function () {
    $p = $this->decoder->decode(jsonRequest('{"api_key":"","batch":[{"event":"e","properties":{"token":"phc_t"}}]}'));

    expect($p->apiKey)->toBe('phc_t');
});

it('returns an oversized api_key as is', function () {
    $key = str_repeat('k', 10_000);
    $p = $this->decoder->decode(jsonRequest(json_encode(['api_key' => $key, 'event' => 'e', 'distinct_id' => 'u'])));

    expect($p->apiKey)->toBe($key);
});

it('decodes base64 data where + arrived as a space', function () {
    $json = '{"api_key":"phc_x","event":"e","distinct_id":"u","properties":{"a":"???>>>"}}';
    $base64 = base64_encode($json);
    expect($base64)->toContain('+');

    $p = $this->decoder->decode(formRequest(['data' => str_replace('+', ' ', $base64)]));

    expect($p->apiKey)->toBe('phc_x')->and($p->events[0]['properties']['a'])->toBe('???>>>');
});

it('decodes url-safe base64 data', function () {
    $json = '{"api_key":"phc_x","event":"e","distinct_id":"u","properties":{"a":"???>>>"}}';
    $base64 = base64_encode($json);
    expect($base64)->toContain('/')->toContain('+');

    $p = $this->decoder->decode(formRequest(['data' => strtr($base64, '+/', '-_')]));

    expect($p->apiKey)->toBe('phc_x')->and($p->events[0]['properties']['a'])->toBe('???>>>');
});

it('still rejects invalid base64 data', function () {
    $this->decoder->decode(formRequest(['data' => 'not*base64!']));
})->throws(InvalidPayload::class);

it('rejects gzip inside base64 form data above the decoded limit', function () {
    $this->decoder->decode(formRequest(['data' => base64_encode(gzencode(str_repeat('a', 200_000)))]));
})->throws(InvalidPayload::class);

it('decodes gzip inside base64 form data within the limit', function () {
    $json = '{"api_key":"phc_x","event":"e","distinct_id":"u"}';

    expect($this->decoder->decode(formRequest(['data' => base64_encode(gzencode($json))]))->apiKey)->toBe('phc_x');
});

it('yields zero events for payloads without events', function (string $body) {
    $p = $this->decoder->decode(jsonRequest($body));

    expect($p->events)->toBe([])->and($p->apiKey)->toBeNull();
})->with(['empty array' => '[]', 'empty object' => '{}', 'scalars in a list' => '[1,2]']);

it('rejects json nested deeper than 512 levels', function () {
    $this->decoder->decode(jsonRequest(str_repeat('[', 600).str_repeat(']', 600)));
})->throws(InvalidPayload::class);

it('keeps invalid payload messages generic', function () {
    try {
        $this->decoder->decode(jsonRequest('{"secret-marker": nope'));
    } catch (InvalidPayload $e) {
        expect($e->getMessage())->not->toContain('secret-marker');

        return;
    }

    throw new RuntimeException('Expected InvalidPayload');
});
