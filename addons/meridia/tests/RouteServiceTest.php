<?php
declare(strict_types=1);
namespace AddOns\Meridia\Tests;

use AddOns\Meridia\Business\{RouteCommandProcessor, RouteService};
use AddOns\Meridia\Domain\{ResolutionContext, RouteCandidate, RouteSource};
use App\Business\Services\AddOnContractValidator;
use App\Domain\Agents\{AgentApplicationScope, AgentRuntimeContext};
use BlueFission\Data\Storage\Storage;
use BlueFission\DevElation;
use BlueFission\Services\Application;
use BlueFission\Wise\Cmd\{CommandProcessor, CommandRequest, CommandResult};
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RouteServiceTest extends TestCase
{
    private function host(?string $tenant = 'tenant', array $caps = ['meridia.read', 'reports.read'], string $app = 'app'): AgentRuntimeContext
    {
        return new AgentRuntimeContext($tenant, ['id' => 'operator'], ['meridia'], ['meridia' => 'active'],
            $caps, 'correlation', new AgentApplicationScope($app));
    }

    private function route(string $id = 'reports', int $priority = 0, ?string $next = null,
        ?RouteSource $source = null, string $uri = '/reports?period=month', string $cap = 'reports.read'): RouteCandidate
    {
        $application = new Application(['name' => 'meridia.fixture.' . uniqid()]);
        $mapping = $application->map('get', explode('?', $uri)[0], static function () {
            throw new RuntimeException('Target must never execute during resolution');
        }, 'reports.show');
        return new RouteCandidate($id, $source ?? new RouteSource('local', 'revision-1', 'app', 'tenant', 200),
            $mapping, $uri, $cap, ['reports.monthly'], $priority, $next);
    }

    private function service(array $routes): RouteService { return new RouteService($routes, static fn () => 100); }
    private function context(): ResolutionContext { return new ResolutionContext($this->host()); }

    public function testPackageAndVersionedRecords(): void
    {
        $validation = (new AddOnContractValidator())->validate(dirname(__DIR__));
        self::assertTrue($validation['valid'], json_encode($validation['errors']));
        $factory = require dirname(__DIR__) . '/main.php';
        self::assertSame('meridia', $factory()->contributions()['agents']['owner']);
        self::assertSame(1, $this->route()->toArray()['version']);
        self::assertTrue($this->route()->isImmutable());
        self::assertSame(0, $this->service([])->crawlPolicy()->toArray()['request_budget']);
        self::assertFalse($this->service([])->crawlPolicy()->toArray()['enabled']);
    }

    public function testUrlQueryAndExplicitIntentResolveSameSnapshot(): void
    {
        $route = $this->route();
        $service = $this->service([$route]);
        $url = $service->resolve('url', '/reports?period=month', $this->context());
        $intent = $service->resolve('intent', 'reports.monthly', $this->context());
        self::assertSame('resolved', $url->status);
        self::assertSame($route, $url->candidate);
        self::assertSame($route, $intent->candidate);
        self::assertSame($service->catalog($this->context())[0], $url->toArray()['candidate']);
        self::assertSame('none', $url->toArray()['effects']);
        self::assertSame('no_match', $service->resolve('intent', 'write.reports', $this->context())->status);
    }

    public function testPrecedenceAndAmbiguityAbstain(): void
    {
        $service = $this->service([$this->route('a', 1), $this->route('b', 2)]);
        self::assertSame('b', $service->resolve('intent', 'reports.monthly', $this->context())->candidate->id);
        $service = $this->service([$this->route('a', 2), $this->route('b', 2)]);
        $result = $service->resolve('intent', 'reports.monthly', $this->context());
        self::assertSame('ambiguous', $result->status);
        self::assertNull($result->candidate);
    }

    public function testDeniedHigherPriorityNeverFallsBack(): void
    {
        $service = $this->service([$this->route('a', 1), $this->route('b', 2, cap: 'admin')]);
        $result = $service->resolve('intent', 'reports.monthly', $this->context());
        self::assertSame('denied', $result->status);
        self::assertNull($result->candidate);
        self::assertCount(1, $service->catalog($this->context()));
    }

    public function testScopeAndMissingActorCannotDiscover(): void
    {
        $service = $this->service([$this->route()]);
        foreach ([$this->host('other'), $this->host(null), $this->host(app: 'other')] as $host) {
            $context = new ResolutionContext($host);
            self::assertSame([], $service->catalog($context));
            self::assertSame('no_match', $service->resolve('intent', 'reports.monthly', $context)->status);
        }
        $context = new ResolutionContext(new AgentRuntimeContext());
        self::assertSame('denied', $service->resolve('intent', 'reports.monthly', $context)->status);
    }

    public function testStaleBoundaryAndRevocation(): void
    {
        $route = $this->route(source: new RouteSource('local', 'r1', 'app', 'tenant', 100));
        $service = $this->service([$route]);
        self::assertSame('stale', $service->resolve('intent', 'reports.monthly', $this->context())->status);
        self::assertSame([], $service->catalog($this->context()));
        $context = new ResolutionContext($this->host(caps: []));
        self::assertSame('denied', $service->resolve('intent', 'reports.monthly', $context)->status);
    }

    public function testAliasesCannotLoopEscapeScopeOrTargetMissingRoute(): void
    {
        $a = $this->route('a', 2, 'b');
        foreach ([
            [[$a, $this->route('b', 1, 'a')], 'loop'],
            [[$a], 'invalid_target'],
            [[$a, $this->route('b', 1, source: new RouteSource('x', 'r1', 'app', 'other', 200))], 'invalid_target'],
            [[$a, $this->route('b', 1, cap: 'admin')], 'denied'],
        ] as [$routes, $expected]) {
            self::assertSame($expected, $this->service($routes)->resolve('intent', 'reports.monthly', $this->context())->status);
        }
        $b = $this->route('b', 1);
        self::assertSame($b, $this->service([$a, $b])->resolve('intent', 'reports.monthly', $this->context())->candidate);
    }

    public function testInvalidUrisAreNeverTargets(): void
    {
        $service = $this->service([$this->route()]);
        foreach (['https://host/reports', '//host/path', '/reports#fragment', '/reports?a=1&a=2',
            '/reports?a[]=x', '/reports?a=%GG', '/reports?bad.key=x', '/reports?x=1&&y=2'] as $uri) {
            self::assertSame('invalid', $service->resolve('url', $uri, $this->context())->status, $uri);
        }
        self::assertSame('/reports?a=1&b=2', RouteCandidate::normalizeUri('/reports?b=2&a=1'));
    }

    public function testSourceAndMappingMutationsCannotChangeSnapshot(): void
    {
        $app = new Application(['name' => 'meridia.snapshot.' . uniqid()]);
        $mapping = $app->map('get', '/reports', static fn () => 'no execution', 'reports.show');
        $source = new RouteSource('local', 'r1', 'app', 'tenant', 200);
        $route = new RouteCandidate('reports', $source, $mapping, '/reports', 'reports.read');
        $mapping->name = 'evil.changed';
        $route->data()->set('target', 'evil.changed');
        $source->data()->set('tenant', 'other');
        self::assertSame('reports.show', $route->target);
        self::assertSame('reports.show', $route->toArray()['target']);
        self::assertSame('tenant', $route->source->tenant);
    }

    public function testWiseCommandsShareFreshHostAuthorization(): void
    {
        $host = $this->host();
        $processor = new RouteCommandProcessor(new CommandProcessor($this->createMock(Storage::class)),
            $this->service([$this->route()]), static function () use (&$host) { return $host; });
        foreach (['resolve', 'validate', 'explain', 'dry-run'] as $op) {
            self::assertSame('resolved', $processor->process('get meridia-route "' . $op . '" "intent" "reports.monthly"')->output()['status']);
        }
        self::assertCount(1, $processor->process('get meridia-route "list"')->output()['routes']);
        self::assertSame(CommandResult::PARSED, $processor->process(CommandRequest::parse('get meridia-route "list"'))->status());
        self::assertSame(CommandResult::INVALID, $processor->process('do meridia-route "list"')->status());
        $host = $this->host(caps: []);
        self::assertSame(CommandResult::FAILED, $processor->process('get meridia-route "list"')->status());
    }

    public function testAliasDiscoveryRejectsInvalidChainAndExpiry(): void
    {
        $a = $this->route('a', 2, 'b');
        self::assertSame([], $this->service([$a])->catalog($this->context()));
        $b = $this->route('b', 1, source: new RouteSource('local', 'r1', 'app', 'tenant', 100));
        self::assertSame([], $this->service([$a, $b])->catalog($this->context()));
        self::assertSame('stale', $this->service([$a, $b])->resolve('intent', 'reports.monthly', $this->context())->status);
    }

    public function testDuplicateCandidateIdentityRejectsCatalog(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service([$this->route(), $this->route()]);
    }

    public function testUnsupportedNativeMappingRejectsCandidate(): void
    {
        $app = new Application(['name' => 'meridia.invalid.mapping']);
        $mapping = $app->map('post', '/reports', static fn () => null, 'reports.show');
        $this->expectException(\InvalidArgumentException::class);
        new RouteCandidate('reports', new RouteSource('local', 'r1', 'app', null, 200), $mapping, '/reports', 'reports.read');
    }

    public function testCommandContextCannotSupplyHostAuthority(): void
    {
        $processor = new RouteCommandProcessor(new CommandProcessor($this->createMock(Storage::class)),
            $this->service([$this->route()]), static fn () => new AgentRuntimeContext());
        $result = $processor->process(new CommandRequest('get meridia-route "list"', context: $this->host()->toArray()));
        self::assertSame(CommandResult::FAILED, $result->status());
        self::assertStringNotContainsString('reports.show', json_encode($result->output()));
    }

    public function testNativeApplicationResolveDelegateAndBehavior(): void
    {
        $app = new Application(['name' => 'meridia.delegation.' . uniqid()]);
        $app->bindArgs(['routes' => [$this->route()], 'clock' => static fn () => 100], RouteService::class);
        $service = $app->resolve(RouteService::class);
        $app->delegate('meridia', $service);
        $seen = null;
        $context = $this->context();
        $app->register('meridia', new \BlueFission\Behavioral\Behaviors\Action('DoMeridiaResolve'), function () use (&$seen, $context): void {
            $seen = $this->resolve('intent', 'reports.monthly', $context);
        });
        $app->perform('DoMeridiaResolve');
        self::assertSame('resolved', $seen->status);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testObserverGetsOnlyStatusAndCannotReenter(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        DevElation::up();
        $service = $this->service([$this->route()]);
        $seen = null;
        $nested = null;
        DevElation::action('meridia.resolve.result', function ($summary) use (&$seen, &$nested, $service): void {
            $seen = $summary;
            $nested = $service->resolve('intent', 'reports.monthly', $this->context());
            throw new RuntimeException('observer');
        });
        self::assertSame('resolved', $service->resolve('intent', 'reports.monthly', $this->context())->status);
        self::assertSame(['version' => 1, 'status' => 'resolved', 'effects' => 'none'], $seen);
        self::assertSame('denied', $nested->status);
    }
}
