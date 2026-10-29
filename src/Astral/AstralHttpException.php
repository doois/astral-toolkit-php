<?php

declare(strict_types=1);

namespace Astral;

/** The API answered with an error status. `code` is the API's error code when it sent one. */
class AstralHttpException extends AstralException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $url,
        public readonly mixed $body,
    ) {
        parent::__construct("Astral API {$status} ({$errorCode}) on {$url}", $status);
    }

    /** Reads the API's `{ "error": "code" }` body and picks the exception class for a status. */
    public static function fromResponse(int $status, string $url, mixed $body): self
    {
        $code = is_array($body) && isset($body['error']) && is_string($body['error']) ? $body['error'] : "http_{$status}";
        return match (true) {
            $status === 404 => new AstralNotFoundException($status, $code, $url, $body),
            $status === 401, $status === 403 => new AstralAuthException($status, $code, $url, $body),
            default => new self($status, $code, $url, $body),
        };
    }
}
