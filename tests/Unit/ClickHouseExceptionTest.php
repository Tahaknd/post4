<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use Illuminate\Support\Facades\Http;

/*
 * Bodies as 25.8 sends them (checked against the live server: plain text over HTTP 4xx/5xx, and the JSON
 * form with an `exception` key when wait_end_of_query=1 and the query dies after the 200 status).
 */
const CH_VERSION = '(version 25.8.33.6 (official build))';

dataset('clickhouse errors', [
    'timeout' => [159, ClickHouseErrorKind::Timeout, 'Code: 159. DB::Exception: Timeout exceeded: elapsed 20.003 seconds, maximum: 20. (TIMEOUT_EXCEEDED) '.CH_VERSION."\n", false, false],
    'memory' => [241, ClickHouseErrorKind::MemoryLimit, 'Code: 241. DB::Exception: Query memory limit exceeded: would use 400.01 MiB (attempt to allocate chunk of 4.00 MiB), maximum: 381.47 MiB.: While executing AggregatingTransform. (MEMORY_LIMIT_EXCEEDED) '.CH_VERSION."\n", false, false],
    'too many rows' => [396, ClickHouseErrorKind::TooManyRows, 'Code: 396. DB::Exception: Limit for result exceeded, max rows: 20.00 thousand, current rows: 20.01 thousand. (TOO_MANY_ROWS_OR_BYTES) '.CH_VERSION."\n", false, false],
    'busy' => [202, ClickHouseErrorKind::Busy, 'Code: 202. DB::Exception: Too many simultaneous queries for user posthog_ro. Current: 4, maximum: 3. (TOO_MANY_SIMULTANEOUS_QUERIES) '.CH_VERSION."\n", true, true],
    'constraint' => [452, ClickHouseErrorKind::ConstraintViolation, 'Code: 452. DB::Exception: Setting max_execution_time shouldn\'t be greater than 22. (SETTING_CONSTRAINT_VIOLATION) '.CH_VERSION."\n", false, false],
    'authentication' => [516, ClickHouseErrorKind::AuthenticationFailed, 'Code: 516. DB::Exception: posthog_ro: Authentication failed: password is incorrect, or there is no user with such name. (AUTHENTICATION_FAILED) '.CH_VERSION."\n", false, false],
    'other code' => [62, ClickHouseErrorKind::Unknown, 'Code: 62. DB::Exception: Syntax error: failed at position 8. (SYNTAX_ERROR) '.CH_VERSION."\n", false, false],
]);

it('maps a ClickHouse error body to a kind, a code, and the retry/busy flags', function (int $code, ClickHouseErrorKind $kind, string $body, bool $retryable, bool $busy) {
    $e = ClickHouseException::fromResponse($body, 500);

    expect($e->errorCode)->toBe($code)
        ->and($e->kind)->toBe($kind)
        ->and($e->isRetryable())->toBe($retryable)
        ->and($e->isBusy())->toBe($busy)
        ->and($e->httpStatus)->toBe(500)
        ->and($e->getCode())->toBe($code)
        ->and($e)->toBeInstanceOf(RuntimeException::class);
})->with('clickhouse errors');

it('reads the code from the JSON form of a 200 response with an exception key', function () {
    $body = '{"meta":[],"data":[],"rows":0,"exception":"Code: 159. DB::Exception: Timeout exceeded: elapsed 20.1 seconds, maximum: 20. (TIMEOUT_EXCEEDED) '.CH_VERSION.'"}';

    expect(ClickHouseException::fromResponse($body)->kind)->toBe(ClickHouseErrorKind::Timeout);
});

it('prefers the X-ClickHouse-Exception-Code header and ignores a malformed one', function () {
    $body = 'Code: 62. DB::Exception: Syntax error. (SYNTAX_ERROR)';

    expect(ClickHouseException::fromResponse($body, 400, '159')->kind)->toBe(ClickHouseErrorKind::Timeout)
        ->and(ClickHouseException::fromResponse($body, 400, 'abc')->errorCode)->toBe(62);
});

