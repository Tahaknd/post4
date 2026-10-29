<?php

namespace App\Domain\Capture;

/**
 * The outcome of evaluating the flags of one request: what `/flags` and `/decide` present, in the shapes posthog-js
 * reads (see SdkStubs). Maps are PHP arrays here and are encoded as JSON objects by the presenters, empty ones too.
 */
final readonly class FlagEvaluation
{
    /**
     * @param  array<string, array<string, mixed>>  $flags  flag key => detail (`enabled`, `variant`, `reason`, `metadata`...)
     * @param  array<string, bool|string>  $featureFlags  flag key => value (`true` or the variant), the legacy view of $flags
     * @param  array<string, mixed>  $featureFlagPayloads  flag key => payload
     * @param  list<string>  $quotaLimited
     */
    public function __construct(
        public array $flags,
        public array $featureFlags,
        public array $featureFlagPayloads,
        public bool $errorsWhileComputingFlags,
        public array $quotaLimited,
        public string $requestId,
    ) {}
}
