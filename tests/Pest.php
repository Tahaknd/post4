<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// A test that forgets Http::fake() must never reach a real service (e.g. ClickHouse).
pest()->beforeEach(fn () => Http::preventStrayRequests())->in('Feature', 'Unit');

function jsonRequest(string $body): Request
{
    return Request::create('/e/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
}
