<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Console\CliManager;
use App\Business\Presentation\CommandResultPresenter;
use App\Business\Services\AgentCommandContextProvider;
use App\Business\Services\CommandHost;
use BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use PHPUnit\Framework\TestCase;

final class CommandHostTest extends TestCase
{
    public function testContainerConstructionDoesNotEnableOptionalActivity(): void
    {
        $instances = new \ReflectionProperty(\BlueFission\Services\Application::class, '_instances');
        $prior = $instances->getValue();
        try {
            $application = new \BlueFission\Services\Application(['name' => 'command-host-default-test']);
            $processor = new class implements ICommandProcessor {
                public function process(CommandRequest|Command|array|string $request): CommandResult
                {
                    return CommandResult::completed('available');
                }
            };
            $application->bindArgs(['processor' => $processor], CommandHost::class);
            $host = $application->getDynamicInstance(CommandHost::class);
            $result = $host->execute('read');
            $this->assertSame('available', $result->output());
            $this->assertNull($result->activityReceipt());
        } finally {
            $instances->setValue(null, $prior);
        }
    }

    public static function exceptionalResults(): array
    {
        return [
            ['processing', \RuntimeException::class], ['processing', \Error::class],
            ['output', \RuntimeException::class], ['output', \Error::class],
            ['diagnostics', \RuntimeException::class], ['diagnostics', \Error::class],
        ];
    }

