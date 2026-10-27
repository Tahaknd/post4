<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The JSON shape of EVERY error on `/api/*`, and fixed sentences instead of exception text.
 *
 * Shape (same for every status, so a client needs one parser): `message` at the top level (what a generic handler
 * reads) and `error: {kind, message, retryable, retry_after_seconds}` (what a client acts on). A validation answer
 * (422) keeps its `errors` map as well.
 *
 * Why not the framework's JSON: its `message` is the exception message, and for some exceptions that names a model
 * and an id ("No query results for model [App\Models\Project] 5"), the HTTP method and route ("The POST method is not
 * supported for route ..."), or other detail a client should not learn. Here the body is a sentence chosen by
 * status, never exception text, so a project the user cannot open answers exactly like one that does not exist.
 * Only a ValidationException keeps its text (fixed sentences of the form request). `abort(422)` is masked like the
 * rest. Retry-After and Allow headers are carried over. A 5xx is left to the framework while APP_DEBUG is on (the
 * developer's page), consistent with {@see ErrorPages}.
 *
 * Registered through `$exceptions->respond()` in bootstrap/app.php, ahead of {@see ErrorPages} (which skips /api/*).
 */
final class ApiErrors
{
    /** @var array<int, array{string, string, bool}> status => [kind, message, retryable] */
    private const array STATUSES = [
        400 => ['bad_request', 'The request could not be understood.', false],
        401 => ['unauthenticated', 'Unauthenticated.', false],
        403 => ['forbidden', 'This action is not allowed.', false],
        404 => ['not_found', 'Not found.', false],
        405 => ['method_not_allowed', 'This method is not allowed here.', false],
        409 => ['conflict', 'The request conflicts with the current state.', false],
        410 => ['gone', 'This resource is gone.', false],
        413 => ['payload_too_large', 'The request body is too large.', false],
        415 => ['unsupported_media_type', 'Send the body as JSON (Content-Type: application/json).', false],
        419 => ['page_expired', 'The page expired. Reload and try again.', false],
        422 => ['unprocessable', 'The request could not be processed.', false],
        423 => ['locked', 'This resource is locked.', false],
        429 => ['rate_limited', 'Too many requests. Try again shortly.', true],
        500 => ['server_error', 'Something went wrong on our side. Try again shortly.', false],
        503 => ['unavailable', 'The service is temporarily unavailable. Try again shortly.', true],
    ];

    /** `/api`, `/api/` and `/api/...` in any letter case (routes are case sensitive, so `/API/x` is simply not found, as JSON). */
    public static function isApiRequest(Request $request): bool
    {
        return preg_match('#\Aapi(/|\z)#i', $request->path()) === 1;
    }

    /**
     * The one request that carries the user's definition: strings must reach the domain exactly as sent, so the
     * framework's TrimStrings and ConvertEmptyStringsToNull are skipped for it only (every other API endpoint trims
     * and null-checks what it needs by hand).
     */
    public static function keepsStringsExact(Request $request): bool
    {
        return $request->isMethod('POST') && preg_match('#\Aapi/projects/[0-9]{1,18}/insights/trends\z#', $request->path()) === 1;
    }

    /**
     * @return array{message: string, error: array{kind: string, message: string, retryable: bool, retry_after_seconds: ?int}}
     */
    public static function body(string $kind, string $message, bool $retryable = false, ?int $retryAfterSeconds = null): array
    {
        return [
            'message' => $message,
            'error' => ['kind' => $kind, 'message' => $message, 'retryable' => $retryable, 'retry_after_seconds' => $retryAfterSeconds],
        ];
    }

    public static function respond(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! self::isApiRequest($request) || $status < 400) {
            return $response;
        }

        if ($exception instanceof ValidationException) {
            $content = json_decode((string) $response->getContent(), true);
            $message = is_array($content) && is_string($content['message'] ?? null) ? $content['message'] : self::STATUSES[422][1];

            return new JsonResponse([...self::body('validation', $message), 'errors' => $exception->errors()], $status);
        }

        if ($status >= 500 && config('app.debug')) {
            return $response;
        }

        [$kind, $message, $retryable] = self::STATUSES[$status] ?? ($status >= 500 ? self::STATUSES[500] : ['client_error', 'The request could not be processed.', false]);
        $retryAfter = $response->headers->get('Retry-After');

        $json = new JsonResponse(self::body($kind, $message, $retryable, $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : null), $status);
        foreach (['Retry-After', 'Allow'] as $header) {
            if ($response->headers->has($header)) {
                $json->headers->set($header, (string) $response->headers->get($header));
            }
        }

        return $json;
    }
}
