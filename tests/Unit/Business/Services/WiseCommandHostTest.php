<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Console\CliManager;
use App\Business\Presentation\CommandResultPresenter;
use App\Business\Services\AgentCommandContextProvider;
use App\Business\Services\WiseCommandHost;
use BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use PHPUnit\Framework\TestCase;

final class WiseCommandHostTest extends TestCase
{
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
        $host = new WiseCommandHost($processor, new CommandResultPresenter());

        $presentation = $host->execute('list resources', ['actor' => ['id' => 'operator-a']]);

        $this->assertSame(1, $processor->calls);
        $this->assertSame('list resources', $processor->request?->input());
        $this->assertSame('operator-a', $processor->request?->context()['actor']['id']);
        $this->assertSame(CommandResult::FAILED, $presentation->status());
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
        $host = new WiseCommandHost($processor, new CommandResultPresenter());

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
        $host = new WiseCommandHost($processor, new CommandResultPresenter());
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
