<?php
declare(strict_types=1);
namespace AddOns\Keryx\Tests;

use AddOns\Keryx\Business\Gateway;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GatewayTransportTest extends TestCase
{
    private mixed $server = null;
    private string $directory;

    protected function setUp(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('The native DevElation transport requires ext-curl.');
        }
        $this->directory = sys_get_temp_dir() . '/opus-keryx-wire-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
    }

    public function testDefaultNativeTransportPreservesBytesAndMakesOnlyTheAdmittedGet(): void
    {
        $gateway = new Gateway($this->start('good'), developmentHttp: true);
        $result = $gateway->fetch('/v1/messages/summary');
        self::assertSame(200, $result['status']);
        self::assertSame('{"data":[{"repoName":"sample","severity":"info","countTotal":3,"countUnread":2}]}', $result['body']);
        self::assertSame([[
            'method' => 'GET', 'path' => '/v1/messages/summary',
            'accept' => 'application/json', 'body' => '',
        ]], $this->requests());
    }

    /** @dataProvider completedResponses */
    public function testObservedStatusIsNotSynthesizedAndRedirectsAreNotFollowed(string $mode, int $status, string $body): void
    {
        $gateway = new Gateway($this->start($mode), developmentHttp: true);
        $result = $gateway->fetch('/v1/capabilities');
        self::assertSame($status, $result['status']);
        self::assertSame($body, $result['body']);
        self::assertCount(1, $this->requests());
        self::assertSame('/v1/capabilities', $this->requests()[0]['path']);
    }

    public static function completedResponses(): array
    {
        return [
            ['redirect', 302, '{"redirect":true}'],
            ['error', 503, '{"unavailable":true}'],
            ['empty', 200, ''],
        ];
    }

    /** @dataProvider incompleteResponses */
    public function testIncompleteWireResponsesFailClosedWithoutRetries(string $mode): void
    {
        $gateway = new Gateway($this->start($mode), developmentHttp: true);
        try {
            $gateway->fetch('/v1/messages/summary');
            self::fail('An incomplete transport response was accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame('keryx_transport_incomplete', $exception->getMessage());
        }
        self::assertCount(1, $this->requests());
    }

    public static function incompleteResponses(): array
    {
        return [['oversized'], ['interrupted'], ['timeout']];
    }

    public function testConnectionFailureDoesNotMasqueradeAsAnEmptySuccessfulResponse(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('keryx_transport_incomplete');
        (new Gateway('http://' . $address, developmentHttp: true))->fetch('/v1/capabilities');
    }

    private function start(string $mode): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $environment = array_merge(getenv(), [
            'KERYX_FIXTURE_MODE' => $mode,
            'KERYX_FIXTURE_REQUESTS' => $this->directory . '/requests',
        ]);
        $this->server = proc_open([PHP_BINARY, '-n', '-S', $address, __DIR__ . '/Fixtures/gateway-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/stdout', 'a'],
                2 => ['file', $this->directory . '/stderr', 'a']], $pipes, null, $environment);
        self::assertIsResource($this->server);
        fclose($pipes[0]);
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
        self::fail('Synthetic HTTP fixture failed to start.');
    }

    private function requests(): array
    {
        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->directory . '/requests', FILE_IGNORE_NEW_LINES));
    }
}
