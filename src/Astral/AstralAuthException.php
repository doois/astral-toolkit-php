<?php

declare(strict_types=1);

namespace Astral;

/** 401 or 403: a missing, invalid or expired key, or a key without the needed capability. */
class AstralAuthException extends AstralHttpException
{
}
