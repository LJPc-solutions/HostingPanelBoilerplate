<?php

declare(strict_types=1);

namespace Panel\Api;

/**
 * The ONLY exception type API clients may throw. Message must be user-safe.
 */
final class ApiException extends \RuntimeException
{
}
