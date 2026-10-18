<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Data\FileSystem;
use BlueFission\Arr;
use BlueFission\Net\HTTP;
use BlueFission\Services\Application;
use BlueFission\Services\Uri;
use BlueFission\Str;
use ErrorException;
use RuntimeException;
use Throwable;

final class ProfileBootstrap
{
    public function routeAllowed(Application $application): bool
    {
        if (Arr::hasKey($_GET, '_method') || Arr::hasKey($_POST, '_method') || Arr::hasKey($_COOKIE, '_method')) {
            $this->failure('method_override_not_allowed', 400);
            return false;
        }
        $method = Str::lower($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = new Uri();
        foreach (Arr::make($application->maps()[$method] ?? [])->keys() as $path) {
            if ($uri->match($path)) {
                return true;
            }
        }
        $this->failure('route_not_found', 404);
        return false;
    }

    public function csrfAllowed(): bool
    {
        if (Arr::has(['GET', 'HEAD', 'OPTIONS'], $_SERVER['REQUEST_METHOD'] ?? 'GET', true)) {
            return true;
        }
        $submitted = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $expected = store('_token');
        if (!Str::is($submitted) || !Str::is($expected) || Str::isEmpty($expected)
            || !hash_equals($expected, $submitted)) {
            $this->failure('csrf_invalid', 403);
            return false;
        }
        return true;
    }

    public function run(callable $application): void
    {
        $level = ob_get_level();
        ob_start();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            header('Cache-Control: no-store');
            ApplicationProfile::current();
            if (!Str::is(getenv('APP_KEY')) || Str::isEmpty(getenv('APP_KEY'))) {
                throw new RuntimeException('application_key_required');
            }
            $this->validateConfiguration(resolve_path('common/config/*.php'));
            $application();
            ob_end_flush();
        } catch (Throwable $failure) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            // Keep diagnostics useful without recording messages, arguments, or host paths.
            @error_log('Opus profile unavailable: ' . get_debug_type($failure) . ' at '
                . basename($failure->getFile()) . ':' . $failure->getLine());
            $this->failure('application_unavailable', 503);
        } finally {
            restore_error_handler();
        }
    }

    private function failure(string $code, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo HTTP::jsonEncode(['ok' => false, 'error' => $code]);
    }

    public function validateConfiguration(string $pattern): void
    {
        $files = glob($pattern);
        if ($files === false || $files === []) {
            throw new RuntimeException('application_configuration_missing');
        }
        foreach ($files as $file) {
            $source = FileSystem::fileContents($file);
            if (!Str::is($source)) {
                throw new RuntimeException('application_configuration_unreadable');
            }
            // Parse every PHP configuration script before any of them executes.
            token_get_all($source, TOKEN_PARSE);
        }
    }
}
