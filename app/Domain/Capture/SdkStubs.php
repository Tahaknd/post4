<?php

namespace App\Domain\Capture;

/**
 * The answers of the posthog-js SDK endpoints that this server only partly implements: remote config
 * (`/array/{token}/config`, `config.js`) and feature flags (`/flags`, `/decide`). The config is a STUB: every project
 * gets the same "nothing enabled" answer. The flag answers are PRESENTERS: they only shape a FlagEvaluation, which
 * comes from the bound FlagEvaluator (NoFlagsEvaluator until Milestone 7, see FlagEvaluator for the plug-in point).
 *
 * Verified against posthog-js 1.438.24 (@posthog/browser-common 0.9.12), the version resources/js pins:
 *  - RemoteConfigLoader asks for `/array/{token}/config.js` (a script tag, `crossorigin=anonymous`) when the bundle has
 *    the external script loader, reads `window._POSTHOG_REMOTE_CONFIG[token].config` and `.siteApps`, and falls back
 *    to `GET /array/{token}/config` (JSON) when the script is missing or the loader is disabled.
 *  - `/flags/?v=2` is a POST (gzip text/plain, or JSON) whose body carries `token`; the SDK reads `flags`,
 *    `errorsWhileComputingFlags`, `quotaLimited`, `requestId`, `evaluatedAt`. `/decide` is what SDKs before the
 *    split call; they read `featureFlags` and `featureFlagPayloads` (or `flags`).
 *
 * Every key below says what turns it off, because anything left on makes the SDK fetch a script from
 * `/static/...` (session recorder, surveys, toolbar, web vitals, exception autocapture, dead clicks) or open `/s/`,
 * and this server serves neither.
 */
final class SdkStubs
{
    /**
     * @return array<string, mixed>
     */
    public static function remoteConfig(int $projectId, string $token): array
    {
        // $projectId is unused until Milestone 7 makes `hasFeatureFlags` (and the product switches) depend on the project.
        return [
            'token' => $token,
            // `gzip-js`: the capture endpoints gunzip bodies (PayloadDecoder). Without this key the SDK sends events uncompressed.
            'supportedCompression' => ['gzip-js'],
            // `false` stops the SDK from calling `/flags` after the config arrived (RemoteConfigLoader).
            'hasFeatureFlags' => false,
            // Each of the following disables a lazily loaded extension.
            'sessionRecording' => false,
            'surveys' => false,
            'heatmaps' => false,
            'captureDeadClicks' => false,
            'capturePerformance' => false,
            'autocaptureExceptions' => false,
            'productTours' => false,
            'conversations' => false,
            // Autocapture itself stays on (the demo page and typical sites want clicks): `false` is the server side opt-out value.
            'autocapture_opt_out' => false,
            // PostHog's default for new projects; the pinned SDK takes `person_profiles` from its own options and ignores it.
            'defaultIdentifiedOnly' => true,
            'siteApps' => [],
        ];
    }

    /** The script variant: what `window._POSTHOG_REMOTE_CONFIG[token]` is read from. */
    public static function remoteConfigScript(int $projectId, string $token): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $key = json_encode($token, $flags);
        $value = json_encode(['config' => self::remoteConfig($projectId, $token), 'siteApps' => []], $flags);

        return "window._POSTHOG_REMOTE_CONFIG = window._POSTHOG_REMOTE_CONFIG || {};\nwindow._POSTHOG_REMOTE_CONFIG[{$key}] = {$value};\n";
    }

    /**
     * Answer of `/flags` (the v2 shape of posthog-js) for an evaluation. The maps are always JSON objects, also when empty.
     *
     * @return array<string, mixed>
     */
    public static function flags(FlagEvaluation $evaluation): array
    {
        return [
            'config' => ['enable_collect_everything' => true],
            'toolbarParams' => (object) [],
            'isAuthenticated' => false,
            'supportedCompression' => ['gzip-js'],
            'flags' => (object) $evaluation->flags,
            'errorsWhileComputingFlags' => $evaluation->errorsWhileComputingFlags,
            'quotaLimited' => array_values($evaluation->quotaLimited),
            'requestId' => $evaluation->requestId,
        ];
    }

    /**
     * Answer of the legacy `/decide`: the same evaluation, with the config keys older SDKs read from it.
     *
     * @return array<string, mixed>
     */
    public static function decide(FlagEvaluation $evaluation): array
    {
        return [
            'config' => ['enable_collect_everything' => true],
            'featureFlags' => (object) $evaluation->featureFlags,
            'featureFlagPayloads' => (object) $evaluation->featureFlagPayloads,
            'flags' => (object) $evaluation->flags,
            'errorsWhileComputingFlags' => $evaluation->errorsWhileComputingFlags,
            'quotaLimited' => array_values($evaluation->quotaLimited),
            'sessionRecording' => false,
            'surveys' => false,
            'heatmaps' => false,
            'capturePerformance' => false,
            'autocapture_opt_out' => false,
            'supportedCompression' => ['gzip-js'],
            'siteApps' => [],
        ];
    }

    private function __construct() {}
}
