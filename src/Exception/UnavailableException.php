<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers\Exception;

/**
 * Careers Orbit could not be reached, did not answer in time, or failed on its side (5xx), after every retry.
 */
final class UnavailableException extends CareersException
{
    public function __construct(string $message, int $status = 503, ?\Throwable $previous = null)
    {
        parent::__construct($status, 'unavailable', $message, $previous);
    }
}
