<?php
declare(strict_types=1);
namespace AddOns\Versa\Tests;

use AddOns\Versa\Business\MessagingService;
use AddOns\Versa\Domain\{Channel, SendRequest, SendApproval};
use App\Domain\Agents\{AgentRuntimeContext, AgentApplicationScope};
use BlueFission\SimpleClients\Contracts\{ClientInterface, ClientConfig, ClientCapabilities, ClientRequest, ClientResponse};
use BlueFission\Services\Application;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MessagingServiceTest extends TestCase
{
    private function host(string $app = 'app', string $tenant = 'tenant', string $actor = 'operator', array $caps = ['versa.read', 'versa.send', 'versa.notify']): AgentRuntimeContext
    {
        return new AgentRuntimeContext($tenant, ['id' => $actor], ['versa'], ['versa' => 'active'], $caps,
            'request', new AgentApplicationScope($app));
    }

    private function client(?callable $dispatch = null): ClientInterface
    {
        return new class($dispatch) implements ClientInterface {
            public int $calls = 0;
            public ?ClientRequest $last = null;
            public function __construct(private mixed $dispatch) {}
            public function configure(ClientConfig $config): static { return $this; }
            public function capabilities(): ClientCapabilities { return new ClientCapabilities(['service' => 'notifications', 'actions' => ['send']]); }
            public function send(ClientRequest $request): ClientResponse {
                ++$this->calls; $this->last = $request;
                return $this->dispatch ? ($this->dispatch)($request) : ClientResponse::success(['private' => 'do not expose'], 202);
            }
        };
    }

    private function service(ClientInterface|callable|null $client, ?callable $context = null, ?callable $review = null, bool $notifications = false): MessagingService
    {
        return new MessagingService([new Channel('app', 'tenant', 'room', ['operator', 'other'], $notifications)],
            $context ?? fn () => $this->host(),
            $client instanceof ClientInterface ? static fn () => $client : ($client ?? static fn () => null),
            $review ?? static fn ($r, $c) => new SendApproval($r, $c->applicationId(), $c->tenantId(), $c->actor()['id'], $c->correlationId()));
    }

    public function testNativeClientSubmissionAndSanitizedAttemptStatus(): void
    {
        $client = $this->client(); $s = $this->service($client);
        $request = new SendRequest('room', 'event', 'private text');
        $result = $s->send($request)->toArray();
        self::assertSame('submitted', $result['code']);
        self::assertSame('unknown', $result['effects']);
        self::assertSame('unknown', $result['delivery']);
        self::assertSame('request', $result['correlation_id']);
        self::assertSame('POST', $client->last->method());
        self::assertSame('messages', $client->last->url());
        self::assertSame('private text', $client->last->body()['text']);
        self::assertSame($result, $s->status('room', 'event')->toArray());
        self::assertStringNotContainsString('private', json_encode($result));
        self::assertSame(1, $client->calls);
        self::assertTrue($request->isImmutable());
    }

    public function testDuplicateConflictAndDifferentActorCannotReplayOrRead(): void
    {
        $client = $this->client(); $host = $this->host();
        $s = $this->service($client, static function () use (&$host) { return $host; });
        $first = $s->send(new SendRequest('room', 'event', 'one'))->toArray();
        self::assertSame($first, $s->send(new SendRequest('room', 'event', 'one'))->toArray());
        self::assertSame('conflict', $s->send(new SendRequest('room', 'event', 'two'))->toArray()['code']);
        $host = $this->host(actor: 'other');
        self::assertSame('denied', $s->status('room', 'event')->toArray()['code']);
        self::assertSame('denied', $s->send(new SendRequest('room', 'event', 'one'))->toArray()['code']);
        self::assertSame(1, $client->calls);
    }

    public function testScopeParticipantAndCapabilityDenialsNeverReachClient(): void
    {
        foreach ([$this->host(app: 'foreign'), $this->host(tenant: 'foreign'), $this->host(actor: 'outsider'), $this->host(caps: [])] as $host) {
            $client = $this->client(); $s = $this->service($client, static fn () => $host);
            self::assertSame('denied', $s->send(new SendRequest('room', 'event', 'text'))->toArray()['code']);
            self::assertSame([], $s->catalog()); self::assertSame(0, $client->calls);
        }
    }

    public function testApprovalMustBindExactRequestAndHostScope(): void
    {
        $client = $this->client();
        foreach ([static fn () => true, static fn ($r) => new SendApproval(clone $r, 'app', 'tenant', 'operator', 'request'),
            static fn ($r) => new SendApproval($r, 'foreign', 'tenant', 'operator', 'request'),
            static fn ($r) => new SendApproval($r, 'app', 'tenant', 'operator', 'foreign')] as $review) {
            self::assertSame('denied', $this->service($client, review: $review)->send(new SendRequest('room', 'event', 'text'))->toArray()['code']);
        }
        self::assertSame(0, $client->calls);
    }

    public function testReviewRevocationIsCheckedImmediatelyBeforeDispatch(): void
    {
        $host = $this->host(); $client = $this->client();
        $review = function ($r, $c) use (&$host) {
            $host = $this->host(caps: []);
            return new SendApproval($r, 'app', 'tenant', 'operator', 'request');
        };
        $s = $this->service($client, static function () use (&$host) { return $host; }, $review);
        self::assertSame('denied', $s->send(new SendRequest('room', 'event', 'text'))->toArray()['code']);
        self::assertSame(0, $client->calls);
    }

    public function testUnavailabilityCanRetryButAmbiguousDispatchCannot(): void
    {
        $client = null; $s = $this->service(static function () use (&$client) { return $client; });
        self::assertSame('unavailable', $s->send(new SendRequest('room', 'event', 'text'))->toArray()['code']);
        $client = $this->client(static function () { throw new RuntimeException('secret token/private body'); });
        $r = $s->send(new SendRequest('room', 'event', 'text'))->toArray();
        self::assertSame('uncertain', $r['code']); self::assertSame('unknown', $r['effects']);
        self::assertSame($r, $s->send(new SendRequest('room', 'event', 'text'))->toArray());
        self::assertSame(1, $client->calls); self::assertStringNotContainsString('secret', json_encode($r));
    }

    public function testNegativeResponseIsNotProofOfNonexecution(): void
    {
        $client = $this->client(static fn () => ClientResponse::failure('credential private echo', 429));
        $s = $this->service($client); $request = new SendRequest('room', 'event', 'text');
        $r = $s->send($request)->toArray();
        self::assertSame('uncertain', $r['code']); self::assertSame('unknown', $r['effects']);
        self::assertStringNotContainsString('credential', json_encode($r));
        $s->send($request); self::assertSame(1, $client->calls);
    }

    public function testNotificationRequiresSeparateCapabilityAndChannelOptIn(): void
    {
        $request = new SendRequest('room', 'event', 'text', notification: true); $client = $this->client();
        self::assertSame('denied', $this->service($client)->send($request)->toArray()['code']);
        self::assertSame('denied', $this->service($client, fn () => $this->host(caps: ['versa.read', 'versa.send']), notifications: true)->send($request)->toArray()['code']);
        self::assertSame('submitted', $this->service($client, notifications: true)->send($request)->toArray()['code']);
        self::assertSame(1, $client->calls);
    }

    public function testInvalidInputAndUnboundedMediaNeverReachTransport(): void
    {
        foreach ([new SendRequest('room', 'event', str_repeat('x', 8193)), new SendRequest('room', 'event', "bad\xff"),
            new SendRequest('room', 'event', "bad\0text"), new SendRequest('room', 'event', 'text', [['url' => 'https://private.invalid']]),
            new SendRequest('room', '../event', 'text'), new SendRequest('room', "event\n", 'text')] as $request) {
            $client = $this->client(); self::assertSame('invalid', $this->service($client)->send($request)->toArray()['code']);
            self::assertSame(0, $client->calls);
        }
    }

    public function testMediaMetadataIsForwardedButNeverFetchedOrEchoed(): void
    {
        $client = $this->client(); $media = [['id' => 'asset-1', 'mime' => 'image/png', 'bytes' => 42]];
        $r = $this->service($client)->send(new SendRequest('room', 'event', 'text', $media))->toArray();
        self::assertSame($media, $client->last->body()['media']); self::assertArrayNotHasKey('media', $r);
    }

    public function testContextFailureAfterDispatchDoesNotClaimNoEffectsOrPermitReplay(): void
    {
        $fail = false;
        $client = $this->client(static function () use (&$fail) {
            $fail = true;
            return ClientResponse::success([], 202);
        });
        $s = $this->service($client, function () use (&$fail) {
            if ($fail) { throw new RuntimeException('private context failure'); }
            return $this->host();
        });
        $request = new SendRequest('room', 'event', 'text');
        $r = $s->send($request)->toArray();
        self::assertSame('denied', $r['code']);
        self::assertSame('unknown', $r['effects']);
        self::assertNull($r['correlation_id']);
        $fail = false;
        self::assertSame('submitted', $s->send($request)->toArray()['code']);
        self::assertSame(1, $client->calls);
    }

    public function testRevocationDuringDispatchRedactsReceiptAndStillPreventsReplay(): void
    {
        $host = $this->host();
        $client = $this->client(function () use (&$host) {
            $host = $this->host(caps: []);
            return ClientResponse::success(['token' => 'private'], 202);
        });
        $s = $this->service($client, static function () use (&$host) { return $host; });
        $r = $s->send(new SendRequest('room', 'event', 'text'))->toArray();
        self::assertSame('denied', $r['code']); self::assertSame('unknown', $r['effects']);
        self::assertNull($r['correlation_id']);
        self::assertSame('denied', $s->status('room', 'event')->toArray()['code']);
        $host = $this->host();
        self::assertSame('submitted', $s->send(new SendRequest('room', 'event', 'text'))->toArray()['code']);
        self::assertSame(1, $client->calls);
    }

    public function testResolverRevocationAndCrossInstanceReentrancyNeverDispatch(): void
    {
        $client = $this->client(); $host = $this->host(); $nested = $this->service($client);
        $s = $this->service(function () use ($nested, $client, &$host) {
            self::assertSame('denied', $nested->send(new SendRequest('room', 'nested', 'text'))->toArray()['code']);
            self::assertSame([], $nested->catalog());
            self::assertSame('denied', $nested->status('room', 'nested')->toArray()['code']);
            $host = $this->host(caps: []);
            return $client;
        }, static function () use (&$host) { return $host; });
        self::assertSame('denied', $s->send(new SendRequest('room', 'event', 'text'))->toArray()['code']);
        self::assertSame(0, $client->calls);
        self::assertSame('submitted', $nested->send(new SendRequest('room', 'nested', 'text'))->toArray()['code']);
        self::assertSame(1, $client->calls);
    }

    public function testDiscoveryAndMissingStatusDoNotResolveTransportOrReview(): void
    {
        $calls = 0;
        $unexpected = static function () use (&$calls) { ++$calls; throw new RuntimeException(); };
        $s = $this->service($unexpected, review: $unexpected);
        self::assertSame([['id' => 'room', 'send' => 'injected_transport', 'status' => 'attempt_only',
            'notifications' => false, 'inbound' => 'unavailable', 'live_delivery' => 'unqualified']], $s->catalog());
        self::assertSame('not_found', $s->status('room', 'missing')->toArray()['code']);
        self::assertSame('invalid', $s->status('room', "missing\n")->toArray()['code']);
        self::assertSame(0, $calls);
    }

    public function testReceiptIsolationPreservesCaseAndTupleBoundaries(): void
    {
        $host = $this->host(); $client = $this->client();
        $s = new MessagingService([
            new Channel('app', 'tenant', 'room', ['operator']),
            new Channel('app', 'Tenant', 'room', ['operator']),
            new Channel('app-tenant', 'other', 'room', ['operator']),
            new Channel('app', 'tenant-other', 'room', ['operator']),
        ], static function () use (&$host) { return $host; }, static fn () => $client,
            static fn ($r, $c) => new SendApproval($r, $c->applicationId(), $c->tenantId(), $c->actor()['id'], $c->correlationId()));
        $keys = [];
        foreach ([['app', 'tenant'], ['app', 'Tenant'], ['app-tenant', 'other'], ['app', 'tenant-other']] as [$app, $tenant]) {
            $host = $this->host($app, $tenant);
            self::assertSame('not_found', $s->status('room', 'event')->toArray()['code']);
            self::assertSame('submitted', $s->send(new SendRequest('room', 'event', 'text'))->toArray()['code']);
            $keys[] = $client->last->body()['idempotency_key'];
        }
        self::assertCount(4, array_unique($keys)); self::assertSame(4, $client->calls);
    }

    public function testLifecycleAndMalformedMediaDenials(): void
    {
        $client = $this->client();
        foreach ([[], ['versa' => 'inactive'], ['versa' => 'failed']] as $states) {
            $host = new AgentRuntimeContext('tenant', ['id' => 'operator'], ['versa'], $states,
                ['versa.read', 'versa.send'], 'request', new AgentApplicationScope('app'));
            $s = $this->service($client, static fn () => $host);
            self::assertSame('denied', $s->send(new SendRequest('room', 'event', 'text'))->toArray()['code']);
            self::assertSame([], $s->catalog());
        }
        foreach ([[['id' => 'asset', 'mime' => 'image/png', 'bytes' => '42']],
            [['id' => 'asset', 'mime' => 'image/png', 'bytes' => 5242881]],
            [['id' => 'asset', 'mime' => 'image/png', 'bytes' => 42, 'url' => 'https://private.invalid']],
            array_fill(0, 5, ['id' => 'asset', 'mime' => 'image/png', 'bytes' => 42])] as $media) {
            self::assertSame('invalid', $this->service($client)->send(new SendRequest('room', 'event', 'text', $media))->toArray()['code']);
        }
        self::assertSame(0, $client->calls);
    }

    public function testMissingClientCapabilityNeverDispatches(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('capabilities')->willReturn(new ClientCapabilities(['service' => 'records', 'actions' => ['read']]));
        $client->expects(self::never())->method('send');
        $s = $this->service($client);
        self::assertSame('unavailable', $s->send(new SendRequest('room', 'event', 'text'))->toArray()['code']);
        self::assertSame('not_found', $s->status('room', 'event')->toArray()['code']);
    }

    public function testReceiptCapacityNeverEvictsAnAmbiguousAttempt(): void
    {
        $client = $this->client(static function ($request) {
            if ($request->body()['text'] === 'ambiguous') { throw new RuntimeException('unknown provider outcome'); }
            return ClientResponse::success([], 202);
        });
        $s = $this->service($client);
        for ($i = 0; $i < 1000; ++$i) {
            self::assertSame($i === 0 ? 'uncertain' : 'submitted',
                $s->send(new SendRequest('room', 'id-' . $i, $i === 0 ? 'ambiguous' : 'text'))->toArray()['code']);
        }
        self::assertSame('capacity', $s->send(new SendRequest('room', 'overflow', 'text'))->toArray()['code']);
        self::assertSame('uncertain', $s->send(new SendRequest('room', 'id-0', 'ambiguous'))->toArray()['code']);
        self::assertSame(1000, $client->calls);
    }

    public function testActualNativeApplicationRegistrationUsesTheGovernedService(): void
    {
        $client = $this->client(); $app = new Application(['name' => 'versa.fixture']);
        $app->bindArgs([
            'channels' => [new Channel('app', 'tenant', 'room', ['operator'])],
            'context' => fn () => $this->host(),
            'clients' => static fn () => $client,
            'review' => static fn ($r) => new SendApproval($r, 'app', 'tenant', 'operator', 'request'),
        ], MessagingService::class);
        $s = $app->resolve(MessagingService::class);
        self::assertInstanceOf(MessagingService::class, $s);
        $app->delegate('versa', $s); $seen = null;
        $app->register('versa', new \BlueFission\Behavioral\Behaviors\Action('DoVersaSend'), function () use (&$seen): void {
            $seen = $this->send(new SendRequest('room', 'event', 'text'));
        });
        $app->perform('DoVersaSend'); self::assertSame('submitted', $seen->toArray()['code']); self::assertSame(1, $client->calls);
    }
}
