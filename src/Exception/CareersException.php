<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers\Exception;

/**
 * A failed call to Careers Orbit. Every other SDK exception extends it.
 */
class CareersException extends \RuntimeException
{
    /**
     * @param int $status The HTTP status, such as 422
     * @param string $errorCode The API's error code, such as "invalid"
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