it('does not take a Code: N. echoed inside the text for the server code', function () {
    // ClickHouse echoes query fragments and rejected parameter values: a hostile value must not be able to
    // make an error look like a retryable busy signal or a timeout.
    $body = "Code: 62. DB::Exception: Cannot parse input: expected '\\n' before: 'Code: 202. DB::Exception: Too many'. (SYNTAX_ERROR) ".CH_VERSION;
    $json = '{"meta":[],"data":[],"rows":0,"exception":"Code: 62. DB::Exception: value \'x\\" Code: 159. y\'. (SYNTAX_ERROR)"}';

    expect(ClickHouseException::fromResponse($body)->kind)->toBe(ClickHouseErrorKind::Unknown)
        ->and(ClickHouseException::fromResponse($json)->errorCode)->toBe(62)
        ->and(ClickHouseException::fromResponse('upstream: Code: 202. proxy text')->kind)->toBe(ClickHouseErrorKind::Unknown);
});

it('is Unknown without a code (proxy HTML, empty body, unparseable)', function (string $body) {
    $e = ClickHouseException::fromResponse($body, 502);

    expect($e->kind)->toBe(ClickHouseErrorKind::Unknown)->and($e->errorCode)->toBeNull()->and($e->isRetryable())->toBeFalse();
})->with(['', '<html>502 Bad Gateway</html>', 'Code: not a number']);

it('keeps raw server text in getMessage() truncated to 1000 characters and out of every user message', function () {
    $secret = "SELECT secret FROM t WHERE x = 'hunter2-param-value'";
    $e = ClickHouseException::fromResponse('Code: 241. DB::Exception: Query memory limit exceeded in scope '.$secret.str_repeat('z', 5000));

    expect($e->getMessage())->toStartWith('ClickHouse error: Code: 241.')
        ->and(mb_strlen($e->getMessage()))->toBe(strlen('ClickHouse error: ') + 1000)
        ->and($e->getMessage())->toContain('hunter2');

    foreach (ClickHouseErrorKind::cases() as $kind) {
        expect($kind->userMessage())->not->toContain('hunter2')->not->toContain('Code')->not->toContain('SELECT')->not->toContain('DB::')
            ->and($kind->userMessage())->not->toBe('');
    }
    expect($e->userMessage())->toBe(ClickHouseErrorKind::MemoryLimit->userMessage())->not->toContain('hunter2');
});

it('gives every kind a message and only busy and unavailable are retryable', function () {
    $retryable = array_values(array_filter(ClickHouseErrorKind::cases(), fn (ClickHouseErrorKind $k) => $k->isRetryable()));

    expect($retryable)->toEqual([ClickHouseErrorKind::Busy, ClickHouseErrorKind::Unavailable])
        ->and(ClickHouseErrorKind::fromCode(null))->toBe(ClickHouseErrorKind::Unknown)
        ->and(ClickHouseErrorKind::fromCode(999999))->toBe(ClickHouseErrorKind::Unknown);
});

it('makes the client throw a ClickHouseException for HTTP errors, 200 exceptions and bad bodies', function () {
    $client = new ClickHouseClient('http://ch:8123', 'posthog', 'u', 'p');
    $answer = null;
    Http::fake(function () use (&$answer) {
        return $answer;
    });
    $caught = function (callable $call) {
        try {
            $call();
        } catch (Throwable $e) {
            return $e;
        }
        throw new RuntimeException('expected an exception');
    };

    $answer = Http::response('Code: 202. DB::Exception: Too many simultaneous queries for user posthog_ro. Current: 4, maximum: 3. (TOO_MANY_SIMULTANEOUS_QUERIES)', 500);
    $busy = $caught(fn () => $client->select('SELECT 1'));

    $answer = Http::response('{"meta":[],"data":[],"rows":0,"exception":"Code: 159. DB::Exception: Timeout exceeded. (TIMEOUT_EXCEEDED)"}');
    $timeout = $caught(fn () => $client->select('SELECT 1'));

    $answer = Http::response('Code: 516. DB::Exception: Authentication failed. (AUTHENTICATION_FAILED)', 403, ['X-ClickHouse-Exception-Code' => '516']);
    $auth = $caught(fn () => $client->execute('SELECT 1'));

    $answer = Http::response('this is not json');
    $garbage = $caught(fn () => $client->select('SELECT 1'));

    expect($busy)->toBeInstanceOf(ClickHouseException::class)
        ->and($busy->isBusy())->toBeTrue()
        ->and($busy->httpStatus)->toBe(500)
        ->and($timeout->kind)->toBe(ClickHouseErrorKind::Timeout)
        ->and($auth->kind)->toBe(ClickHouseErrorKind::AuthenticationFailed)
        ->and($auth->httpStatus)->toBe(403)
        ->and($garbage->kind)->toBe(ClickHouseErrorKind::Unknown)
        ->and($garbage->getMessage())->toBe('ClickHouse error: unparseable response');
});
