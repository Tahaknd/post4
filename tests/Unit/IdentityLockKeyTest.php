<?php

use App\Domain\Identity\IdentityLockKey;
use App\Domain\Users\UserCreationLock;

it('derives the same key for the same project and id, every time', function () {
    expect(IdentityLockKey::for(1, 'alice'))->toBe(IdentityLockKey::for(1, 'alice'));
});

it('derives a pair of signed 32-bit integers whose first is the fixed identity namespace', function () {
    foreach (['alice', '', 'é', str_repeat('x', 200), "a\0b"] as $id) {
        [$namespace, $hash] = IdentityLockKey::for(5, $id);

        expect($namespace)->toBe(IdentityLockKey::NAMESPACE)
            ->and($hash)->toBeInt()->toBeGreaterThanOrEqual(-(2 ** 31))->toBeLessThan(2 ** 31);
    }
});

it('keeps the key spaces of projects and ids apart', function () {
    expect(IdentityLockKey::for(1, 'alice'))->not->toBe(IdentityLockKey::for(2, 'alice'))
        ->and(IdentityLockKey::for(1, 'alice'))->not->toBe(IdentityLockKey::for(1, 'alicf'))
        // The separator: project 1 with id "2:x" and project 12 with id ":x" must not be the same text.
        ->and(IdentityLockKey::for(1, '2:x'))->not->toBe(IdentityLockKey::for(12, ':x'))
        ->and(IdentityLockKey::for(1, "2\0x"))->not->toBe(IdentityLockKey::for(12, "\0x"));
});

it('matches the xxh32 of "project NUL id" (the derivation is part of the lock contract)', function () {
    $expected = unpack('N', hash('xxh32', "9\0alice", true))[1];
    $expected = $expected >= 2 ** 31 ? $expected - 2 ** 32 : $expected;

    expect(IdentityLockKey::for(9, 'alice'))->toBe([IdentityLockKey::NAMESPACE, $expected]);
});

it('spreads many ids over the key space without collapsing onto few keys', function () {
    $hashes = [];
    foreach (range(1, 5000) as $n) {
        $hashes[IdentityLockKey::for(1, "user-{$n}")[1]] = true;
    }

    // 5000 draws from 2^32 values: a collision is possible but at most a handful.
    expect(count($hashes))->toBeGreaterThan(4990);
});

it('returns the keys of several ids in one global ascending order, whatever order they are given in', function () {
    $ids = ['alice', 'bob', 'carol', 'dave', 'erin'];
    $forward = IdentityLockKey::sorted(1, $ids);
    $reverse = IdentityLockKey::sorted(1, array_reverse($ids));

    expect($forward)->toBe($reverse);

    $hashes = array_column($forward, 1);
    $sorted = $hashes;
    sort($sorted);

    expect($hashes)->toBe($sorted)->and(count($forward))->toBe(5);
});

it('takes the lock of an id once, and the pair A,B in the same order as the pair B,A', function () {
    expect(IdentityLockKey::sorted(1, ['alice', 'alice']))->toBe([IdentityLockKey::for(1, 'alice')])
        ->and(IdentityLockKey::sorted(1, ['alice', 'bob']))->toBe(IdentityLockKey::sorted(1, ['bob', 'alice']))
        ->and(IdentityLockKey::sorted(1, []))->toBe([]);
});

it('collapses two ids that hash alike into one lock instead of locking the same key twice', function () {
    // Find a real collision of the 32-bit hash by the birthday method (about 80k draws), then check it. The ids are
    // md5 texts: xxh32 never collides on short ids that differ in one 4-byte word, so "id-1", "id-2" would not do.
    $seen = [];
    for ($n = 0; $n < 600_000; $n++) {
        $id = md5((string) $n);
        $hash = IdentityLockKey::for(1, $id)[1];
        if (isset($seen[$hash])) {
            expect(IdentityLockKey::sorted(1, [$seen[$hash], $id]))->toHaveCount(1)
                ->and($seen[$hash])->not->toBe($id);

            return;
        }
        $seen[$hash] = $id;
    }

    $this->fail('no 32-bit collision found in 600k draws (about 40 are expected)');
});

it('does not touch the single-key space of UserCreationLock (the two-int form is a different Postgres key space)', function () {
    // pg_advisory_xact_lock(bigint) and pg_advisory_xact_lock(int, int) never conflict; the namespace is documented
    // on the class. This pins that the user lock still is a single bigint, the other form.
    expect(UserCreationLock::KEY)->toBeInt()->toBeGreaterThan(2 ** 32);
});
