<?php

declare(strict_types=1);

namespace App\Business\Http\Api;

use BlueFission\BlueCore\Auth as Authenticator;
use BlueFission\Connections\Database\MySQLLink;
use BlueFission\Flag;
use BlueFission\Services\Request;
use BlueFission\Services\Service;
use BlueFission\Val;

class AuthenticationController extends Service
{
    public function __construct(MySQLLink $link)
    {
        parent::__construct();
        $link->open();
    }

    public function login(Request $request, Authenticator $auth): mixed
    {
        if ($request->login === false) {
            return null;
        }

        $sessionEstablished = Flag::make(false);

        if ($auth->isAuthenticated()) {
            $sessionEstablished->val($auth->setSession());

            return response($this->loginResponse($auth->status(), $sessionEstablished));
        }

        if ($request->remember) {
            $auth->config('duration', 3600 * 24 * 7 * 4);
        }

        if ($auth->authenticate($request->username, $request->password)) {
            $sessionEstablished->val($auth->setSession());
        }

        return response($this->loginResponse($auth->status(), $sessionEstablished));
    }

    private function loginResponse(mixed $status, Flag $sessionEstablished): array
    {
        return [
            'status' => $status,
            'data' => $sessionEstablished->isTruthy() && Val::isEmpty($status),
        ];
    }

    public function logout(Request $request, Authenticator $auth): mixed
    {
        if ($request->logout === false) {
            return null;
        }

        $auth->destroySession();

        return header('location: ' . ROOT_URL);
    }
}
