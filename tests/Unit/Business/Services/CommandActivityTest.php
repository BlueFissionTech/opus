<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Presentation\CommandResultPresenter;
use App\Business\Console\CliManager;
use App\Business\Services\CommandActivityService;
use App\Business\Services\CommandHost;
use App\Business\Services\SQLiteActivityStore;
use App\Domain\Activity\ActivityEvent;
use App\Domain\Activity\ActivityScope;
use App\Domain\Activity\IActivityStore;
use BlueFission\Connections\Database\SQLiteLink;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use PHPUnit\Framework\TestCase;

final class CommandActivityTest extends TestCase
{
    private string $path;
    private SQLiteLink $link;
    private SQLiteActivityStore $store;

    protected function setUp(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('SQLite3 is required for the optional activity store.');
        }
        $this->path = tempnam(sys_get_temp_dir(), 'opus-activity-');
        $this->link = new SQLiteLink(['database' => $this->path]);
        $this->link->open();
        $this->store = new SQLiteActivityStore($this->link);
        $this->store->initialize();
    }

    protected function tearDown(): void
    {
        if (isset($this->link)) {
            $this->link->close();
        }
        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testApprovalFlowSurvivesReopenWithoutPersistingPrivatePayloads(): void
    {
        $processor = $this->processor();
        $activity = new CommandActivityService($this->store, 'app-a');
        $host = new CommandHost($processor, new CommandResultPresenter(), $activity);
        $context = $this->context();
        $pending = $host->execute('synthetic-private-input', $context);
        $this->assertTrue($pending->confirmationRequired());
        $completed = $host->resume('synthetic-private-token', true, $context);
        $this->assertSame(2, $processor->calls);
        $this->link->close();
        $this->link->open();
        $history = $host->history($context);
        $this->assertSame(['requested', 'approval_requested', 'approval_submitted', 'outcome'], array_column($history, 'phase'));
        $this->assertCount(1, array_unique(array_column($history, 'correlation_id')));
        $this->assertSame($history[1]['event_id'], $history[2]['causation_id']);
        $this->assertSame($history[2]['event_id'], $history[3]['causation_id']);
        $this->assertSame('completed', $history[3]['status']);
        $this->assertSame($history[1]['event_id'], $pending->activityReceipt()['event_id']);
        $this->assertSame($history[3]['event_id'], $completed->activityReceipt()['event_id']);
        $this->assertSame($history[3]['correlation_id'], $completed->activityReceipt()['correlation_id']);
        $this->assertSame('unknown', $history[3]['effects']);
        $this->assertStringNotContainsString('synthetic-private', json_encode($history));
        $this->assertStringNotContainsString('synthetic-private', file_get_contents($this->path));
        $this->assertSame(2, $processor->calls, 'Reading history cannot replay execution.');
        $this->assertSame([$history[2], $history[3]], $host->history($context, $history[1]['sequence'], 2));
    }

    public function testScopeAndFiltersCannotRevealAnotherActorTenantOrApplication(): void
    {
        $processor = $this->processor();
        $host = new CommandHost($processor, new CommandResultPresenter(), new CommandActivityService($this->store, 'app-a'));
        $host->execute('private', $this->context());
        $own = $host->history($this->context());
        foreach ([$this->context('other'), $this->context('operator', 'other'), $this->context('operator', null)] as $context) {
            $this->assertSame([], $host->history($context, 0, 100, $own[0]['correlation_id']));
        }
        $other = new CommandHost($processor, new CommandResultPresenter(), new CommandActivityService($this->store, 'app-b'));
        $this->assertSame([], $other->history($this->context()));
        $host->resume('synthetic-private-token', false, $this->context('other'));
        $unrelated = $host->history($this->context('other'));
        $this->assertNotSame($own[0]['correlation_id'], $unrelated[0]['correlation_id']);
        $this->assertNull($unrelated[0]['causation_id']);
        $this->assertSame('rejection_submitted', $unrelated[0]['phase']);
    }

    public function testInterleavedScopesDoNotExposeGlobalInsertionCounts(): void
    {
        $host = new CommandHost($this->processor(), new CommandResultPresenter(), new CommandActivityService($this->store, 'app'));
        $host->execute('operate', $this->context());
        $host->execute('operate', $this->context('other'));
        $host->execute('operate', $this->context('operator', 'other'));
        $host->resume('synthetic-private-token', true, $this->context());
        $this->assertSame([1, 2, 3, 4], array_column($host->history($this->context()), 'sequence'));
        $this->assertSame([1, 2], array_column($host->history($this->context('other')), 'sequence'));
        $this->assertSame([3, 4], array_column($host->history($this->context(), 2), 'sequence'));
    }

    public function testReadProjectionRechecksScopeEvenForFaultyStore(): void
    {
        $foreign = ActivityEvent::create(new ActivityScope('app', 'foreign', 'actor'), 'requested', 'command.execute');
        $store = $this->decorator();
        $store->foreign = $foreign;
        $activity = new CommandActivityService($store, 'app');
        $this->assertSame([], $activity->history($this->context()));
    }

    public function testMissingActorAndFailedInitialAppendPreventDispatch(): void
    {
        $processor = $this->processor();
        $store = $this->decorator();
        $store->failAt = 1;
        $host = new CommandHost($processor, new CommandResultPresenter(), new CommandActivityService($store, 'app'));
        $this->assertTrue($host->execute('operate', [])->failedAtHost());
        $this->assertSame(0, $processor->calls);
        $result = $host->execute('operate', $this->context());
        $this->assertSame(['command_host_activity_start_failed'], $result->diagnostics());
        $this->assertSame(0, $processor->calls);
        $this->assertStringNotContainsString('synthetic-private', json_encode($result->toArray()));
    }

    public function testFailedCompletionAppendNeverReplaysAndLeavesAnIncompleteReceipt(): void
    {
        $processor = $this->processor();
        $store = $this->decorator();
        $store->failAt = 2;
        $host = new CommandHost($processor, new CommandResultPresenter(), new CommandActivityService($store, 'app'));
        $result = $host->execute('operate', $this->context());
        $this->assertSame(['command_host_activity_recording_failed'], $result->diagnostics());
        $this->assertSame('unknown', $result->metadata()['effects']);
        $this->assertNull($result->continuationToken());
        $this->assertSame(1, $processor->calls);
        $this->assertCount(1, $host->history($this->context()));
        $this->assertTrue($result->failedAtHost());
        $this->assertSame('requested', $result->activityReceipt()['recorded_phase']);
    }

    public function testProcessorExceptionHasAHostFailureOutcomeAndNoSecret(): void
    {
        $processor = $this->processor();
        $processor->throw = true;
        $host = new CommandHost($processor, new CommandResultPresenter(), new CommandActivityService($this->store, 'app'));
        $result = $host->execute('operate', $this->context());
        $history = $host->history($this->context());
        $this->assertTrue($result->failedAtHost());
        $this->assertSame('failed', $history[1]['status']);
        $this->assertSame(['command_host_failure'], $history[1]['reason_codes']);
        $this->assertSame(1, $processor->calls);
        $this->assertStringNotContainsString('synthetic-private', json_encode($history));
    }

    public function testSqlValuesAreBoundAndDuplicateEventsAreRejected(): void
    {
        $scope = new ActivityScope("app'; DROP TABLE opus_activity; --", null, "actor' OR 1=1 --");
        $event = ActivityEvent::create($scope, 'requested', 'command.execute');
        $this->store->append($event);
        $this->assertCount(1, $this->store->read($scope));
        $this->expectException(\RuntimeException::class);
        $this->store->append($event);
    }

    private function context(string $actor = 'operator', ?string $tenant = 'tenant-a'): array
    {
        return ['actor' => ['id' => $actor], 'tenant_id' => $tenant, 'private' => 'synthetic-private-context'];
    }

    public function testRealDatabaseLockStopsDispatchAndRecoveryDoesNotReplay(): void
    {
        $processor = $this->processor();
        $host = new CommandHost($processor, new CommandResultPresenter(), new CommandActivityService($this->store, 'app'));
        $lock = new \SQLite3($this->path);
        try {
            $lock->exec('BEGIN EXCLUSIVE');
            $result = $host->execute('operate', $this->context());
            $this->assertSame(['command_host_activity_start_failed'], $result->diagnostics());
            $this->assertSame(0, $processor->calls);
            $lock->exec('ROLLBACK');
            $this->assertSame([], $host->history($this->context()));
            $this->assertSame(0, $processor->calls);
            $host->execute('operate', $this->context());
            $this->assertSame(1, $processor->calls);
        } finally {
            $lock->close();
        }
    }

    public function testCommitThenExceptionRemainsUncertainWithoutRepeatingTheCommand(): void
    {
        $processor = $this->processor();
        $store = $this->decorator();
        $store->throwAfterCommit = true;
        $host = new CommandHost($processor, new CommandResultPresenter(), new CommandActivityService($store, 'app'));
        $result = $host->execute('operate', $this->context());
        $this->assertSame('activity_recording', $result->metadata()['failure_stage']);
        $this->assertSame('requested', $result->activityReceipt()['recorded_phase']);
        $this->assertCount(2, $host->history($this->context()));
        $this->assertSame(1, $processor->calls);
    }

    public function testCliUsesTheSameActivityBoundaryAndProviderCannotForgeAReceipt(): void
    {
        $processor = $this->processor();
        $processor->metadata = ['activity_receipt' => ['event_id' => 'synthetic-private-forged']];
        $host = new CommandHost($processor, new CommandResultPresenter(), new CommandActivityService($this->store, 'app'));
        $cli = new CliManager($host);
        $result = $cli->execute('operate', $this->context());
        $history = $host->history($this->context());
        $this->assertSame($history[1]['event_id'], $result->toArray()['activity_receipt']['event_id']);
        $this->assertSame(1, $processor->calls);
        $withoutActivity = new CommandHost($processor, new CommandResultPresenter());
        $this->assertNull($withoutActivity->execute('operate', $this->context())->activityReceipt());
    }

    public function testContainerBindingExplicitlyEnablesTheConfiguredStore(): void
    {
        $instances = new \ReflectionProperty(\BlueFission\Services\Application::class, '_instances');
        $prior = $instances->getValue();
        try {
            $application = new \BlueFission\Services\Application(['name' => 'command-host-activity-test']);
            $application->bindArgs([
                'processor' => $this->processor(),
                'activity' => new CommandActivityService($this->store, 'app'),
            ], CommandHost::class);
            $host = $application->getDynamicInstance(CommandHost::class);
            $this->assertNotNull($host->execute('read', $this->context())->activityReceipt());
            $this->assertCount(2, $host->history($this->context()));
        } finally {
            $instances->setValue(null, $prior);
        }
    }

    public function testUnknownStatusesAndMalformedStoredDataDoNotBecomeClaims(): void
    {
        $processor = $this->processor();
        $processor->outcome = new CommandResult('synthetic-private-status', 'private');
        $host = new CommandHost($processor, new CommandResultPresenter(), new CommandActivityService($this->store, 'app'));
        $host->execute('operate', $this->context());
        $history = $host->history($this->context());
        $this->assertSame('unknown', $history[1]['status']);
        $this->assertSame('unknown', $history[1]['mechanism']);
        $this->assertStringNotContainsString('synthetic-private', json_encode($history));
        $this->link->query("UPDATE opus_activity SET receipt = '{invalid}'");
        $this->expectException(\RuntimeException::class);
        $host->history($this->context());
    }

    public function testUnencodableIdentityCannotDispatchOrBecomeAnEmptyReceipt(): void
    {
        $processor = $this->processor();
        $host = new CommandHost($processor, new CommandResultPresenter(), new CommandActivityService($this->store, 'app'));
        $result = $host->execute('operate', $this->context("invalid\xFF"));
        $this->assertSame(['command_host_activity_start_failed'], $result->diagnostics());
        $this->assertSame(0, $processor->calls);
    }

    public static function invalidPages(): array
    {
        return [[-1, 1], [0, 0], [0, 501]];
    }

    public function testApprovalCredentialDoesNotEnterGenericValueFilters(): void
    {
        $activity = new CommandActivityService($this->store, 'app');
        $request = new CommandRequest('private', context: $this->context());
        $resume = CommandRequest::resume('synthetic-private-token', true, $this->context());
        $pending = (new CommandResultPresenter())->present(CommandResult::pending(null, null, 'synthetic-private-token'));
        $filters = new \ReflectionProperty(\BlueFission\DevElation::class, '_filters');
        $active = new \ReflectionProperty(\BlueFission\DevElation::class, '_isActive');
        $priorFilters = $filters->getValue();
        $priorActive = $active->getValue();
        $observed = [];
        try {
            \BlueFission\DevElation::up();
            \BlueFission\DevElation::filter('_in', static function ($value) use (&$observed) {
                $observed[] = $value;
                return $value;
            });
            $receipt = $activity->begin($request);
            $activity->finish($receipt, $pending);
            $continued = $activity->begin($resume);
            $this->assertSame($receipt->correlationId, $continued->correlationId);
            $this->assertNotContains('synthetic-private-token', $observed);
        } finally {
            $filters->setValue(null, $priorFilters);
            $active->setValue(null, $priorActive);
        }
    }

    public function testPersistedBooleanClaimsRequireNativeBooleanValues(): void
    {
        $data = ActivityEvent::create(new ActivityScope('app', null, 'actor'), 'requested', 'command.execute')->toArray();
        $data['host_failure'] = 'false';
        $this->expectException(\InvalidArgumentException::class);
        ActivityEvent::fromArray($data);
    }

    /** @dataProvider invalidPages */
    public function testReadBoundsAreEnforcedBeforeStoreAccess(int $after, int $limit): void
    {
        $activity = new CommandActivityService($this->store, 'app');
        $this->expectException(\InvalidArgumentException::class);
        $activity->history($this->context(), $after, $limit);
    }

    private function processor(): object
    {
        return new class implements ICommandProcessor {
            public int $calls = 0;
            public bool $throw = false;
            public array $metadata = [];
            public ?CommandResult $outcome = null;
            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                ++$this->calls;
                if ($this->throw) { throw new \RuntimeException('synthetic-private-exception'); }
                if ($this->outcome !== null) { return $this->outcome; }
                return $request->isContinuation()
                    ? CommandResult::completed('synthetic-private-output', metadata: ['secret' => 'synthetic-private-metadata'])
                    : CommandResult::pending('synthetic-private-output', null, 'synthetic-private-token', $this->metadata);
            }
        };
    }

    private function decorator(): object
    {
        return new class($this->store) implements IActivityStore {
            public int $calls = 0;
            public int $failAt = 0;
            public bool $throwAfterCommit = false;
            public ?ActivityEvent $foreign = null;
            public function __construct(private IActivityStore $store) {}
            public function append(ActivityEvent $event, ?string $approvalReference = null): void
            {
                if (++$this->calls === $this->failAt) { throw new \RuntimeException('synthetic-private-storage'); }
                $this->store->append($event, $approvalReference);
                if ($this->throwAfterCommit && $this->calls === 2) {
                    throw new \RuntimeException('synthetic-private-committed-error');
                }
            }
            public function approval(ActivityScope $scope, string $reference): ?ActivityEvent
            {
                return $this->store->approval($scope, $reference);
            }
            public function read(ActivityScope $scope, int $after = 0, int $limit = 100, ?string $correlation = null): array
            {
                return $this->foreign ? [['sequence' => 1, 'event' => $this->foreign]] : $this->store->read($scope, $after, $limit, $correlation);
            }
        };
    }
}
