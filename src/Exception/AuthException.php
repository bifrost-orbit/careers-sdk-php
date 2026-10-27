<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers\Exception;

/**
 * The key is missing, wrong, revoked or expired (401).
 */
final class AuthException extends CareersException
{
    public function __construct(string $message, string $errorCode = 'unauthorized', ?\Throwable $previous = null)
    {
        parent::__construct(401, $errorCode, $message, $previous);
    }
}
