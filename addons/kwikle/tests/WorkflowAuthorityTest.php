<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Tests;

use AddOns\Kwikle\Business\{FixtureConnector, WorkflowService};
use AddOns\Kwikle\Domain\{Action, Approval, ConnectionReference, RunRequest, Workflow, Trigger};
use App\Domain\Agents\{AgentApplicationScope, AgentRuntimeContext};
use BlueFission\DevElation;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkflowAuthorityTest extends TestCase
{
    private function context(array $changes = []): AgentRuntimeContext
    {
        $scope = array_replace(['application' => 'app', 'tenant' => 'tenant', 'actor' => 'operator',
            'addons' => ['kwikle'], 'states' => ['kwikle' => 'active'],
            'caps' => ['kwikle.read', 'kwikle.execute'], 'correlation' => 'request-1'], $changes);
        return new AgentRuntimeContext($scope['tenant'], ['id' => $scope['actor']], $scope['addons'],
            $scope['states'], $scope['caps'], $scope['correlation'], new AgentApplicationScope($scope['application']));
    }

    private function workflow(): Workflow
    {
        return new Workflow('capture', 'app', 'tenant', new Trigger(),
            new Action(new ConnectionReference('local', 'fixture', 'fixture.capture')));
    }

    private function approval(RunRequest $request, bool $compensation = false): Approval
    {
        return new Approval($request, 'app', 'tenant', 'operator', $compensation);
    }

    /** @dataProvider changedAuthority */
    public function testReviewCannotOutliveCurrentHostAuthority(array|string $change): void
    {
        $initial = $this->context();
        $current = $initial;
        $reads = 0;
        $connector = new FixtureConnector();
        $service = new WorkflowService([$this->workflow()], $connector,
            function (RunRequest $request) use (&$current, $change): Approval {
                $current = is_array($change) ? $this->context($change) : $change;
                return $this->approval($request);
            },
            static function () use (&$current, &$reads) {
                $reads++;
                if ($current === 'throw') { throw new RuntimeException('private-host-detail'); }
                return $current;
            });

        $result = $service->run(new RunRequest('capture', 'event', 'private-subject'), $initial);
        self::assertSame('denied', $result->status);
        self::assertSame('none', $result->effects);
        self::assertSame(0, $connector->count());
        self::assertGreaterThanOrEqual(2, $reads);
        self::assertStringNotContainsString('private', json_encode($result->toArray()));
    }

    public static function changedAuthority(): array
    {
        return [
            'capability revoked' => [['caps' => ['kwikle.read']]],
            'addon disabled' => [['addons' => []]],
            'lifecycle changed' => [['states' => ['kwikle' => 'disabled']]],
            'application changed' => [['application' => 'other-app']],
            'tenant changed' => [['tenant' => 'other-tenant']],
            'actor changed' => [['actor' => 'other-actor']],
            'request changed' => [['correlation' => 'other-request']],
            'authority unavailable' => ['unavailable'],
            'authority throws' => ['throw'],
        ];
    }

    public function testMissingFreshAuthorityProviderDeniesBeforeReview(): void
    {
        $reviews = 0;
        $connector = new FixtureConnector();
        $service = new WorkflowService([$this->workflow()], $connector,
            function (RunRequest $request) use (&$reviews): Approval { $reviews++; return $this->approval($request); });
        self::assertSame('denied', $service->run(new RunRequest('capture', 'event', 'subject'), $this->context())->status);
        self::assertSame(0, $reviews);
        self::assertSame(0, $connector->count());
    }

    public function testAvailabilityCallbackCannotRetainARevokedGrant(): void
    {
        $current = $this->context();
        $initial = $current;
        $connector = new class(function () use (&$current): void { $current = $this->context(['caps' => []]); }) extends FixtureConnector {
            public function __construct(private Closure $changed) { parent::__construct(); }
            public function available(): bool { ($this->changed)(); return true; }
        };
        $service = new WorkflowService([$this->workflow()], $connector, fn ($request) => $this->approval($request),
            static function () use (&$current) { return $current; });
        self::assertSame('denied', $service->run(new RunRequest('capture', 'event', 'subject'), $initial)->status);
        self::assertSame(0, $connector->count());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNativeGraphCallbackCannotCarryAuthorityIntoCapture(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        $current = $this->context();
        $initial = $current;
        $connector = new FixtureConnector();
        $service = new WorkflowService([$this->workflow()], $connector, fn ($request) => $this->approval($request),
            static function () use (&$current) { return $current; });
        $changed = false;
        DevElation::up();
        // The locked native Node applies this filter to the fixed mapping label.
        // Payload/authority never enters Synematic's input or observation data.
        DevElation::filter('develation.apply', function ($value) use (&$current, &$changed) {
            if ($value === 'mapped') {
                $changed = true;
                $current = $this->context(['caps' => []]);
            }
            return $value;
        });
        $request = new RunRequest('capture', 'event', 'private-subject');
        $result = $service->run($request, $initial);
        self::assertTrue($changed, 'The real native graph callback must have executed.');
        self::assertSame('denied', $result->status);
        self::assertSame('none', $result->effects);
        self::assertSame(0, $connector->count());
        $current = $initial;
        self::assertSame($result, $service->run($request, $initial));
        self::assertSame('retry_denied', $service->run(new RunRequest('capture', 'event', 'private-subject', 2), $initial)->status);
        self::assertSame(0, $connector->count());
    }

    /** @dataProvider settledEffects */
    public function testRevocationPreservesEffectsButCannotAuthorizeCompensation(bool $partial, string $status): void
    {
        $current = $this->context();
        $initial = $current;
        $connector = new class($partial, function () use (&$current): void { $current = $this->context(['caps' => []]); }) extends FixtureConnector {
            public int $compensations = 0;
            public function __construct(private bool $partial, private Closure $changed) { parent::__construct(); }
            public function capture(string $receipt, string $subject): array {
                parent::capture($receipt, $subject);
                ($this->changed)();
                return ['ok' => !$this->partial, 'effects' => 'committed'];
            }
            public function compensate(string $receipt): bool { $this->compensations++; return parent::compensate($receipt); }
        };
        $service = new WorkflowService([$this->workflow()], $connector, fn ($request) => $this->approval($request, true),
            static function () use (&$current) { return $current; });
        $request = new RunRequest('capture', 'event', 'subject');
        $result = $service->run($request, $initial);
        self::assertSame($status, $result->status);
        self::assertSame('committed', $result->effects);
        self::assertSame(1, $connector->count());
        self::assertSame(0, $connector->compensations);
        $current = $initial;
        self::assertSame($result, $service->run($request, $initial));
        self::assertSame('retry_denied', $service->run(new RunRequest('capture', 'event', 'subject', 2), $initial)->status);
        self::assertSame(1, $connector->count());
    }

    public static function settledEffects(): array { return [[false, 'completed'], [true, 'partial']]; }

    public function testAuthorityResolversCannotReenterAndTheFenceReleases(): void
    {
        $context = $this->context();
        $connector = new FixtureConnector();
        $otherConnector = new FixtureConnector();
        $other = new WorkflowService([$this->workflow()], $otherConnector, fn ($request) => $this->approval($request),
            static fn () => $context);
        $nested = [];
        $service = new WorkflowService([$this->workflow()], $connector, fn ($request) => $this->approval($request),
            static function () use ($context, $other, &$nested) {
                $nested[] = $other->run(new RunRequest('capture', 'nested', 'subject'), $context)->status;
                return $context;
            });
        self::assertSame('completed', $service->run(new RunRequest('capture', 'first', 'subject'), $context)->status);
        self::assertSame('completed', $service->run(new RunRequest('capture', 'second', 'subject'), $context)->status);
        self::assertNotEmpty($nested);
        self::assertSame(['denied'], array_values(array_unique($nested)));
        self::assertSame(2, $connector->count());
        self::assertSame(0, $otherConnector->count());
    }

    public function testFailedAuthorityResolutionDoesNotPoisonTheNextInvocation(): void
    {
        $context = $this->context();
        $connector = new FixtureConnector();
        $unavailable = true;
        $service = new WorkflowService([$this->workflow()], $connector, fn ($request) => $this->approval($request),
            static function () use ($context, &$unavailable) {
                if ($unavailable) { throw new RuntimeException('private-host-detail'); }
                return $context;
            });
        $request = new RunRequest('capture', 'event', 'subject');
        self::assertSame('denied', $service->run($request, $context)->status);
        self::assertSame(0, $connector->count());
        $unavailable = false;
        self::assertSame('completed', $service->run($request, $context)->status);
        self::assertSame(1, $connector->count());
    }
}
