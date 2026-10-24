<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\{ExtensionPointCatalog, LazyAgentCommandProcessor};
use BlueFission\DevElation;
use BlueFission\Services\Application;
use BlueFission\Wise\Cmd\{Command, CommandRequest, CommandResult, ICommandProcessor};
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RuntimeInitializationIsolationTest extends TestCase
{
    public static function targets(): array
    {
        return ['same instance' => [false], 'other instance' => [true]];
    }

    private function inner(): ICommandProcessor
    {
        return new class implements ICommandProcessor {
            public array $requests = [];

            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $this->requests[] = $request;
                return CommandResult::completed('fixture');
            }
        };
    }

    private function assertNestedDenied(array $result): void
    {
        self::assertSame(CommandResult::INVALID, $result['status']);
        self::assertSame(['agent_command_runtime_reentry_denied'], $result['diagnostics']);
        self::assertSame('deny', $result['metadata']['agent_decision']);
        self::assertSame('command_runtime_reentry_denied', $result['metadata']['agent_reason']);
        self::assertFalse($result['confirmation_required']);
        self::assertNull($result['continuation_token']);
    }

    /** @dataProvider targets */
    public function testFactoryCannotConstructOrExecuteANestedRuntime(bool $otherInstance): void
    {
        $inner = $this->inner();
        $otherInner = $this->inner();
        $builds = 0;
        $otherBuilds = 0;
        $nested = null;
        $runtime = null;
        $other = new LazyAgentCommandProcessor(static function () use (&$otherBuilds, $otherInner): ICommandProcessor {
            $otherBuilds++;
            return $otherInner;
        });
        $runtime = new LazyAgentCommandProcessor(
            static function () use (&$runtime, &$builds, &$nested, $other, $otherInstance, $inner): ICommandProcessor {
                $builds++;
                // Bound the failing-before probe; never allow uncontrolled recursion.
                if ($builds === 1) {
                    $nested = ($otherInstance ? $other : $runtime)->process('nested');
                }
                return $inner;
            }
        );

        $request = new CommandRequest('outer', context: ['correlation_id' => 'fixture-request']);
        self::assertSame(CommandResult::COMPLETED, $runtime->process($request)->status());
        $this->assertNestedDenied($nested->toArray());
        self::assertSame(1, $builds);
        self::assertSame(0, $otherBuilds);
        self::assertTrue($inner->requests === [$request]);
        self::assertSame([], $otherInner->requests);
        self::assertSame(CommandResult::COMPLETED, $other->process('later')->status());
        self::assertSame(1, $otherBuilds);
    }

    /** @dataProvider targets */
    public function testReadyObserverCannotExecuteThroughACachedRuntime(bool $otherInstance): void
    {
        DevElation::up();
        $inner = $this->inner();
        $otherInner = $this->inner();
        $other = new LazyAgentCommandProcessor(static fn (): ICommandProcessor => $otherInner);
        $other->process('prime');
        $builds = 0;
        $application = new Application(['name' => 'runtime-initialization-fixture']);
        $application->bindArgs(['factory' => static function () use (&$builds, $inner): ICommandProcessor {
            $builds++;
            return $inner;
        }], LazyAgentCommandProcessor::class);
        $runtime = $application->resolve(LazyAgentCommandProcessor::class);
        self::assertInstanceOf(LazyAgentCommandProcessor::class, $runtime);
        self::assertSame(0, $builds, 'Native resolution must remain lazy.');
        $nested = null;
        $observations = 0;
        DevElation::action(ExtensionPointCatalog::AGENT_COMMAND_RUNTIME_READY,
            static function (array $payload) use (&$nested, &$observations, $runtime, $other, $otherInstance): void {
                $observations++;
                if ($observations === 1) {
                    $nested = ($otherInstance ? $other : $runtime)->process('nested');
                }
            });

        self::assertSame(CommandResult::COMPLETED, $runtime->process('outer')->status());
        $this->assertNestedDenied($nested->toArray());
        self::assertSame(1, $observations);
        self::assertSame(1, $builds);
        self::assertSame(['outer'], $inner->requests);
        self::assertSame(['prime'], $otherInner->requests);
        self::assertSame(CommandResult::COMPLETED, $runtime->process('later')->status());
        self::assertSame(['outer', 'later'], $inner->requests);
    }

    /** @dataProvider targets */
    public function testUnavailableObservationCannotRetryButLaterExplicitRecoveryCan(bool $otherInstance): void
    {
        DevElation::up();
        $inner = $this->inner();
        $otherInner = $this->inner();
        $other = new LazyAgentCommandProcessor(static fn (): ICommandProcessor => $otherInner);
        $other->process('prime');
        $available = false;
        $builds = 0;
        $runtime = new LazyAgentCommandProcessor(
            static function () use (&$builds, &$available, $inner): ICommandProcessor {
                $builds++;
                if (!$available) { throw new RuntimeException('private-provider-detail'); }
                return $inner;
            }
        );
        $nested = null;
        $observations = 0;
        DevElation::action(ExtensionPointCatalog::AGENT_COMMAND_RUNTIME_UNAVAILABLE,
            static function (array $payload) use (&$nested, &$observations, $runtime, $other, $otherInstance): void {
                $observations++;
                if ($observations === 1) {
                    $nested = ($otherInstance ? $other : $runtime)->process('nested');
                }
            });

        $result = $runtime->process('outer');
        self::assertSame(CommandResult::INVALID, $result->status());
        self::assertSame(['agent_command_runtime_unavailable'], $result->diagnostics());
        $this->assertNestedDenied($nested->toArray());
        self::assertSame(1, $builds);
        self::assertSame(1, $observations);
        self::assertSame([], $inner->requests);
        self::assertSame(['prime'], $otherInner->requests);
        self::assertStringNotContainsString('private-provider-detail', json_encode($result->toArray()));
        $available = true;
        self::assertSame(CommandResult::COMPLETED, $runtime->process('explicit-recovery')->status());
        self::assertSame(2, $builds);
        self::assertSame(['explicit-recovery'], $inner->requests);
    }

    public function testNormalExecutionCanDelegateAfterPreparationHasFinished(): void
    {
        $inner = $this->inner();
        $other = new LazyAgentCommandProcessor(static fn (): ICommandProcessor => $inner);
        $delegator = new class($other) implements ICommandProcessor {
            public int $calls = 0;
            public function __construct(private ICommandProcessor $delegate) {}
            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $this->calls++;
                return $this->delegate->process($request);
            }
        };
        $runtime = new LazyAgentCommandProcessor(static fn (): ICommandProcessor => $delegator);
        $request = new CommandRequest('outer', context: ['correlation_id' => 'fixture-request']);

        self::assertSame(CommandResult::COMPLETED, $runtime->process($request)->status());
        self::assertSame(1, $delegator->calls);
        self::assertTrue($inner->requests === [$request]);
    }
}
