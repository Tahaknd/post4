<?php

namespace App\Domain\Insights\Sql;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Collects the bound parameters of ONE query and hands out their `{name:Type}` placeholders. This is the
 * only way a value reaches a generated query: builders concatenate class constants, enum fragments and the
 * placeholders returned here, never a value. Names are chosen by the builder (code, `^[a-z][a-z0-9_]*$`),
 * must be unique, and are NOT derived from values, so the SQL text of two queries built from the same
 * definition shape is identical whatever the values are.
 */
final class Params
{
    /** @var array<string, string|int|list<string>|DateTimeInterface> */
    private array $values = [];

    /** @return string the placeholder, e.g. `{from:DateTime64(3)}` */
    public function bind(string $name, ParamType $type, string|int|array|DateTimeInterface $value): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException("Invalid parameter name '{$name}'");
        }
        if (array_key_exists($name, $this->values)) {
            throw new InvalidArgumentException("Parameter '{$name}' is already bound");
        }
        $this->assertFits($name, $type, $value);
        $this->values[$name] = $value;

        return '{'.$name.':'.$type->value.'}';
    }

    /** @return array<string, string|int|list<string>|DateTimeInterface> */
    public function all(): array
    {
        return $this->values;
    }

    private function assertFits(string $name, ParamType $type, mixed $value): void
    {
        $fits = match ($type) {
            ParamType::Text => is_string($value) && mb_check_encoding($value, 'UTF-8'),
            ParamType::SmallCount => is_int($value) && $value >= 0 && $value <= 255,
            ParamType::Count64 => is_int($value) && $value >= 0,
            ParamType::TextList => is_array($value) && array_is_list($value) && $value !== []
                && array_filter($value, fn ($v) => ! is_string($v) || ! mb_check_encoding($v, 'UTF-8')) === [],
            ParamType::Instant => $value instanceof DateTimeInterface,
        };

        if (! $fits) {
            throw new InvalidArgumentException("Parameter '{$name}' does not fit {$type->value}");
        }
    }
}
