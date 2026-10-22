<?php

use App\Domain\Capture\InvalidPayload;
use App\Domain\Capture\PayloadDecoder;
use Illuminate\Http\Request;

function jsonRequest(string $body): Request
{
    return Request::create('/e/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
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
