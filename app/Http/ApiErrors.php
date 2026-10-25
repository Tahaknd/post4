<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Fixed JSON for exceptions on `/api/*`. The framework's own JSON puts the exception message into `message`, and
 * for some exceptions that message names a model and an id ("No query results for model [App\Models\Project] 5"),
 * the HTTP method and route ("The POST method is not supported for route ..."), or other detail a client should not
 * learn. Here the body is a sentence chosen by status, never exception text, so a project the user cannot open
 * answers exactly like one that does not exist.
 *
 * Validation answers (422) are left alone: their messages are fixed sentences of the form request, and the
 * `errors` map is the contract. Retry-After and Allow headers are carried over. Registered through
 * `$exceptions->respond()` in bootstrap/app.php, ahead of {@see ErrorPages} (which skips /api/*).
 */
final class ApiErrors
{
    /** @var array<int, string> */
    private const array MESSAGES = [
        400 => 'The request could not be understood.',
        401 => 'Unauthenticated.',
        403 => 'This action is not allowed.',
        404 => 'Not found.',
        405 => 'This method is not allowed here.',
        413 => 'The request body is too large.',
        415 => 'Send the body as JSON (Content-Type: application/json).',
        419 => 'The page expired. Reload and try again.',
        429 => 'Too many requests. Try again shortly.',
        500 => 'Something went wrong on our side. Try again shortly.',
        503 => 'The service is temporarily unavailable. Try again shortly.',
    ];

    /** Statuses whose JSON carries a machine readable `error.kind`, like the endpoint's own failures. */
    private const array KINDS = [429 => 'rate_limited', 503 => 'unavailable'];

    public static function isApiRequest(Request $request): bool
    {
        return $request->is('api/*');
    }

    public static function respond(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! self::isApiRequest($request) || $status < 400 || $status === 422) {
            return $response;
        }

        $message = self::MESSAGES[$status] ?? ($status >= 500 ? self::MESSAGES[500] : 'The request could not be processed.');
        $body = ['message' => $message];
        if (isset(self::KINDS[$status])) {
            $retryAfter = $response->headers->get('Retry-After');
            $body['error'] = [
                'kind' => self::KINDS[$status],
                'message' => $message,
                'retryable' => true,
                'retry_after_seconds' => $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : null,
            ];
        }

        $json = new JsonResponse($body, $status);
        foreach (['Retry-After', 'Allow'] as $header) {
            if ($response->headers->has($header)) {
                $json->headers->set($header, (string) $response->headers->get($header));
            }
        }

        return $json;
    }
}
