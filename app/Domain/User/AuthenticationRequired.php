<?php

declare(strict_types=1);

namespace App\Domain\User;

use RuntimeException;

final class AuthenticationRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('authentication_required', 401);
    }
}
