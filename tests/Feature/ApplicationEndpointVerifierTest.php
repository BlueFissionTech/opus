<?php

declare(strict_types=1);

namespace Tests\Feature;

use InvalidArgumentException;
use Opus\Tools\ApplicationEndpointVerifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tools/ApplicationEndpointVerifier.php';

final class ApplicationEndpointVerifierTest extends TestCase
{
    private mixed $server = null;
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/opus-endpoint-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        // Only remove this test's newly created fixture tree; never follow links.
        $temporaryRoot = realpath(sys_get_temp_dir());
        $fixtureRoot = realpath($this->directory);
        if ($temporaryRoot === false || $fixtureRoot === false
            || $fixtureRoot !== $temporaryRoot . DIRECTORY_SEPARATOR . basename($this->directory)) {
            throw new \RuntimeException('Fixture cleanup escaped its intended temporary directory.');
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $fixtureRoot, \FilesystemIterator::SKIP_DOTS
        ), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testQualifiesEveryPublishedApiEndpointThroughNativeTransport(): void
    {
        $result = (new ApplicationEndpointVerifier())->verify($this->start('good'), 'api');
        self::assertTrue($result['ok']);
        self::assertSame('public_endpoints', $result['scope']);
        self::assertSame(['health', 'ready', 'profile'], array_column($result['checks'], 'name'));
        self::assertSame([200, 200, 200], array_column($result['checks'], 'http_status'));
        self::assertSame(['/health', '/ready', '/api/info'], $this->requests());
    }

    /** @dataProvider failedResponses */
    public function testHttpSuccessAloneCannotQualifyAnApplication(string $mode, string $reason, int $requests): void
    {
        $result = (new ApplicationEndpointVerifier())->verify($this->start($mode));
        self::assertFalse($result['ok']);
        self::assertSame($reason, $result['checks'][count($result['checks']) - 1]['reason']);
        self::assertCount($requests, $this->requests());
        self::assertStringNotContainsString('private-fixture', json_encode($result));
    }

    public static function failedResponses(): array
    {
        return [
            ['bad_status', 'http_status_unexpected', 1],
            ['redirect', 'http_status_unexpected', 1],
            ['html', 'content_type_invalid', 1],
            ['bad_json', 'response_invalid', 1],
            ['bad_contract', 'response_mismatch', 1],
            ['oversize', 'response_too_large', 1],
            ['not_ready', 'http_status_unexpected', 2],
            ['wrong_profile', 'response_mismatch', 3],
        ];
    }

    /** @dataProvider cliResponses */
    public function testCliExitStatusRequiresTheCompletePublishedContract(string $mode, string $profile, bool $ok): void
    {
        $origin = $this->start($mode);
        // Mirror the approved runtime's extension directory without inheriting
        // unrelated php.ini startup output. Statically built extensions need no
        // load flag; shared extensions use the platform's normal file name.
        $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir')];
        foreach (['mbstring', 'curl'] as $extension) {
            $filename = (PHP_SHLIB_SUFFIX === 'dll' ? 'php_' : '') . $extension . '.' . PHP_SHLIB_SUFFIX;
            if (is_file(ini_get('extension_dir') . '/' . $filename)) {
                $command[] = '-d';
                $command[] = 'extension=' . $filename;
            }
        }
        $root = dirname(__DIR__, 2);
        $command = array_merge($command, [$root . '/bin/verify-application.php', $origin, $profile]);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $root, array_merge(getenv(), ['OPUS_HOST_ROOT' => $root]));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame($ok ? 0 : 1, proc_close($process), $output);
        self::assertSame('', $errors);
        $receipt = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($ok, $receipt['ok']);
        self::assertSame($profile, $receipt['profile']);
        self::assertSame('public_endpoints', $receipt['scope']);
        self::assertSame($mode === 'not_ready' ? ['/health', '/ready'] : ['/health', '/ready', '/api/info'],
            $this->requests());
        self::assertStringNotContainsString($origin, $output);
    }

    public static function cliResponses(): array
    {
        return [
            ['good', 'api', true],
            ['dashboard', 'dashboard', true],
            ['wrong_profile', 'api', false],
            ['not_ready', 'api', false],
        ];
    }

    public function testConnectionFailureIsRedactedAndDoesNotRetry(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('The native DevElation transport requires ext-curl.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $result = (new ApplicationEndpointVerifier())->verify('http://' . $address);
        self::assertFalse($result['ok']);
        self::assertCount(1, $result['checks']);
        self::assertSame('transport_unavailable', $result['checks'][0]['reason']);
        self::assertStringNotContainsString($address, json_encode($result));
    }

    /** @dataProvider invalidOrigins */
    public function testRejectsCredentialsAndNonOriginTargetsBeforeNetworkAccess(string $origin): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ApplicationEndpointVerifier())->verify($origin);
    }

    public static function invalidOrigins(): array
    {
        return array_map(static fn (string $url): array => [$url], [
            'file:///tmp/health', 'ftp://localhost', 'http://user:private-fixture@localhost',
            'http://localhost/private-fixture', 'http://localhost?token=private-fixture',
            'http://localhost#private-fixture', "http://localhost\n", 'localhost',
        ]);
    }

    public function testDoesNotClaimFullPlatformQualification(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ApplicationEndpointVerifier())->verify('http://localhost', 'platform');
    }

    public function testMissingHostAutoloaderProducesOnlyARedactedCliFailure(): void
    {
        // An empty OPUS_HOST_ROOT does not remove the package's own autoloader:
        // the native resolver deliberately falls back to it. Isolate the real
        // CLI/bootstrap/resolver files without installing any vendor tree.
        foreach (['bin/verify-application.php', 'common/bootstrap/runtime.php',
            'app/Business/Services/RuntimePathResolver.php'] as $relative) {
            $target = $this->directory . '/' . $relative;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            self::assertTrue(copy(dirname(__DIR__, 2) . '/' . $relative, $target));
        }
        $environment = array_merge(getenv(), ['OPUS_HOST_ROOT' => $this->directory]);
        $process = proc_open([PHP_BINARY, '-n', $this->directory . '/bin/verify-application.php',
            'http://localhost', 'api'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $this->directory, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $errors);
        self::assertSame(['version' => 1, 'ok' => false, 'reason' => 'probe_unavailable'], json_decode($output, true));
    }

    private function start(string $mode): string
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('The native DevElation transport requires ext-curl.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $router = __DIR__ . '/../Fixtures/application-endpoint-router.php';
        $environment = array_merge(getenv(), ['OPUS_FIXTURE_MODE' => $mode,
            'OPUS_FIXTURE_REQUESTS' => $this->directory . '/requests']);
        $this->server = proc_open([PHP_BINARY, '-n', '-S', $address, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/stdout', 'a'],
                2 => ['file', $this->directory . '/stderr', 'a']], $pipes, null, $environment);
        self::assertIsResource($this->server);
        fclose($pipes[0]);
        // Match the existing profile HTTP suite's fixture-start allowance.
        // This does not change the verifier's request/connect timeouts.
        $deadline = microtime(true) + 10;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $code, $message, 0.1);
            if ($connection !== false) {
                fclose($connection);
                error_clear_last();
                return 'http://' . $address;
            }
            usleep(50000);
        } while (microtime(true) < $deadline && proc_get_status($this->server)['running']);
        self::fail('Synthetic HTTP fixture failed to start: '
            . file_get_contents($this->directory . '/stderr'));
    }

    private function requests(): array
    {
        return file($this->directory . '/requests', FILE_IGNORE_NEW_LINES);
    }
}
