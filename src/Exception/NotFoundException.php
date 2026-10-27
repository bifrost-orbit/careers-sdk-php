<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers\Exception;

/**
 * No such job, or it is closed, scheduled or unpublished (404).
 */
final class NotFoundException extends CareersException
{
    public function __construct(string $message, string $errorCode = 'not_found', ?\Throwable $previous = null)
    {
        parent::__construct(404, $errorCode, $message, $previous);
    }
}
