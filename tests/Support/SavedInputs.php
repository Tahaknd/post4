<?php

namespace Tests\Support;

use App\Domain\Insights\InsightTypes;

/** Valid insight inputs (the bodies of `POST insights/{type}`) of every registered type, with a relative range by default. */
final class SavedInputs
{
    /** @param  array<string, mixed>  $override  keys replace the defaults; `null` removes a key
     * @return array<string, mixed> */
    public static function for(string $type, array $override = []): array
    {
        $base = match ($type) {
            InsightTypes::TRENDS => ['events' => ['signup'], 'date_preset' => 'last_7_days'],
            InsightTypes::FUNNEL => ['steps' => [['event' => 'signup'], ['event' => 'purchase']], 'date_preset' => 'last_7_days'],
            InsightTypes::RETENTION => ['target' => ['event' => 'signup'], 'returning' => ['event' => 'visit'], 'date_preset' => 'last_7_days', 'periods' => 3],
        };

        $merged = [...$base, ...$override];

        return array_filter($merged, fn (mixed $value) => $value !== null);
    }
}
