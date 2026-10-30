<?php

use App\Domain\Dashboards\Labels;
use Illuminate\Support\Facades\Validator;

function labelErrors(string $field, mixed $value): array
{
    $rules = $field === 'name' ? Labels::name() : Labels::description();

    return Validator::make([$field => $value], [$field => $rules])->errors()->keys();
}

it('trims a name like a project name, with Unicode spaces', function () {
    expect(Labels::normaliseName("  \u{00A0}Weekly signups\u{3000} "))->toBe('Weekly signups');
});

it('accepts names of 1 to 120 characters and refuses blank, long and invisible ones', function () {
    expect(labelErrors('name', 'a'))->toBe([])
        ->and(labelErrors('name', str_repeat('é', 120)))->toBe([])
        ->and(labelErrors('name', str_repeat('a', 121)))->toBe(['name'])
        ->and(labelErrors('name', ''))->toBe(['name'])
        ->and(labelErrors('name', "\u{00A0}"))->toBe(['name'])
        ->and(labelErrors('name', "a\nb"))->toBe(['name'])
        ->and(labelErrors('name', "a\u{202E}b"))->toBe(['name'])
        ->and(labelErrors('name', "a\u{200B}b"))->toBe(['name'])
        ->and(labelErrors('name', ['x']))->toBe(['name']);
});

it('accepts a description of at most 500 characters with line breaks, and null', function () {
    expect(labelErrors('description', null))->toBe([])
        ->and(labelErrors('description', "line one\r\nline\ttwo"))->toBe([])
        ->and(labelErrors('description', str_repeat('a', 500)))->toBe([])
        ->and(labelErrors('description', str_repeat('a', 501)))->toBe(['description'])
        ->and(labelErrors('description', "a\u{0007}b"))->toBe(['description'])
        ->and(labelErrors('description', "a\u{202E}b"))->toBe(['description']);
});

it('stores a blank description as null and trims the rest', function () {
    expect(Labels::normaliseDescription(null))->toBeNull()
        ->and(Labels::normaliseDescription("  \n "))->toBeNull()
        ->and(Labels::normaliseDescription("  hello\nworld \n"))->toBe("hello\nworld");
});