    /** @dataProvider exceptionalResults */
    public function testUnexpectedFailuresBecomePrivateSafeResultsWithoutReplay(string $phase, string $errorClass): void
    {
        $processor = new class($phase, $errorClass) implements ICommandProcessor {
            public int $calls = 0;
            public function __construct(private string $phase, private string $errorClass) {}
            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                ++$this->calls;
                if ($this->phase === 'processing') {
                    throw new ($this->errorClass)('synthetic-private-marker');
                }
                $unrenderable = new class($this->errorClass) implements \Stringable {
                    public function __construct(private string $errorClass) {}
                    public function __toString(): string
                    {
                        throw new ($this->errorClass)('synthetic-private-marker');
                    }
                };
                return new CommandResult(
                    CommandResult::CONFIRMATION_REQUIRED,
                    $this->phase === 'output' ? $unrenderable : 'synthetic-private-output',
                    confirmationRequired: true,
                    diagnostics: $this->phase === 'diagnostics' ? [$unrenderable] : [],
                    metadata: ['private' => 'synthetic-private-metadata'],
                    continuationToken: 'synthetic-private-token'
                );
            }
        };
        $host = new CommandHost($processor, new CommandResultPresenter());
        $result = $host->execute('operate');
        $this->assertSame(1, $processor->calls);
        $this->assertSame(CommandResult::FAILED, $result->status());
        $this->assertSame(1, $result->exitCode());
        $this->assertTrue($result->failedAtHost());
        $this->assertNull($result->output());
        $this->assertFalse($result->confirmationRequired());
        $this->assertNull($result->continuationToken());
        $stage = $phase === 'processing' ? 'processing' : 'presentation';
        $this->assertSame(['failure_stage' => $stage, 'effects' => 'unknown'], $result->metadata());
        $this->assertSame(['command_host_' . $stage . '_failed'], $result->diagnostics());
        $this->assertStringNotContainsString('synthetic-private', json_encode($result->toArray()));
    }

    public function testCliAndContinuationFailuresUseTheSameHostResult(): void
    {
        $processor = new class implements ICommandProcessor {
            public array $requests = [];
            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $this->requests[] = $request;
                throw new \RuntimeException('synthetic-private-marker');
            }
        };
        $host = new CommandHost($processor, new CommandResultPresenter());
        $cli = new CliManager($host);
        $context = ['actor' => ['id' => 'operator']];
        $direct = $host->execute('operate', $context);
        $interactive = $cli->execute('operate', $context);
        $resumed = $host->resume('synthetic-private-token', false, $context);
        $this->assertCount(3, $processor->requests);
        $this->assertSame($direct->toArray(), $interactive->toArray());
        $this->assertSame($direct->toArray(), $resumed->toArray());
        $this->assertTrue($processor->requests[2]->isContinuation());
        $this->assertFalse($processor->requests[2]->approved());
        $this->assertSame($context, $processor->requests[2]->context());
    }

    public function testUnencodableOutputIsNotReportedAsSuccessfulEmptyText(): void
    {
        $processor = new class implements ICommandProcessor {
            public int $calls = 0;
            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                ++$this->calls;
                return CommandResult::completed(['measurement' => INF]);
            }
        };
        $result = (new CommandHost($processor, new CommandResultPresenter()))->execute('measure');
        $this->assertSame(1, $processor->calls);
        $this->assertSame(CommandResult::FAILED, $result->status());
        $this->assertTrue($result->failedAtHost());
        $this->assertSame(['command_host_presentation_failed'], $result->diagnostics());
        $this->assertNull($result->output());
    }

    public function testItPresentsAProcessorResultWithoutExecutingItAgain(): void
    {
        $processor = new class implements ICommandProcessor {
            public int $calls = 0;
            public ?CommandRequest $request = null;

            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $this->calls++;
                $this->request = $request instanceof CommandRequest ? $request : new CommandRequest($request);

                return new CommandResult(
                    CommandResult::FAILED,
                    ['reason' => 'unavailable'],
                    exitCode: 7,
                    diagnostics: ['provider_unavailable'],
                    metadata: ['correlation_id' => 'request-a']
                );
            }
        };
        $host = new CommandHost($processor, new CommandResultPresenter());

        $presentation = $host->execute('list resources', ['actor' => ['id' => 'operator-a']]);

        $this->assertSame(1, $processor->calls);
        $this->assertSame('list resources', $processor->request?->input());
        $this->assertSame('operator-a', $processor->request?->context()['actor']['id']);
        $this->assertSame(CommandResult::FAILED, $presentation->status());
        $this->assertFalse($presentation->failedAtHost());
        $this->assertSame(['reason' => 'unavailable'], $presentation->output());
        $this->assertSame('{"reason":"unavailable"}', $presentation->outputText());
        $this->assertSame(7, $presentation->exitCode());
        $this->assertSame(['provider_unavailable'], $presentation->diagnostics());
        $this->assertSame('provider_unavailable', $presentation->diagnosticText());
        $this->assertSame(
            '{"reason":"unavailable"}' . PHP_EOL . 'provider_unavailable',
            $presentation->message()
        );
        $this->assertSame('request-a', $presentation->metadata()['correlation_id']);
    }

    public function testItPreservesConfirmationAndResumesOnceWithTheDecision(): void
    {
        $processor = new class implements ICommandProcessor {
            public int $calls = 0;
            public array $requests = [];

            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $this->calls++;
                $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
                $this->requests[] = $request;

                if ($request->isContinuation()) {
                    return CommandResult::completed(['approved' => $request->approved()]);
                }

                return CommandResult::pending(null, null, 'continuation-a', ['policy' => 'confirm']);
            }
        };
        $host = new CommandHost($processor, new CommandResultPresenter());

        $pending = $host->execute('delete record', ['tenant_id' => 'tenant-a']);
        $completed = $host->resume('continuation-a', false, ['tenant_id' => 'tenant-a']);

        $this->assertSame(2, $processor->calls);
        $this->assertTrue($pending->confirmationRequired());
        $this->assertSame('Confirmation required.', $pending->outputText());
        $this->assertSame('continuation-a', $pending->continuationToken());
        $this->assertSame('confirm', $pending->metadata()['policy']);
        $this->assertTrue($processor->requests[1]->isContinuation());
        $this->assertFalse($processor->requests[1]->approved());
        $this->assertSame('tenant-a', $processor->requests[1]->context()['tenant_id']);
        $this->assertSame('{"approved":false}', $completed->outputText());
    }

    public function testCliAndProgrammaticCallsUseTheSameHostPresentation(): void
    {
        $processor = new class implements ICommandProcessor {
            public int $calls = 0;
            public array $requests = [];

            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $this->calls++;
                $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
                $this->requests[] = $request;

                return CommandResult::completed('ready');
            }
        };
        $query = new class implements IActivatedAddOnsQuery {
            public function fetch(): array
            {
                return [['name' => 'reports', 'is_active' => 1]];
            }
        };
        $host = new CommandHost($processor, new CommandResultPresenter());
        $cli = new CliManager($host, new AgentCommandContextProvider($query));

        $programmatic = $host->execute('list commands', ['actor' => ['id' => 'api']]);
        $terminal = $cli->execute('list commands');

        $this->assertSame(2, $processor->calls);
        $this->assertSame($programmatic->toArray(), $terminal->toArray());
        $this->assertSame('api', $processor->requests[0]->context()['actor']['id']);
        $this->assertSame('cli', $processor->requests[1]->context()['actor']['id']);
        $this->assertSame(['reports'], $processor->requests[1]->context()['active_addons']);
    }
}
