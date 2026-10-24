<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Console;

use App\Business\Console\CliManager;
use App\Business\Presentation\CommandResultPresenter;
use App\Business\Services\AgentCommandContextProvider;
use App\Business\Services\CommandHost;
use BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery;
use BlueFission\Ref;
use BlueFission\Services\Application;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CliInputTest extends TestCase
{
    public static function completeLines(): array
    {
        return [
            ['\"quoted command\"', "\n"], [str_repeat('x', 65536), "\n"],
            [str_repeat('x', 65536), "\r\n"], [str_repeat('x', 65536), ''],
            [str_repeat('é', 32768), "\n"],
        ];
    }

    /** @dataProvider completeLines */
    public function testOneCompleteLineIsForwardedOnce(string $command, string $ending): void
    {
        [$cli, $processor] = $this->manager();
        [$exit, $out, $error] = $this->runLoop($cli, $command . $ending);
        self::assertSame(0, $exit);
        self::assertSame('', $error);
        self::assertStringContainsString('handled', $out);
        self::assertCount(1, $processor->requests);
        self::assertSame($command, $processor->requests[0]->input());
    }

    public static function oversizedLines(): array
    {
        return [[str_repeat('x', 65537)], [str_repeat(' ', 65537)],
            [str_repeat('é', 32769)], [str_repeat('x', 131072)]];
    }

    /** @dataProvider oversizedLines */
    public function testOversizedInputNeverExecutesPrefixOrRemainder(string $command): void
    {
        [$cli, $processor] = $this->manager();
        [$exit, , $error] = $this->runLoop($cli, $command . "\nnext\n");
        self::assertSame(2, $exit);
        self::assertSame("cli_input_too_long\n", $error);
        self::assertSame([], $processor->requests);
    }

    public static function confirmations(): array
    {
        return [["y\n", true], [" YES \r\n", true], ["no\n", false], ["\n", false],
            ["yes             not-a-command\n", false], [".\n", false], ['yes', true], ['', false],
            ['y' . str_repeat(' ', 127) . "\r\n", true]];
    }

    /** @dataProvider confirmations */
    public function testConfirmationConsumesExactlyOneWholeAnswer(string $answer, bool $approved): void
    {
        [$cli, $processor, $query] = $this->manager(static fn (CommandRequest $request): CommandResult =>
            $request->isContinuation() ? CommandResult::completed('settled')
                : CommandResult::pending('review', null, 'private-token'));
        [$exit, , $error] = $this->runLoop($cli, "confirm\n" . $answer);
        self::assertSame(0, $exit);
        self::assertSame('', $error);
        self::assertCount(2, $processor->requests);
        $continuation = $processor->requests[1];
        self::assertTrue($continuation->isContinuation());
        self::assertSame('private-token', $continuation->continuationToken());
        self::assertSame($approved, $continuation->approved());
        self::assertSame(2, $query->reads, 'Refresh host context for continuation.');
        self::assertSame(['id' => 'cli'], $continuation->context()['actor']);
        self::assertSame([], $continuation->context()['capabilities']);
    }

    public function testOversizedConfirmationCancelsOnceThenStops(): void
    {
        [$cli, $processor] = $this->manager(static fn (CommandRequest $request): CommandResult =>
            $request->isContinuation() ? CommandResult::completed('cancelled')
                : CommandResult::pending('review', null, 'private-token'));
        [$exit, $out, $error] = $this->runLoop($cli, "confirm\n" . 'yes' . str_repeat(' ', 126) . "\nnext\n");
        self::assertSame(2, $exit);
        self::assertSame("cli_input_too_long\n", $error);
        self::assertCount(2, $processor->requests);
        self::assertFalse($processor->requests[1]->approved());
        self::assertStringNotContainsString('private-token', $out . $error);
    }

    public function testBlankLinesAndStopSentinelAreNotCommands(): void
    {
        [$cli, $processor] = $this->manager();
        [$exit] = $this->runLoop($cli, "\n \r\nfirst\n.\nignored\n");
        self::assertSame(0, $exit);
        self::assertCount(1, $processor->requests);
        self::assertSame('first', $processor->requests[0]->input());
    }

    public function testMissingTokenCannotReadOrApproveAnotherCommand(): void
    {
        [$cli, $processor] = $this->manager(static fn (): CommandResult =>
            new CommandResult(CommandResult::CONFIRMATION_REQUIRED, 'review', confirmationRequired: true));
        [$exit, , $error] = $this->runLoop($cli, "confirm\nyes\nnext\n");
        self::assertSame(2, $exit);
        self::assertSame("cli_confirmation_unavailable\n", $error);
        self::assertCount(1, $processor->requests);
    }

    public function testFailedConfirmationReadCancelsOnceWithoutLeakingTheError(): void
    {
        [$cli, $processor] = $this->manager(static fn (CommandRequest $request): CommandResult =>
            $request->isContinuation() ? CommandResult::completed('cancelled')
                : CommandResult::pending('review', null, 'private-token'));
        [$exit, $out, $error] = $this->runLoop($cli, "confirm\nyes\nnext\n", static fn ($stream): Ref =>
            new class($stream) extends Ref {
                private int $reads = 0;
                public function line(?int $length = null): string|false
                {
                    if (++$this->reads === 2) { throw new RuntimeException('private-io-error'); }
                    return parent::line($length);
                }
            });
        self::assertSame(1, $exit);
        self::assertSame("cli_stream_failed\n", $error);
        self::assertCount(2, $processor->requests);
        self::assertFalse($processor->requests[1]->approved());
        self::assertStringNotContainsString('private-', $out . $error);
    }

    public function testFailedOutputStopsBeforeDispatch(): void
    {
        [$cli, $processor] = $this->manager();
        [$exit, , $error] = $this->runLoop($cli, "first\n", null, static fn ($stream): Ref =>
            new class($stream) extends Ref {
                public function write(mixed $data): int|bool { return 0; }
            });
        self::assertSame(1, $exit);
        self::assertSame("cli_stream_failed\n", $error);
        self::assertSame([], $processor->requests);
    }

    public function testIncompleteNonblockingReadNeverExecutesAPrefix(): void
    {
        [$cli, $processor] = $this->manager();
        [$exit, , $error] = $this->runLoop($cli, 'partial', static fn ($stream): Ref =>
            new class($stream) extends Ref {
                public function eof(): bool { return false; }
            });
        self::assertSame(1, $exit);
        self::assertSame("cli_stream_failed\n", $error);
        self::assertSame([], $processor->requests);
    }

    public function testUnsettledContinuationCannotFallThroughToNewCommands(): void
    {
        [$cli, $processor] = $this->manager(static fn (): CommandResult =>
            CommandResult::pending('review', null, 'private-token'));
        [$exit, , $error] = $this->runLoop($cli, "confirm\nyes\nnext\n");
        self::assertSame(2, $exit);
        self::assertSame("cli_confirmation_unresolved\n", $error);
        self::assertCount(2, $processor->requests);
    }

    public function testUnknownEffectHostFailureStopsWithoutReplay(): void
    {
        $effects = 0;
        [$cli, $processor] = $this->manager(static function () use (&$effects): CommandResult {
            $effects++;
            throw new RuntimeException('private-error');
        });
        [$exit, $out, $error] = $this->runLoop($cli, "first\nsecond\n");
        self::assertSame(1, $exit);
        self::assertSame(1, $effects);
        self::assertCount(1, $processor->requests);
        self::assertStringContainsString('command_host_processing_failed', $error);
        self::assertStringNotContainsString('private-error', $out . $error);
    }

    public function testOrdinaryFailurePreservesLastResultSemantics(): void
    {
        [$cli, $processor] = $this->manager(static fn (): CommandResult =>
            new CommandResult(CommandResult::FAILED, 'failed', exitCode: 7, diagnostics: ['known_failure']));
        [$exit, $out, $error] = $this->runLoop($cli, "first\nsecond\n");
        self::assertSame(7, $exit);
        self::assertCount(2, $processor->requests);
        self::assertStringContainsString('failed', $out);
        self::assertSame('known_failure' . PHP_EOL . 'known_failure' . PHP_EOL, $error);
    }

    public function testNativeServiceResolutionUsesTheSameLoop(): void
    {
        [, $processor, $query] = $this->manager();
        $application = new Application(['name' => 'cli-stream-fixture']);
        $application->bindArgs([
            'commandHost' => new CommandHost($processor, new CommandResultPresenter()),
            'contextProvider' => new AgentCommandContextProvider($query),
        ], CliManager::class);
        $application->delegate('cmd', CliManager::class);
        $cli = $application->service('cmd');
        self::assertInstanceOf(CliManager::class, $cli);
        self::assertSame([], $processor->requests);
        [$exit] = $this->runLoop($cli, "native\n");
        self::assertSame(0, $exit);
        self::assertCount(1, $processor->requests);
    }

    private function manager(?callable $response = null): array
    {
        $processor = new class($response) implements ICommandProcessor {
            public array $requests = [];
            public function __construct(private mixed $response) {}
            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $this->requests[] = $request;
                return $this->response ? ($this->response)($request) : CommandResult::completed('handled');
            }
        };
        $query = new class implements IActivatedAddOnsQuery {
            public int $reads = 0;
            public function fetch(): array { $this->reads++; return []; }
        };
        return [new CliManager(new CommandHost($processor, new CommandResultPresenter()),
            new AgentCommandContextProvider($query)), $processor, $query];
    }

    private function runLoop(CliManager $cli, string $text, ?callable $inputRef = null, ?callable $outputRef = null): array
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'r+');
        $error = fopen('php://temp', 'r+');
        try {
            fwrite($input, $text);
            rewind($input);
            $exit = $cli->interact($inputRef ? $inputRef($input) : Ref::resource($input),
                $outputRef ? $outputRef($output) : Ref::resource($output), Ref::resource($error));
            foreach ([$input, $output, $error] as $stream) {
                self::assertTrue(is_resource($stream), 'Do not close caller-owned streams.');
            }
            rewind($output);
            rewind($error);
            return [$exit, stream_get_contents($output), stream_get_contents($error)];
        } finally {
            fclose($input);
            fclose($output);
            fclose($error);
        }
    }
}
