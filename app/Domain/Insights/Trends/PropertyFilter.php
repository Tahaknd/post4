<?php

namespace App\Domain\Insights\Trends;

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
            throw new InvalidTrendsQuery('Filter property key must be 1 to '.self::MAX_KEY_LENGTH.' characters of valid UTF-8');
        }

        if ($operator->needsValue()) {
            if ($value === null || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                throw new InvalidTrendsQuery('Filter value is required and at most '.self::MAX_VALUE_LENGTH.' characters of valid UTF-8');
            }
            if ($operator === FilterOperator::Contains && $value === '') {
                throw new InvalidTrendsQuery('A "contains" filter needs a non-empty value');
            }
        } elseif ($value !== null) {
            throw new InvalidTrendsQuery('The "is set" filter takes no value');
        }
    }

    /** @return array{key: string, operator: string, value: ?string} */
    public function canonical(): array
    {
        return ['key' => $this->key, 'operator' => $this->operator->value, 'value' => $this->value];
    }
}
