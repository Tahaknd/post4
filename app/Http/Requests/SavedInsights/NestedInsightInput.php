<?php

namespace App\Http\Requests\SavedInsights;

use App\Domain\Insights\InsightTypes;
use App\Http\Requests\Insights\FunnelInsightRequest;
use App\Http\Requests\Insights\InsightRequest;
use App\Http\Requests\Insights\RetentionInsightRequest;
use App\Http\Requests\Insights\TrendsInsightRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Validates the `query` of a saved insight with the request class of its type: the very rules and the very sentences of
 * `POST insights/{type}`, so a definition that the insight API refuses can never be saved, and the two cannot drift apart.
 * The nested body is validated as if it had been POSTed to that endpoint (same project, same user, same clock); its errors
 * come back under `query.` (`query.events.0`).
 */
final class NestedInsightInput
{
    /** @var array<string, class-string<InsightRequest>> */
    public const array REQUESTS = [
        InsightTypes::TRENDS => TrendsInsightRequest::class,
        InsightTypes::FUNNEL => FunnelInsightRequest::class,
        InsightTypes::RETENTION => RetentionInsightRequest::class,
    ];

    private function __construct() {}

    /**
     * @param  object|array<array-key, mixed>  $query  the `query` object as decoded from the body (objects kept as objects, so `{}` stays `{}`)
     * @return array<string, mixed> the validated input, ready for the actions
     *
     * @throws ValidationException
     */
    public static function validate(FormRequest $outer, string $type, object|array $query): array
    {
        $class = self::REQUESTS[$type] ?? throw new InvalidArgumentException("No request class for insight type '{$type}'");

        $nested = $class::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($query, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $nested->setContainer(app())->setRedirector(app('redirect'));
        $nested->setUserResolver($outer->getUserResolver());
        $nested->setRouteResolver($outer->getRouteResolver());

        try {
            $nested->validateResolved();
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors['query.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        return $nested->validated();
    }
}
