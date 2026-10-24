<?php

namespace App\Domain\Insights;

use DateTimeInterface;

/** SQL text (class constants and placeholders only) and its bound parameters, ready for ProjectScopedReader. */
final readonly class BuiltQuery
{
    /** @param  array<string, string|int|list<string>|DateTimeInterface>  $params */
    public function __construct(public string $sql, public array $params) {}
}
