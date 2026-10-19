<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Console\CliManager;
use App\Business\Presentation\CommandResultPresenter;
use App\Business\Services\CommandHost;
use App\Business\Services\ExtensionPointCatalog;
use BlueFission\DevElation;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CommandResultObservationTest extends TestCase
{
    private mixed $originalActive;
    private mixed $originalActions;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(DevElation::class);
        $this->originalActive = $reflection->getProperty('_isActive')->getValue();
        $this->originalActions = $reflection->getProperty('_actions')->getValue();
        $reflection->getProperty('_actions')->setValue(null, []);
        DevElation::up();
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(DevElation::class);
        $reflection->getProperty('_isActive')->setValue(null, $this->originalActive);
        $reflection->getProperty('_actions')->setValue(null, $this->originalActions);
    }

    public function testDeliveryFollowsProcessingAndPresentationInPriorityOrder(): void
    {
        $events = [];
        $output = new class($events) implements \Stringable {
            public function __construct(private array &$events) {}
            public function __toString(): string { $this->events[] = 'presented'; return 'private-output'; }
        };
        $processor = $this->processor(new CommandResult('completed', $output, metadata: ['secret' => 'private-meta']));
        $host = new CommandHost($processor, new CommandResultPresenter());
        DevElation::action(ExtensionPointCatalog::COMMAND_RESULT, function (array $summary) use (&$events, $processor): array {
            $this->assertSame(1, $processor->calls);
            $this->assertSame(['presented'], $events);
            $this->assertSame(['version' => 1, 'status' => 'completed', 'confirmation_required' => false,
                'host_failure' => false, 'phase' => 'presented'], $summary);
            $events[] = 'first';
            $summary['status'] = 'failed';
            return $summary;
        }, 1);
        DevElation::action(ExtensionPointCatalog::COMMAND_RESULT, function (array $summary) use (&$events): void {
            $this->assertSame('completed', $summary['status']);
            $events[] = 'second';
        }, 5);
        DevElation::action(ExtensionPointCatalog::COMMAND_RESULT, function () use (&$events): void { $events[] = 'third'; }, 5);
        $result = $host->execute('private-input', ['tenant_id' => 'private-tenant']);
        $this->assertSame(['presented', 'first', 'second', 'third'], $events);
        $this->assertSame('completed', $result->status());
        $this->assertSame('private-output', $result->outputText());
        $this->assertSame(['secret' => 'private-meta'], $result->metadata());
    }

    public function testCliAndContinuationObserveOnlyTheBoundedSummary(): void
    {
        $summaries = [];
        DevElation::action(ExtensionPointCatalog::COMMAND_RESULT, function (array $summary) use (&$summaries): void { $summaries[] = $summary; });
        $processor = $this->processor(new CommandResult('confirmation_required', 'private-output',
            confirmationRequired: true, diagnostics: ['private-diagnostic'], metadata: ['private' => 'private-meta'],
            continuationToken: 'private-token'));
        $host = new CommandHost($processor, new CommandResultPresenter());
        $direct = $host->execute('private-input');
        $cli = (new CliManager($host))->execute('private-input');
        $resumed = $host->resume('private-token', true, ['actor' => ['id' => 'private-actor']]);
        $this->assertSame(3, $processor->calls);
        $this->assertCount(3, $summaries);
        $this->assertSame($summaries[0], $summaries[1]);
        $this->assertSame($summaries[0], $summaries[2]);
        $this->assertTrue($summaries[0]['confirmation_required']);
        $this->assertStringNotContainsString('private', json_encode($summaries));
        $this->assertSame($direct->toArray(), $cli->toArray());
        $this->assertSame($direct->toArray(), $resumed->toArray());
        $this->assertSame('private-token', $direct->continuationToken());
    }

    public function testUnknownStatusCannotLeakProviderTextIntoObservation(): void
    {
        $summaries = [];
        DevElation::action(ExtensionPointCatalog::COMMAND_RESULT, function (array $summary) use (&$summaries): void { $summaries[] = $summary; });
        $host = new CommandHost($this->processor(new CommandResult('private-status', 'private-output')), new CommandResultPresenter());
        $this->assertSame('private-status', $host->execute('operate')->status());
        $this->assertSame('unknown', $summaries[0]['status']);
        $this->assertStringNotContainsString('private', json_encode($summaries));
    }

    public static function hostFailures(): array
    {
        return [['processing'], ['presentation']];
    }

    /** @dataProvider hostFailures */
    public function testHostFailureIsObservedWithoutRawErrorsOrRetry(string $stage): void
    {
        $summaries = [];
        DevElation::action(ExtensionPointCatalog::COMMAND_RESULT, function (array $summary) use (&$summaries): void { $summaries[] = $summary; });
        $output = new class implements \Stringable {
            public function __toString(): string { throw new \Error('private-error'); }
        };
        $processor = $this->processor($stage === 'processing' ? new \RuntimeException('private-error') : CommandResult::completed($output));
        $host = new CommandHost($processor, new CommandResultPresenter());
        $result = $host->execute('operate');
        $this->assertTrue($result->failedAtHost());
        $this->assertSame(1, $processor->calls);
        $this->assertSame([['version' => 1, 'status' => 'failed', 'confirmation_required' => false,
            'host_failure' => true, 'phase' => $stage . '_failed']], $summaries);
    }

    public function testObserverFailureCannotReplaceResultAndGuardAlwaysResets(): void
    {
        $calls = 0;
        $lateCalls = 0;
        DevElation::action(ExtensionPointCatalog::COMMAND_RESULT, function () use (&$calls): void { ++$calls; throw new \Error('private-observer-error'); }, 1);
        DevElation::action(ExtensionPointCatalog::COMMAND_RESULT, function () use (&$lateCalls): void { ++$lateCalls; }, 2);
        $processor = $this->processor(CommandResult::completed('ready'));
        $host = new CommandHost($processor, new CommandResultPresenter());
        $this->assertSame('ready', $host->execute('first')->output());
        $this->assertSame('ready', $host->execute('second')->output());
        $this->assertSame(2, $calls);
        $this->assertSame(2, $processor->calls);
        $this->assertSame(0, $lateCalls);
    }

    public function testObserverCannotReenterAnyCommandHostOrConsumeAContinuation(): void
    {
        $processor = $this->processor(CommandResult::completed('ready'));
        $host = new CommandHost($processor, new CommandResultPresenter());
        $otherHost = new CommandHost($processor, new CommandResultPresenter());
        $calls = 0;
        $nested = [];
        DevElation::action(ExtensionPointCatalog::COMMAND_RESULT, function () use (&$calls, &$nested, $host, $otherHost): void {
            ++$calls;
            $nested[] = $host->execute('nested');
            $nested[] = $otherHost->resume('private-token', true);
        });
        $this->assertSame('ready', $host->execute('first')->output());
        $this->assertSame(1, $processor->calls);
        $this->assertSame(1, $calls);
        $this->assertCount(2, $nested);
        foreach ($nested as $denied) {
            $this->assertSame('failed', $denied->status());
            $this->assertSame(['command_result_observer_reentry_denied'], $denied->diagnostics());
            $this->assertSame(['effects' => 'none'], $denied->metadata());
        }
        $this->assertSame('ready', $otherHost->execute('later')->output());
        $this->assertSame(2, $processor->calls);
    }

    public function testDisabledHooksAndAbsentHandlersPreserveExistingResults(): void
    {
        $processor = $this->processor(CommandResult::completed('ready'));
        $host = new CommandHost($processor, new CommandResultPresenter());
        $withoutHandlers = $host->execute('first');
        $called = false;
        DevElation::action(ExtensionPointCatalog::COMMAND_RESULT, function () use (&$called): void { $called = true; });
        DevElation::down();
        $disabled = $host->execute('second');
        $this->assertFalse($called);
        $this->assertSame($withoutHandlers->toArray(), $disabled->toArray());
        $this->assertSame(2, $processor->calls);
    }

    private function processor(CommandResult|\Throwable $result): ICommandProcessor
    {
        return new class($result) implements ICommandProcessor {
            public int $calls = 0;
            public function __construct(private CommandResult|\Throwable $result) {}
            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                ++$this->calls;
                if ($this->result instanceof \Throwable) { throw $this->result; }
                return $this->result;
            }
        };
    }
}
