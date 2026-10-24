<?php
declare(strict_types=1);
namespace AddOns\Keryx\Tests;

use AddOns\Keryx\Business\{Gateway, ReadService};
use App\Domain\Agents\{AgentApplicationScope, AgentRuntimeContext};
use App\Business\Services\AddOnContractValidator;
use BlueFission\Data\Storage\Storage;
use BlueFission\Services\Application;
use BlueFission\SimpleClients\Cloud\HttpClient;
use BlueFission\Wise\Cmd\CommandProcessor;
use PHPUnit\Framework\TestCase;

class ReadServiceTest extends TestCase
{
    private function context(string $tenant = 'tenant', array $caps = ['keryx.read'], array $active = ['keryx']): AgentRuntimeContext
    {
        return new AgentRuntimeContext($tenant, ['id' => 'operator'], $active,
            ['keryx' => 'active'], $caps, applicationScope: new AgentApplicationScope('app'));
    }

    private function directory(): array
    {
        return ['data' => [['id' => 42, 'canonicalName' => 'sample', 'routingStatus' => 'active',
            'freshness' => ['state' => 'current'], 'path' => '/private/path', 'description' => 'secret']]];
    }

    private function fixture(?callable $handler = null, ?\Closure $context = null): array
    {
        $handler ??= fn () => ['status' => 200, 'body' => json_encode($this->directory())];
        $client = new class($handler) extends HttpClient {
            public array $calls = [];
            public function __construct(private $handler) {}
            public function request(string $method, string $url, array $headers = [], $body = null): array {
                $this->calls[] = [$method, $url, $body];
                return ($this->handler)($url);
            }
        };
        $gateway = new Gateway('https://gateway.example', $client);
        $service = new ReadService($gateway, 'app', 'tenant', ['primary' => ['id' => 42, 'canonical' => 'sample']],
            $context ?? fn () => $this->context());
        return [$service, $client, $gateway];
    }

    public function testVerifiedDirectoryIsMinimizedAndNeverComplete(): void
    {
        [$service, $client] = $this->fixture();
        self::assertSame([], $client->calls);
        $result = $service->read('directory', 'primary');
        self::assertSame('verified', $result['status']);
        self::assertSame([['alias' => 'primary', 'id' => 42, 'canonical' => 'sample']], $result['data']);
        self::assertTrue($result['partial']);
        self::assertNull($result['next_page']);
        self::assertStringNotContainsString('private', json_encode($result));
        self::assertSame('GET', $client->calls[0][0]);
    }

    public function testDeniedAuthorityAndUnknownAliasNeverConnect(): void
    {
        foreach ([$this->context('other'), $this->context(caps: []), $this->context(active: [])] as $context) {
            [$service, $client] = $this->fixture(context: fn () => $context);
            self::assertSame('denied', $service->read('directory', 'primary')['status']);
            self::assertSame([], $client->calls);
        }
        [$service, $client] = $this->fixture();
        self::assertSame('denied', $service->read('directory', 'foreign')['status']);
        self::assertSame([], $client->calls);
    }

    public function testUnsupportedReadsPagesAndMutationsNeverConnect(): void
    {
        [$service, $client, $gateway] = $this->fixture();
        foreach (['job', 'discussions'] as $op) {
            self::assertSame('unavailable', $service->read($op, 'primary')['status']);
        }
        self::assertSame('denied', $service->read('send', 'primary')['status']);
        self::assertSame('unavailable', $service->read('directory', 'primary', 1)['status']);
        self::assertSame([], $client->calls);
        $this->expectException(\InvalidArgumentException::class);
        $gateway->fetch('/v1/jobs/1');
    }

    public function testTransportAndMalformedResponsesFailClosed(): void
    {
        foreach ([[0, ''], [200, ''], [200, '{}'], [200, 'null'], [200, '{'], [302, '{}'], [500, '{}'], [200, str_repeat('x', 1048577)]] as [$status, $body]) {
            [$service] = $this->fixture(fn () => ['status' => $status, 'body' => $body]);
            self::assertNotSame('verified', $service->read('directory', 'primary')['status']);
        }
        [$service] = $this->fixture(fn () => ['status' => 403, 'body' => 'private']);
        self::assertSame('denied', $service->read('directory', 'primary')['status']);
        [$service] = $this->fixture(fn () => throw new \RuntimeException('private-token'));
        $result = $service->read('directory', 'primary');
        self::assertSame('unavailable', $result['status']);
        self::assertStringNotContainsString('private', json_encode($result));
    }

