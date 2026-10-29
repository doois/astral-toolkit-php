<?php

declare(strict_types=1);

namespace Astral;

/**
 * Every failure the client raises is one of these, so a site can tell a missing
 * entry (render a 404) from a bad key (fail loudly) without parsing messages.
 */
class AstralException extends \RuntimeException
{
}
