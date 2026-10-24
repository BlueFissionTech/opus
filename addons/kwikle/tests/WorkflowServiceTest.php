<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Tests;

use AddOns\Kwikle\Business\{FixtureConnector, WorkflowCommandProcessor, WorkflowService};
use AddOns\Kwikle\Domain\{Action, Approval, ConnectionReference, RetryPolicy, RunRequest, Trigger, Workflow};
use App\Business\Services\AddOnContractValidator;
use App\Domain\Agents\{AgentApplicationScope, AgentRuntimeContext};
use BlueFission\Data\Storage\Storage;
use BlueFission\DevElation;
use BlueFission\Services\Application;
use BlueFission\Synematic\Orchestration\GraphDocument;
use BlueFission\Wise\Cmd\{CommandProcessor, CommandRequest, CommandResult};
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WorkflowServiceTest extends TestCase
{
    private function graph(array $changes = []): GraphDocument
    {
        return new GraphDocument(array_replace([
            'id' => 'fixture.capture', 'entry' => 'received', 'max_steps' => 3,
            'nodes' => [
                ['id' => 'received', 'resolver' => 'fixture.created'],
                ['id' => 'mapped', 'resolver' => 'fixture.subject'],
                ['id' => 'stored', 'resolver' => 'fixture.capture'],
            ],
            'edges' => [
                ['from' => ['node' => 'received'], 'to' => ['node' => 'mapped']],
                ['from' => ['node' => 'mapped'], 'to' => ['node' => 'stored'],
                    'condition_ref' => 'fixture.subject-present'],
            ],
        ], $changes));
    }

    private function graphWorkflow(GraphDocument $graph): Workflow
    {
        return new Workflow('capture', 'app', 'tenant', new Trigger(),
            new Action(new ConnectionReference('local', 'fixture', 'fixture.capture')), graph: $graph);
    }

    public function testNativeGraphReferencesAndConditionsDriveExecution(): void
    {
        $workflow = $this->graphWorkflow($this->graph());
        $connector = new FixtureConnector();
        $service = new WorkflowService([$workflow], $connector,
            static fn ($request) => new Approval($request, 'app', 'tenant', 'operator'), fn () => $this->context());
        self::assertSame($workflow->graph()->toArray(), $service->describe('capture', $this->context())['definition']);
        // The native condition prevents capture for an empty subject.
        self::assertSame('skipped', $service->run(new RunRequest('capture', 'empty', ''), $this->context())->status);
        self::assertSame(0, $connector->count());
        self::assertSame('completed', $service->run(new RunRequest('capture', 'full', 'subject'), $this->context())->status);
        self::assertSame(1, $connector->count());
    }

    public function testNativeTopologyCannotSkipMappingAndExecuteAnEffect(): void
    {
        $workflow = $this->graphWorkflow($this->graph(['entry' => 'stored', 'edges' => []]));
        $connector = new FixtureConnector();
        $service = new WorkflowService([$workflow], $connector,
            static fn ($request) => new Approval($request, 'app', 'tenant', 'operator'), fn () => $this->context());
        self::assertSame('failed', $service->run(new RunRequest('capture', 'event', 'subject'), $this->context())->status);
        self::assertSame(0, $connector->count());
    }

    public function testWorkflowSnapshotsNativeDefinitionWithoutSharingMutableAuthority(): void
    {
        $document = $this->graph();
        $workflow = $this->graphWorkflow($document);
        $document->entry = 'stored';
        $export = $workflow->graph();
        $export->entry = 'stored';
        self::assertSame('received', $workflow->graph()->entry());
    }

    public function testNativeSchemaRejectsInvalidTopology(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->graphWorkflow($this->graph(['entry' => 'missing']));
    }

    public function testUnreviewedResolverAndFanOutAreUnavailableBeforeApproval(): void
    {
        foreach ([['mode' => 'all'], ['nodes' => [['id' => 'received', 'resolver' => 'shell.exec']], 'edges' => []]] as $changes) {
            $workflow = $this->graphWorkflow($this->graph($changes));
            $connector = new FixtureConnector();
            $reviews = 0;
            $service = new WorkflowService([$workflow], $connector, static function () use (&$reviews) { $reviews++; }, fn () => $this->context());
            self::assertSame('unavailable', $service->run(new RunRequest('capture', 'event', 'subject'), $this->context())->status);
            self::assertSame(0, $reviews);
            self::assertSame(0, $connector->count());
        }
    }

    private function context(string $tenant = 'tenant', string $actor = 'operator', array $caps = ['kwikle.read', 'kwikle.execute']): AgentRuntimeContext
    {
        return new AgentRuntimeContext($tenant, ['id' => $actor], ['kwikle'], ['kwikle' => 'active'],
            $caps, 'correlation', new AgentApplicationScope('app'));
    }

    private function fixture(?FixtureConnector $connector = null, ?callable $review = null, string $client = 'fixture'): array
    {
        $connector ??= new FixtureConnector();
        $workflow = new Workflow('capture', 'app', 'tenant', new Trigger(),
            new Action(new ConnectionReference('local', $client, 'fixture.capture')), new RetryPolicy(2));
        $review ??= static fn ($request, $context) => new Approval($request, $context->applicationId(),
            $context->tenantId(), $context->actor()['id']);
        return [new WorkflowService([$workflow], $connector, $review, fn () => $this->context()), $connector];
    }

    public function testPackageAndPassiveFactory(): void
    {
        $validation = (new AddOnContractValidator())->validate(dirname(__DIR__));
        self::assertTrue($validation['valid'], json_encode($validation['errors']));
        $factory = require dirname(__DIR__) . '/main.php';
        self::assertSame('kwikle', $factory()->contributions()['agents']['owner']);
    }

    public function testNativeWiseDiscoveryDryRunAndExecution(): void
    {
        [$service, $connector] = $this->fixture();
        $processor = new WorkflowCommandProcessor(new CommandProcessor($this->createMock(Storage::class)), $service, fn () => $this->context());
        self::assertSame(CommandResult::PARSED, $processor->process(CommandRequest::parse('do kwikle-workflow "run" "capture" "event" "subject"'))->status());
        self::assertSame(['capture'], $processor->process('get kwikle-workflow "list"')->output()['workflows']);
        self::assertSame('valid', $processor->process('get kwikle-workflow "validate" "capture"')->output()['status']);
        self::assertSame('subject -> capture.subject', $processor->process('get kwikle-workflow "explain" "capture"')->output()['mapping']);
        self::assertSame('dry_run', $processor->process('get kwikle-workflow "dry-run" "capture" "event" "subject"')->output()['status']);
        self::assertSame(0, $connector->count());
        self::assertSame(CommandResult::COMPLETED, $processor->process('do kwikle-workflow "run" "capture" "event" "subject"')->status());
        self::assertSame(1, $connector->count());
        self::assertSame('completed', $processor->process('get kwikle-workflow "status" "capture" "event"')->output()['status']);
        self::assertSame(CommandResult::INVALID, $processor->process('get kwikle-workflow "run" "capture" "event" "subject"')->status());
    }

    public function testDuplicateConflictAndOtherActorCannotReplay(): void
    {
        [$service, $connector] = $this->fixture();
        $request = new RunRequest('capture', 'event', 'subject');
        $receipt = $service->run($request, $this->context());
        self::assertSame('completed', $receipt->status);
        self::assertSame('correlation', $receipt->correlation);
        self::assertSame($receipt, $service->run($request, $this->context()));
        self::assertSame('conflict', $service->run(new RunRequest('capture', 'event', 'changed'), $this->context())->status);
        self::assertSame('denied', $service->run($request, $this->context(actor: 'other'))->status);
        self::assertNull($service->status('capture', 'event', $this->context(actor: 'other')));
        self::assertSame(1, $connector->count());
    }

    public function testAuthorizationAndApprovalBindActualRequest(): void
    {
        [$service, $connector] = $this->fixture();
        $request = new RunRequest('capture', 'event', '01');
        self::assertSame('denied', $service->run($request, $this->context(tenant: 'other'))->status);
        self::assertSame('denied', $service->run($request, $this->context(caps: ['kwikle.read']))->status);
        [$service] = $this->fixture($connector, static fn () => new Approval(new RunRequest('capture', 'event', '1'), 'app', 'tenant', 'operator'));
        self::assertSame('denied', $service->run($request, $this->context())->status);
        self::assertSame(0, $connector->count());
    }

    public function testExternalOrUnavailableProviderDoesNotExecute(): void
    {
        [$service, $connector] = $this->fixture(client: 'simpleclients.provider');
        self::assertSame('unavailable', $service->run(new RunRequest('capture', 'event', 'subject'), $this->context())->status);
        self::assertSame(0, $connector->count());
        $connector = new class extends FixtureConnector { public function available(): bool { return false; } };
        [$service] = $this->fixture($connector);
        self::assertSame('unavailable', $service->run(new RunRequest('capture', 'event', 'subject'), $this->context())->status);
        self::assertSame(0, $connector->count());
    }

    public function testExplicitRetryOnlyAfterConfirmedNoEffects(): void
    {
        $connector = new class extends FixtureConnector {
            public int $calls = 0;
            public function capture(string $receipt, string $subject): array {
                return ++$this->calls === 1 ? ['ok' => false, 'effects' => 'none'] : parent::capture($receipt, $subject);
            }
        };
        [$service] = $this->fixture($connector);
        self::assertSame('failed', $service->run(new RunRequest('capture', 'event', 'subject'), $this->context())->status);
        self::assertSame(1, $connector->calls);
        self::assertSame('completed', $service->run(new RunRequest('capture', 'event', 'subject', 2), $this->context())->status);
        self::assertSame(2, $connector->calls);
        self::assertSame('retry_denied', $service->run(new RunRequest('capture', 'event', 'subject', 3), $this->context())->status);
    }

    public function testUncertainFailureCannotRetryOrLeakException(): void
    {
        $connector = new class extends FixtureConnector {
            public int $calls = 0;
            public function capture(string $receipt, string $subject): array {
                $this->calls++; throw new RuntimeException('private-token');
            }
        };
        [$service] = $this->fixture($connector);
        $receipt = $service->run(new RunRequest('capture', 'event', 'private-subject'), $this->context());
        self::assertSame('uncertain', $receipt->status);
        self::assertStringNotContainsString('private', json_encode($receipt->toArray()));
        self::assertSame('retry_denied', $service->run(new RunRequest('capture', 'event', 'private-subject', 2), $this->context())->status);
        self::assertSame(1, $connector->calls);
    }

    /** @dataProvider compensationCases */
    public function testPartialFailureNeedsSpecificCompensationApproval(bool $approved, string $expected): void
    {
        $connector = new class extends FixtureConnector {
            public function capture(string $receipt, string $subject): array {
                parent::capture($receipt, $subject); return ['ok' => false, 'effects' => 'committed'];
            }
        };
        [$service] = $this->fixture($connector, static fn ($request) => new Approval($request, 'app', 'tenant', 'operator', $approved));
        self::assertSame($expected, $service->run(new RunRequest('capture', 'event', 'subject'), $this->context())->status);
        self::assertSame($approved ? 0 : 1, $connector->count());
    }

    public static function compensationCases(): array { return [[false, 'partial'], [true, 'compensated']]; }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testObserversCannotLeakPayloadReenterOrEraseReceipt(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        [$service, $connector] = $this->fixture();
        $request = new RunRequest('capture', 'event', 'private-subject');
        $events = [];
        DevElation::up();
        DevElation::action('kwikle.run.result', function ($event) use (&$events, $service, $request): void {
            $events[] = $event;
            self::assertSame('denied', $service->run($request, $this->context())->status);
            throw new RuntimeException('observer-failure');
        });
        $receipt = $service->run($request, $this->context());
        self::assertSame('completed', $receipt->status);
        self::assertSame($receipt, $service->status('capture', 'event', $this->context()));
        self::assertSame([['version' => 1, 'status' => 'completed', 'effects' => 'committed']], $events);
        self::assertSame(1, $connector->count());
    }
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testApplicationResolvesDelegatedServiceWithFreshAuthority(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        $connector = new FixtureConnector();
        $workflow = new Workflow('capture', 'app', 'tenant', new Trigger(),
            new Action(new ConnectionReference('local', 'fixture', 'fixture.capture')));
        $app = new Application(['name' => 'kwikle-registration']);
        $current = $this->context();
        $resolveContext = function () use (&$current) { return $current; };
        $app->bindArgs(['workflows' => [$workflow], 'connector' => $connector,
            'review' => static fn ($request) => new Approval($request, 'app', 'tenant', 'operator'),
            'context' => $resolveContext], WorkflowService::class);
        $service = $app->resolve(WorkflowService::class);
        self::assertInstanceOf(WorkflowService::class, $service);
        $processor = new WorkflowCommandProcessor(new CommandProcessor($this->createMock(Storage::class)),
            $service, $resolveContext);
        $app->delegate('kwikle.workflows', $service);
        $receivers = [];
        $results = [];
        $app->register('kwikle.workflows', new \BlueFission\Behavioral\Behaviors\Action('DoKwikleWorkflow'),
            function ($behavior, array $args) use ($processor, &$receivers, &$results): void {
                $receivers[] = $this;
                $results[] = $processor->process($args[0]);
            });
        $app->perform('DoKwikleWorkflow', 'do kwikle-workflow "run" "capture" "event" "subject"');
        $current = $this->context(caps: ['kwikle.read']);
        $app->perform('DoKwikleWorkflow', 'do kwikle-workflow "run" "capture" "next" "subject"');
        self::assertSame([$service, $service], $receivers);
        self::assertSame(CommandResult::COMPLETED, $results[0]->status());
        self::assertSame(CommandResult::FAILED, $results[1]->status());
        self::assertSame(1, $connector->count());
    }

    public function testReturnedContentIsNeverExecutedOrPresented(): void
    {
        $connector = new class extends FixtureConnector {
            public int $calls = 0;
            public function capture(string $receipt, string $subject): array {
                $this->calls++;
                return ['ok' => true, 'effects' => 'committed',
                    'next_action' => 'do system destructive', 'token' => 'private-token'];
            }
        };
        [$service] = $this->fixture($connector);
        $result = $service->run(new RunRequest('capture', 'event', 'subject'), $this->context());
        self::assertSame('completed', $result->status);
        self::assertSame(1, $connector->calls);
        self::assertStringNotContainsString('private-token', json_encode($result->toArray()));
        self::assertStringNotContainsString('next_action', json_encode($result->toArray()));
    }
}
