<?php

namespace App\Domain\Insights\Query;

/** Shape checks shared by the `fromCanonical` constructors of the insight types. */
final class Canonical
{
    private function __construct() {}

    /**
     * The array has exactly these keys (any order): no unknown, no missing one.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     *
     * @throws InvalidInsightQuery
     */
    public static function expectKeys(array $data, array $keys, string $what): void
    {
        $actual = array_keys($data);
        sort($actual);
        $expected = $keys;
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidInsightQuery("Malformed {$what}: unexpected or missing keys");
        }
    }
}
