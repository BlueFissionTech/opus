<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class ApplicationProfileHttpTest extends TestCase
{
    private mixed $server = null;
    private string $host;
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->host = sys_get_temp_dir() . '/opus-profile-' . bin2hex(random_bytes(8));
        mkdir($this->host . '/sessions', 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->host,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->host);
    }

    public function testApiBootsWithoutDatabaseAndExcludesThePlatformRoutes(): void
    {
        $this->start('api');
        $this->assertJsonResponse('/health', 200, ['ok' => true, 'status' => 'live']);
        $this->assertJsonResponse('/ready', 200, ['ok' => true, 'status' => 'ready']);
        $this->assertJsonResponse('/api/info', 200, ['ok' => true, 'profile' => 'api']);
        $this->assertJsonResponse('/health', 200, ['ok' => true, 'status' => 'live']);
        foreach (['/dashboard', '/admin', '/api/admin/users', '/api/chat'] as $path) {
            $this->assertJsonResponse($path, 404, ['ok' => false, 'error' => 'route_not_found']);
        }
        $this->assertJsonResponse('/health?_method=post', 400, ['ok' => false, 'error' => 'method_override_not_allowed']);
        $override = $this->request('/health?value=1', 'Cookie: _method=post');
        $this->assertSame(400, $override['status']);
    }

    public function testDashboardUsesRealSessionAuthenticationAndPackageTemplate(): void
    {
        $this->start('dashboard');
        $this->assertJsonResponse('/health', 200, ['ok' => true, 'status' => 'live']);
        $this->assertJsonResponse('/ready', 503, ['ok' => false, 'status' => 'unavailable']);
        $this->assertJsonResponse('/dashboard', 401, ['ok' => false, 'error' => 'authentication_required']);
        $login = $this->request('/login');
        $this->assertSame(200, $login['status']);
        $this->assertStringContainsString('autocomplete="current-password"', $login['body']);
        $this->assertStringNotContainsString('{$', $login['body']);
        $deniedPost = $this->request('/login', '', 'POST', 'login=1&username=fixture&password=fixture');
        $this->assertSame(403, $deniedPost['status']);
        $this->assertSame(['ok' => false, 'error' => 'csrf_invalid'], json_decode($deniedPost['body'], true));

        // Synthetic server-owned session: exercise the actual Auth and Session classes.
        $sessionId = 'profile' . bin2hex(random_bytes(12));
        file_put_contents($this->host . '/sessions/sess_' . $sessionId,
            'login|' . serialize(json_encode(['id' => 'fixture-user', 'username' => 'fixture-user'])));
        $dashboard = $this->request('/dashboard', 'Cookie: PHPSESSID=' . $sessionId);
        $this->assertSame(200, $dashboard['status'], $dashboard['body']);
        $this->assertStringContainsString('Your authenticated application is available.', $dashboard['body']);
        $this->assertStringNotContainsString('{$', $dashboard['body']);
        $this->assertStringContainsString('no-store', $dashboard['headers']);
        preg_match('/name="_token" value="([^"]+)"/', $dashboard['body'], $token);
        $this->assertNotEmpty($token[1] ?? null);
        $logout = $this->request('/logout', 'Cookie: PHPSESSID=' . $sessionId, 'POST', '_token=' . urlencode($token[1]));
        $this->assertSame(303, $logout['status']);
        $revoked = $this->request('/dashboard', 'Cookie: PHPSESSID=' . $sessionId);
        $this->assertSame(401, $revoked['status']);
    }

    public static function unavailableProfiles(): array
    {
        return [['unknown', []], ['api', ['APP_KEY' => '']], ['api', ['OPUS_ADDONS' => '../escape']],
            ['api', ['OPUS_ADDONS' => 'uninstalled']]];
    }

    /** @dataProvider unavailableProfiles */
    public function testBadConfigurationAndUnavailableAddonsFailClosed(string $profile, array $environment): void
    {
        $this->start($profile, $environment);
        $this->assertJsonResponse('/health', 503, ['ok' => false, 'error' => 'application_unavailable']);
    }

    public function testAllConfigurationIsParsedBeforeAnyScriptExecutes(): void
    {
        mkdir($this->host . '/common/config', 0700, true);
        $marker = $this->host . '/executed';
        file_put_contents($this->host . '/common/config/a.php', '<?php file_put_contents(' . var_export($marker, true) . ', "bad"); return [];');
        file_put_contents($this->host . '/common/config/z.php', '<?php return [;');
        $this->start('api');
        $this->assertJsonResponse('/health', 503, ['ok' => false, 'error' => 'application_unavailable']);
        $this->assertFileDoesNotExist($marker);
    }

    public function testUnsafeHostRoutesRequireCsrfBeforeTheirEffect(): void
    {
        mkdir($this->host . '/mapping', 0700, true);
        $marker = $this->host . '/mutated';
        file_put_contents($this->host . '/mapping/custom.php', '<?php \\BlueFission\\Services\\Mapping::add("/feature", static function () { file_put_contents('
            . var_export($marker, true) . ', "bad"); return "mutated"; }, "fixture.feature", "put");');
        $this->start('api');
        $response = $this->request('/feature', '', 'PUT', 'value=changed');
        $this->assertSame(403, $response['status']);
        $this->assertSame(['ok' => false, 'error' => 'csrf_invalid'], json_decode($response['body'], true));
        $this->assertFileDoesNotExist($marker);
    }

    public function testBootstrapOutputCannotContaminateTheHealthResponse(): void
    {
        mkdir($this->host . '/mapping', 0700, true);
        file_put_contents($this->host . '/mapping/noisy.php', '<?php echo "private-bootstrap-detail";');
        $this->start('api');
        $this->assertJsonResponse('/health', 503, ['ok' => false, 'error' => 'application_unavailable']);
    }

    private function assertJsonResponse(string $path, int $status, array $body): void
    {
        $response = $this->request($path);
        $this->assertSame($status, $response['status'], $response['body']);
        $this->assertStringContainsString('application/json', $response['headers']);
        $this->assertStringContainsString('no-store', $response['headers']);
        $this->assertSame($body, json_decode($response['body'], true), $response['body']);
    }

    private function start(string $profile, array $overrides = []): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        $this->assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $root = dirname(__DIR__, 2);
        $environment = array_merge([
            'PATH' => getenv('PATH') ?: '', 'SystemRoot' => getenv('SystemRoot') ?: '',
            'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir(),
            'OPUS_HOST_ROOT' => $this->host, 'OPUS_PROFILE' => $profile, 'OPUS_ADDONS' => '',
            'APP_KEY' => base64_encode(random_bytes(32)), 'APP_NAME' => 'Profile fixture',
            'MYSQL_DB_HOST' => '127.0.0.1', 'MYSQL_DB_PORT' => '1',
            'MYSQL_DB_NAME' => 'unavailable', 'MYSQL_DB_USERNAME' => 'fixture', 'MYSQL_DB_PASSWORD' => '',
        ], $overrides);
        $this->server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $this->host . '/sessions',
            '-d', 'display_errors=0', '-S', $address, '-t', $root . '/public', $root . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->host . '/stdout.log', 'a'],
                2 => ['file', $this->host . '/stderr.log', 'a']], $pipes, $root, $environment);
        $this->assertIsResource($this->server);
        fclose($pipes[0]);
        $deadline = microtime(true) + 10;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $code, $message, 0.1);
            if ($connection !== false) {
                fclose($connection);
                error_clear_last();
                return;
            }
            if (!proc_get_status($this->server)['running']) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        $this->fail('Profile HTTP server did not start.');
    }

    private function request(string $path, string $header = '', string $method = 'GET', string $body = ''): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30,
            'follow_location' => 0, 'method' => $method, 'content' => $body,
            'header' => "Connection: close\r\nContent-Type: application/x-www-form-urlencoded\r\n" . $header]]);
        $body = file_get_contents($this->baseUrl . $path, false, $context);
        $this->assertNotFalse($body, (string) file_get_contents($this->host . '/stderr.log'));
        $headers = $http_response_header ?? [];
        preg_match('/^HTTP\/\S+ (\d+)/', $headers[0] ?? '', $matches);
        return ['status' => (int) ($matches[1] ?? 0), 'headers' => implode("\n", $headers), 'body' => $body];
    }
}