    public function testIdentityMismatchDuplicateAndStaleFailClosed(): void
    {
        foreach (['mismatch', 'duplicate', 'stale'] as $case) {
            $data = $this->directory();
            if ($case === 'mismatch') { $data['data'][0]['id'] = 43; }
            if ($case === 'duplicate') { $data['data'][] = $data['data'][0]; }
            if ($case === 'stale') { $data['data'][0]['freshness']['state'] = 'stale'; }
            [$service] = $this->fixture(fn () => ['status' => 200, 'body' => json_encode($data)]);
            self::assertSame($case === 'stale' ? 'stale' : 'verification_failed', $service->read('directory', 'primary')['status']);
        }
    }

    public function testMessageSummaryFiltersForeignDataAndRejectsInvalidCounts(): void
    {
        $rows = [['repoName' => 'sample', 'severity' => 'info', 'countTotal' => 3, 'countUnread' => 2, 'token' => 'secret'],
            ['repoName' => 'foreign', 'severity' => 'error', 'countTotal' => 99, 'countUnread' => 99]];
        $handler = fn ($url) => ['status' => 200, 'body' => json_encode(str_contains($url, '/messages/') ? ['data' => $rows] : $this->directory())];
        [$service, $client] = $this->fixture($handler);
        self::assertSame([['severity' => 'info', 'total' => 3, 'unread' => 2]], $service->read('messages', 'primary')['data']);
        self::assertCount(2, $client->calls);
        $rows[0]['countUnread'] = 4;
        [$service] = $this->fixture(fn ($url) => ['status' => 200, 'body' => json_encode(str_contains($url, '/messages/') ? ['data' => $rows] : $this->directory())]);
        self::assertSame('verification_failed', $service->read('messages', 'primary')['status']);
    }

    public function testRevocationDuringReadDiscardsData(): void
    {
        $current = $this->context();
        [$service] = $this->fixture(function () use (&$current) {
            $current = $this->context(caps: []);
            return ['status' => 200, 'body' => json_encode($this->directory())];
        }, function () use (&$current) { return $current; });
        self::assertSame('denied', $service->read('directory', 'primary')['status']);
    }

    public function testPassivePackageValidation(): void
    {
        $package = dirname(__DIR__);
        $validation = (new AddOnContractValidator())->validate($package);
        self::assertTrue($validation['valid'], json_encode($validation['errors']));
        $factory = require $package . '/main.php';
        self::assertIsArray($factory()->contributions());
    }

    public function testCatalogNeverAdvertisesPrivilegedNativeCommands(): void
    {
        $catalog = ['version' => 1, 'capabilities' => [
            ['name' => 'repos.discover', 'mutating' => true, 'endpoints' => ['GET /v1/repos/directory', 'POST /v1/repos/rescan']],
            ['name' => 'messages.route', 'mutating' => true, 'endpoints' => ['GET /v1/messages/summary', 'POST /v1/messages']],
            ['name' => 'host.execute', 'endpoints' => ['POST /v1/host'], 'command' => 'private-secret'],
        ]];
        [$service] = $this->fixture(fn ($url) => ['status' => 200, 'body' => json_encode(str_ends_with($url, '/capabilities') ? $catalog : $this->directory())]);
        $result = $service->read('capabilities', 'primary');
        self::assertSame('verified', $result['status']);
        self::assertSame(['directory', 'messages', 'capabilities'], $result['data']['operations']);
        self::assertSame([], $result['data']['mutations']);
        self::assertStringNotContainsString('private', json_encode($result));
        self::assertStringNotContainsString('POST', json_encode($result));
    }

    public function testGatewayRejectsUnreviewedOriginsWithoutNetwork(): void
    {
        foreach (['http://gateway.example', 'https://user:pass@example.com', 'https://example.com/path', 'file:///etc/passwd', 'https://example.com?token=secret'] as $origin) {
            try { new Gateway($origin); self::fail('Unsafe origin admitted'); }
            catch (\InvalidArgumentException $error) { self::assertSame('keryx_origin_invalid', $error->getMessage()); }
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNativeWiseDispatchRechecksAuthority(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        $current = $this->context();
        [$unused, $client, $gateway] = $this->fixture();
        $app = Application::instance();
        $app->bindArgs(['gateway' => $gateway, 'application' => 'app', 'tenant' => 'tenant',
            'repositories' => ['primary' => ['id' => 42, 'canonical' => 'sample']],
            'context' => function () use (&$current) { return $current; }], ReadService::class);
        $service = $app->resolve(ReadService::class);
        self::assertInstanceOf(ReadService::class, $service);
        $service->registerWith($app);
        $processor = new CommandProcessor($this->createMock(Storage::class));
        self::assertContains('get keryx', $processor->availableCommands());
        $result = $processor->process('get keryx "directory" "primary"');
        self::assertSame('verified', json_decode($result->output(), true)['status']);
        $current = $this->context(caps: []);
        $denied = $processor->process('get keryx "messages" "primary"');
        self::assertSame('denied', json_decode($denied->output(), true)['status']);
        self::assertCount(1, $client->calls);
    }
}
