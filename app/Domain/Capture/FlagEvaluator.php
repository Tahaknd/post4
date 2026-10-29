<?php

namespace App\Domain\Capture;

/**
 * Decides which feature flags are on for a request. SdkStubController asks it after the token is resolved and presents
 * the answer in the `/flags` and `/decide` shapes.
 *
 * Milestone 7 plug-in point: implement this interface with the real evaluation (flag definitions of the project,
 * person and group properties, rollout, variants, payloads), bind it in AppServiceProvider instead of NoFlagsEvaluator,
 * and set `hasFeatureFlags` of SdkStubs::remoteConfig() from whether the project has flags. Nothing else in the
 * request path has to change; the controller and class names (still `SdkStub*`) can be renamed then.
 */
interface FlagEvaluator
{
    public function evaluate(FlagRequest $request): FlagEvaluation;
}
