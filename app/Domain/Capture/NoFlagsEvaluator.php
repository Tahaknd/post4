<?php

namespace App\Domain\Capture;

use Illuminate\Support\Str;

/** The default evaluator until Milestone 7: no flag exists, for any project. Every answer has a fresh request id. */
final class NoFlagsEvaluator implements FlagEvaluator
{
    public function evaluate(FlagRequest $request): FlagEvaluation
    {
        return new FlagEvaluation([], [], [], false, [], (string) Str::uuid());
    }
}
