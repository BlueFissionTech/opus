<?php

declare(strict_types=1);

namespace App\Business\Presentation;

use BlueFission\Net\HTTP;

final class AuthenticationFailureResponse
{
    public function send(): void
    {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo HTTP::jsonEncode(['ok' => false, 'error' => 'authentication_required']);
    }
}
