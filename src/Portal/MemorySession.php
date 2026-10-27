<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers\Portal;

/**
 * Keeps the session token for the life of the object, such as one request.
 */
final class MemorySession implements SessionStore
{
    public function __construct(private ?string $token = null)
    {
    }

    public function get(): ?string
    {
        return $this->token;
    }

    public function set(?string $token): void
    {
        $this->token = $token;
    }
}
