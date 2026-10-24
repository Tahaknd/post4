<?php

namespace App\Infrastructure\ClickHouse;

use RuntimeException;
use Throwable;

/**
 * A failed ClickHouse request. `getMessage()` is `ClickHouse error: <server text>` truncated to 1000
 * characters, for LOGS ONLY: the server text echoes query fragments and rejected parameter values, so it must
 * never reach an API response or a page. Show {@see self::userMessage()} instead; {@see self::$kind} and
 * {@see self::isRetryable()} / {@see self::isBusy()} tell the caller how to react.
 *
 * The code comes from the `X-ClickHouse-Exception-Code` header when present, else from the START of the body
 * (`Code: 159. DB::Exception: ...` or `{"exception":"Code: 159. ..."}`): a `Code: N.` further inside the text
 * (an echoed parameter value) is never taken for the server's code.
 */
class ClickHouseException extends RuntimeException
{
    public const MESSAGE_LIMIT = 1000;

    public function __construct(
        string $message,
        public readonly ?int $errorCode,
        public readonly ClickHouseErrorKind $kind,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode ?? 0, $previous);
    }

    /** @param  string|null  $codeHeader  value of X-ClickHouse-Exception-Code, when the response had one */
    public static function fromResponse(string $body, ?int $httpStatus = null, ?string $codeHeader = null): self
    {
        $code = self::parseCode($body, $codeHeader);

        return new self('ClickHouse error: '.mb_substr($body, 0, self::MESSAGE_LIMIT), $code, ClickHouseErrorKind::fromCode($code), $httpStatus);
    }

    /** A failure that has no ClickHouse answer (unparseable body, no data key). */
    public static function unknown(string $detail): self
    {
        return new self('ClickHouse error: '.mb_substr($detail, 0, self::MESSAGE_LIMIT), null, ClickHouseErrorKind::Unknown);
    }

    public function userMessage(): string
    {
        return $this->kind->userMessage();
    }

    public function isRetryable(): bool
    {
        return $this->kind->isRetryable();
    }

    public function isBusy(): bool
    {
        return $this->kind->isBusy();
    }

    private static function parseCode(string $body, ?string $codeHeader): ?int
    {
        if ($codeHeader !== null && preg_match('/^\s*(\d{1,5})\s*$/D', $codeHeader, $m) === 1) {
            return (int) $m[1];
        }

        if (preg_match('/^\s*(?:\{.*?"exception"\s*:\s*")?Code:\s*(\d{1,5})\./Ds', $body, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
