<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCommandContextProvider;
use App\Business\Services\LazyAgentCommandProcessor;
use BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LazyAgentCommandProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(ICommandProcessor::class)
            || !class_exists(CommandRequest::class)
            || !class_exists(CommandResult::class)
        ) {
            $this->markTestSkipped('The optional typed Wise command runtime is unavailable.');
        }
    }

    public function testCommandRuntimeIsBuiltOnlyWhenFirstInvoked(): void
    {
        $builds = 0;
        $inner = new class implements ICommandProcessor {
            public int $calls = 0;

            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $this->calls++;

                return CommandResult::completed(['ok' => true]);
            }
        };
        $processor = new LazyAgentCommandProcessor(
            static function () use (&$builds, $inner): ICommandProcessor {
                $builds++;

                return $inner;
            }
        );

        $this->assertSame(0, $builds);
        $this->assertSame(CommandResult::COMPLETED, $processor->process('list command')->status());
        $this->assertSame(CommandResult::COMPLETED, $processor->process('list command')->status());
        $this->assertSame(1, $builds);
        $this->assertSame(2, $inner->calls);
    }

    public function testDeferredRuntimeFailuresReturnAStableCommandResult(): void
    {
        $processor = new LazyAgentCommandProcessor(
            static fn (): ICommandProcessor => throw new RuntimeException('runtime failed')
        );

        $result = $processor->process('list command');

        $this->assertSame(CommandResult::INVALID, $result->status());
        $this->assertSame(['agent_command_runtime_unavailable'], $result->diagnostics());
        $this->assertSame('command_runtime_unavailable', $result->metadata()['agent_reason']);
    }

    public function testExecutionFailuresAreNotMisreportedAsConstructionFailures(): void
    {
        $inner = new class implements ICommandProcessor {
            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                throw new RuntimeException('execution failed');
            }
        };
        $processor = new LazyAgentCommandProcessor(static fn (): ICommandProcessor => $inner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('execution failed');
        $processor->process('run command');
    }

    public function testActivatedAddOnQueryIsResolvedOnlyWhenContextIsRequested(): void
    {
        $resolutions = 0;
        $query = new class implements IActivatedAddOnsQuery {
            public function fetch(): array
            {
                return [['name' => 'reporting', 'is_active' => 1]];
            }
        };
        $provider = new AgentCommandContextProvider(
            queryResolver: static function () use (&$resolutions, $query): IActivatedAddOnsQuery {
                $resolutions++;

                return $query;
            }
        );

        $this->assertSame(0, $resolutions);
        $first = $provider->forActor('operator-a');
        $second = $provider->forActor('operator-b');

        $this->assertSame(1, $resolutions);
        $this->assertSame(['reporting'], $first['active_addons']);
        $this->assertSame(['reporting'], $second['active_addons']);
    }
}
