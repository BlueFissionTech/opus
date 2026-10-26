<?php

declare(strict_types=1);

namespace App\Business\Gateways;

use App\Domain\User\AuthenticationRequired;
use BlueFission\BlueCore\Auth;
use BlueFission\Services\Request;
use Closure;
use Throwable;

class AuthenticationGateway extends \BlueFission\BlueCore\Gateway\AuthenticationGateway
{
    private ?Closure $authResolver;

    public function __construct(?callable $authResolver = null)
    {
        parent::__construct();
        $this->authResolver = $authResolver === null ? null : Closure::fromCallable($authResolver);
    }

    public function process(Request $request, &$arguments): void
    {
        $auth = null;
        try {
            // Only the registering host can supply an authenticator owner.
            $resolved = $this->authResolver === null ? null : ($this->authResolver)();
            $auth = $resolved instanceof Auth ? $resolved : null;
            if ($auth === null) {
                throw new AuthenticationRequired();
            }
            if ($auth->isAuthenticated() === true && $auth->setSession() === true) {
                return;
            }
        } catch (Throwable) {
            // Failed identity/session reads must never fall through to a controller.
        }

        try {
            $auth?->destroySession();
        } catch (Throwable) {
            // Cleanup failure cannot turn a denial into permission to dispatch.
        }

        throw new AuthenticationRequired();
    }
}
