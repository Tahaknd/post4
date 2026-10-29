<?php

use App\Domain\Capture\EventNormalizer;
use App\Domain\Identity\DistinctIdProblem;
use App\Domain\Identity\DistinctIdRules;
use Carbon\CarbonImmutable;

it('refuses every reserved id, whatever its case and surrounding whitespace', function (string $id) {
    foreach ([$id, strtoupper($id), ucfirst($id), "  {$id}  ", "\t{$id}"] as $variant) {
        // A tab is a control character, which is refused too; the point is that the id never passes.
        expect(DistinctIdRules::isValid($variant))->toBeFalse("'{$variant}' should be refused");
    }

    expect(DistinctIdRules::problem($id))->toBe(DistinctIdProblem::Reserved);
})->with(DistinctIdRules::RESERVED);

it('lists the ids the task and PostHog name', function () {
    expect(DistinctIdRules::RESERVED)->toContain('null', 'undefined', '0', '[object object]', 'nan', 'none', 'true', 'false')
        ->and(array_map('strtolower', DistinctIdRules::RESERVED))->toBe(DistinctIdRules::RESERVED);
});

it('refuses [object Object], NaN, None and none in the spellings JavaScript and Python produce', function (string $id) {
    expect(DistinctIdRules::problem($id))->toBe(DistinctIdProblem::Reserved);
})->with(['[object Object]', 'NaN', 'None', 'none', 'NULL', 'Undefined', 'True', 'FALSE', 'Anonymous', 'Guest', 'distinct_id', 'distinctId', 'not_authenticated', 'ID', 'Email']);

it('accepts ordinary ids, including ones that merely contain a reserved word', function (string $id) {
    expect(DistinctIdRules::problem($id))->toBeNull()->and(DistinctIdRules::isValid($id))->toBeTrue();
})->with([
    'user-42', 'alice@example.com', '0197a000-0000-7000-8000-000000000001', 'null-user', 'noneofyourbusiness', 'idle', '00', '1',
    'None of them', 'anonymous-1', 'guest_17', 'é', '日本語のid', 'a b', "has'quote", '[object Object]x', 'id-',
]);

it('refuses a blank id', function (string $id) {
    expect(DistinctIdRules::problem($id))->toBe(DistinctIdProblem::Blank);
})->with(['', ' ', "   \u{00A0}  ", "\u{3000}"]);

it('refuses an id above 200 characters and counts characters, not bytes', function () {
    expect(DistinctIdRules::problem(str_repeat('a', 200)))->toBeNull()
        ->and(DistinctIdRules::problem(str_repeat('a', 201)))->toBe(DistinctIdProblem::TooLong)
        ->and(DistinctIdRules::problem(str_repeat('é', 200)))->toBeNull()
        ->and(DistinctIdRules::problem(str_repeat('é', 201)))->toBe(DistinctIdProblem::TooLong)
        ->and(DistinctIdRules::problem(str_repeat('😀', 200)))->toBeNull()
        ->and(DistinctIdRules::problem(str_repeat('😀', 201)))->toBe(DistinctIdProblem::TooLong);
});

it('refuses control characters, among them the NUL that a Postgres text column cannot hold', function (string $id) {
    expect(DistinctIdRules::problem($id))->toBe(DistinctIdProblem::ControlCharacters);
})->with(["a\0b", "line\nbreak", "tab\tinside", "bell\x07", "del\x7F", "\x1Bescape"]);

it('uses the same length limit as the event normalizer, so an accepted capture id is never refused for its length', function () {
    $normalize = fn (string $id) => (new EventNormalizer)->normalize(
        ['event' => 'e', 'distinct_id' => $id],
        project: 1,
        now: CarbonImmutable::parse('2026-10-29 10:00:00', 'UTC'),
    );

    expect(DistinctIdRules::MAX_LENGTH)->toBe(200)
        ->and($normalize(str_repeat('a', DistinctIdRules::MAX_LENGTH)))->not->toBeNull()
        ->and($normalize(str_repeat('a', DistinctIdRules::MAX_LENGTH + 1)))->toBeNull();
});
