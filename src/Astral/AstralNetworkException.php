<?php

declare(strict_types=1);

namespace Astral;

/** The request never got an HTTP answer (DNS, refused connection, timeout). */
class AstralNetworkException extends AstralException
{
    public function __construct(public readonly string $url, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct("Network error on {$url}: {$reason}", 0, $previous);
    }
}
