<?php

declare(strict_types=1);

namespace App\Business\Http;

use App\Business\Services\ApplicationProfile;
use BlueFission\BlueCore\Engine;
use BlueFission\BlueCore\Auth;
use BlueFission\Connections\Database\MySQLLink;
use BlueFission\Net\HTTP;
use BlueFission\Services\Service;
use Throwable;

final class ProfileController extends Service
{
    public function health(): string
    {
        return $this->json(['ok' => true, 'status' => 'live']);
    }

    public function info(): string
    {
        return $this->json(['ok' => true, 'profile' => ApplicationProfile::current()->name]);
    }

    public function ready(): string
    {
        $profile = ApplicationProfile::current();
        $ready = true;
        if ($profile->name === 'dashboard') {
            try {
                $link = Engine::makeInstance(MySQLLink::class);
                $link->open();
                $ready = MySQLLink::tableExists('users') && MySQLLink::tableExists('credentials')
                    && MySQLLink::tableExists('login_attempts');
            } catch (Throwable) {
                $ready = false;
            }
        }
        return $this->json(['ok' => $ready, 'status' => $ready ? 'ready' : 'unavailable'], $ready ? 200 : 503);
    }

    public function dashboard(): string
    {
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
        return instance('template')->render('profile', 'dashboard.vibe', [
            'appName' => env('APP_NAME', 'Opus'), 'csrfToken' => store('_token'),
        ]);
    }

    public function logout(Auth $auth): string
    {
        $auth->destroySession();
        header('Location: /login', true, 303);
        return '';
    }

    public function login(): string
    {
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
        return instance('template')->render('profile', 'login.vibe', [
            'appName' => env('APP_NAME', 'Opus'), 'csrfToken' => store('_token'),
        ]);
    }

    private function json(array $data, int $status = 200): string
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        return HTTP::jsonEncode($data);
    }
}
