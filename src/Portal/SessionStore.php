<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers\Portal;

/**
 * Keeps a candidate's portal session token between requests, such as in an httpOnly cookie or the PHP session.
 */
interface SessionStore
{
    public function get(): ?string;

    public function set(?string $token): void;
}
