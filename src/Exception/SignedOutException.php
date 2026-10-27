<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers\Exception;

/**
 * The candidate's portal session is missing or has expired (401). The session store has been cleared.
 */
final class SignedOutException extends CareersException
{
    public function __construct(string $message, string $errorCode = 'unauthorized', ?\Throwable $previous = null)
    {
        parent::__construct(401, $errorCode, $message, $previous);
    }
}
