<?php

namespace App\Domain\Insights\Query;

/** One condition on a JSON property of the event. Key and value only ever travel as bound parameters. */
final readonly class PropertyFilter
{
    public const MAX_KEY_LENGTH = 200;

    public const MAX_VALUE_LENGTH = 1000;

    public function __construct(
        public string $key,
        public FilterOperator $operator,
        public ?string $value = null,
    ) {
        if ($key === '' || mb_strlen($key) > self::MAX_KEY_LENGTH || ! mb_check_encoding($key, 'UTF-8')) {
            throw new InvalidInsightQuery('Filter property key must be 1 to '.self::MAX_KEY_LENGTH.' characters of valid UTF-8');
        }

        if ($operator->needsValue()) {
            if ($value === null || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                throw new InvalidInsightQuery('Filter value is required and at most '.self::MAX_VALUE_LENGTH.' characters of valid UTF-8');
            }
            if ($operator === FilterOperator::Contains && $value === '') {
                throw new InvalidInsightQuery('A "contains" filter needs a non-empty value');
            }
        } elseif ($value !== null) {
            throw new InvalidInsightQuery('The "is set" filter takes no value');
        }
    }

    /**
     * A list of filters from their canonical arrays (strict: wrong shape, types or operator are an InvalidInsightQuery,
     * never a TypeError).
     *
     * @return list<PropertyFilter>
     */
    public static function listFromCanonical(mixed $filters): array
    {
        if (! is_array($filters) || ! array_is_list($filters)) {
            throw new InvalidInsightQuery('Malformed filters');
        }

        return array_map(function (mixed $filter): self {
            if (! is_array($filter)) {
                throw new InvalidInsightQuery('Malformed filter');
            }
            Canonical::expectKeys($filter, ['key', 'operator', 'value'], 'filter');
            if (! is_string($filter['key']) || ! is_string($filter['operator']) || ($filter['value'] !== null && ! is_string($filter['value']))) {
                throw new InvalidInsightQuery('Malformed filter');
            }

            return new self(
                $filter['key'],
                FilterOperator::tryFrom($filter['operator']) ?? throw new InvalidInsightQuery('Unknown filter operator'),
                $filter['value'],
            );
        }, $filters);
    }

    /**
     * The filters of a client INPUT as the list {@see self::listFromCanonical()} reads: every filter gets all three keys
     * (a missing one is null, which the strict reader then rejects or accepts as the operator demands); anything that is
     * not a list of arrays is passed on as it is and rejected there.
     */
    public static function canonicalListFromInput(mixed $filters): mixed
    {
        $filters ??= [];
        if (! is_array($filters) || ! array_is_list($filters)) {
            throw new InvalidInsightQuery('Malformed filters');
        }

        return array_map(
            fn (mixed $f) => is_array($f) ? ['key' => $f['key'] ?? null, 'operator' => $f['operator'] ?? null, 'value' => $f['value'] ?? null] : $f,
            $filters,
        );
    }

    /** @return array{key: string, operator: string, value: ?string} */
    public function canonical(): array
    {
        return ['key' => $this->key, 'operator' => $this->operator->value, 'value' => $this->value];
    }
}
