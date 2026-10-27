<?php

use App\Domain\Capture\Enrichment\BoundedLru;

it('evicts the least recently used entry when full', function () {
    $lru = new BoundedLru(maxEntries: 3, maxBytes: 1_000_000);
    $lru->put('a', 1, 10);
    $lru->put('b', 2, 10);
    $lru->put('c', 3, 10);

    expect($lru->get('a'))->toBe(1);   // a is now the most recently used

    $lru->put('d', 4, 10);             // evicts b, the oldest

    expect($lru->has('b'))->toBeFalse()
        ->and($lru->keys())->toBe(['c', 'a', 'd']);
});

it('keeps the byte estimate in step with puts, replacements and evictions', function () {
    $lru = new BoundedLru(maxEntries: 100, maxBytes: 1_000_000);
    $lru->put('a', 'x', 100);
    $one = $lru->bytes();
    $lru->put('a', 'y', 100);

    expect($lru->bytes())->toBe($one)->and($lru->count())->toBe(1);

    $small = new BoundedLru(maxEntries: 100, maxBytes: 600);
    for ($i = 0; $i < 20; $i++) {
        $small->put("k{$i}", $i, 100);
    }

    expect($small->bytes())->toBeLessThanOrEqual(600)->and($small->count())->toBeGreaterThan(0);
});

it('does not store a single entry that exceeds the byte budget', function () {
    $lru = new BoundedLru(maxEntries: 10, maxBytes: 100);
    $lru->put('big', 1, 5000);

    expect($lru->count())->toBe(0)->and($lru->bytes())->toBe(0);
});

it('stores nothing with zero entries allowed', function () {
    $lru = new BoundedLru(maxEntries: 0, maxBytes: 1000);
    $lru->put('a', 1, 1);

    expect($lru->count())->toBe(0);
});
