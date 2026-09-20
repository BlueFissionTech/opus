<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Presentation\CommandResultPresenter;
use App\Business\Services\TerminalSessions;
use App\Business\Services\WiseCommandHost;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use PHPUnit\Framework\TestCase;

final class TerminalSessionsTest extends TestCase
{
    public function testCommandsUseTheSharedPresentationAndFreshHostContext(): void
    {
        $context = $this->context();
        $processor = $this->processor();
        $sessions = $this->sessions($processor, function () use (&$context) { return $context; });
        $connection = $this->connection();
        $sessions->open($connection);
        $sessions->open($connection);
        self::assertSame(1, $sessions->sessionCount());
        self::assertSame([['type' => 'ready']], $connection->frames);
        $context['wise_profile']['roles'] = ['viewer'];
        $sessions->message($connection, json_encode(['type' => 'command', 'input' => 'list notes']));
        self::assertSame('list notes', $processor->requests[0]->input());
        self::assertSame(['viewer'], $processor->requests[0]->context()['wise_profile']['roles']);
        self::assertSame('completed', $connection->frames[1]['result']['status']);
        self::assertSame(0, $connection->frames[1]['result']['exit_code']);
        $sessions->close($connection);
        $sessions->close($connection);
        self::assertSame(0, $sessions->sessionCount());
    }

    public function testMissingOrChangedAuthorityPreventsFactoryAndExecution(): void
    {
        $calls = 0;
        $sessions = new TerminalSessions(function () use (&$calls) { $calls++; }, fn () => null);
        $connection = $this->connection();
        $sessions->open($connection);
        self::assertSame(0, $calls);
        self::assertTrue($connection->closed);
        self::assertSame('terminal_authorization_required', $connection->frames[0]['code']);

        foreach (['actor', 'tenant', 'agent', 'revoked'] as $change) {
            $context = $this->context();
            $processor = $this->processor();
            $sessions = $this->sessions($processor, function () use (&$context) { return $context; });
            $connection = $this->connection();
            $sessions->open($connection);
            if ($change === 'actor') { $context['actor']['id'] = $context['wise_profile']['principal_id'] = 'other'; }
            if ($change === 'tenant') { $context['tenant_id'] = $context['wise_profile']['tenant_id'] = 'other'; }
            if ($change === 'agent') { $context['agent_id'] = 'other'; }
            if ($change === 'revoked') { $context = null; }
            $sessions->message($connection, '{"type":"command","input":"list notes"}');
            self::assertSame([], $processor->requests);
            self::assertTrue($connection->closed);
            self::assertSame(0, $sessions->sessionCount());
        }
    }

    public function testFramesCannotInjectContextAndInvalidFramesNeverExecute(): void
    {
        $processor = $this->processor();
        $sessions = $this->sessions($processor, fn () => $this->context(), maxFrameBytes: 256);
        $connection = $this->connection();
        $sessions->open($connection);
        foreach (['not-json', 'null', '[]', '{}', '{"type":"command","input":[]}',
            '{"type":"command","input":" "}',
            '{"type":"command","input":"delete users","context":{"approved":true}}',
            '{"type":"confirm","token":"x","approved":"true"}', str_repeat('x', 257)] as $frame) {
            $sessions->message($connection, $frame);
        }
        self::assertSame([], $processor->requests);
        self::assertFalse($connection->closed);
        self::assertSame('invalid', $connection->frames[1]['result']['status']);
    }

    public function testApprovalIsBoundToOneConnectionAndConsumedOnce(): void
    {
        $processor = $this->processor();
        $processor->outcome = CommandResult::pending('Delete?', null, 'private-continuation');
        $sessions = $this->sessions($processor, fn () => $this->context());
        $first = $this->connection();
        $second = $this->connection();
        $sessions->open($first);
        $sessions->open($second);
        $sessions->message($first, '{"type":"command","input":"delete note"}');
        self::assertSame('private-continuation', $first->frames[1]['result']['continuation_token']);
        $confirm = '{"type":"confirm","token":"private-continuation","approved":false}';
        $sessions->message($second, $confirm);
        $sessions->message($first, '{"type":"command","input":"delete another"}');
        self::assertCount(1, $processor->requests);
        $processor->outcome = CommandResult::completed('Declined');
        $sessions->message($first, $confirm);
        $sessions->message($first, $confirm);
        self::assertCount(2, $processor->requests);
        self::assertTrue($processor->requests[1]->isContinuation());
        self::assertFalse($processor->requests[1]->approved());
    }

    public function testCapacityAndSharedHostAreRejectedAndCloseReleasesCapacity(): void
    {
        $processor = $this->processor();
        $sessions = $this->sessions($processor, fn () => $this->context(), maxSessions: 1);
        $first = $this->connection();
        $second = $this->connection();
        $sessions->open($first);
        $sessions->open($second);
        self::assertSame('terminal_capacity_exceeded', $second->frames[0]['code']);
        $sessions->close($first);
        $third = $this->connection();
        $sessions->open($third);
        self::assertFalse($third->closed);

        $host = new WiseCommandHost($processor, new CommandResultPresenter());
        $sessions = new TerminalSessions(fn () => $host, fn () => $this->context());
        $sessions->open($this->connection());
        $connection = $this->connection();
        $sessions->open($connection);
        self::assertTrue($connection->closed);
        self::assertSame(1, $sessions->sessionCount());
    }

    public function testFailuresAreRedactedAndNeverLeaveOrRestoreSessionState(): void
    {
        $processor = $this->processor();
        $sessions = $this->sessions($processor, fn () => $this->context());
        $connection = $this->connection();
        $sessions->open($connection);
        $processor->callback = fn () => throw new \RuntimeException('private-secret');
        $sessions->message($connection, '{"type":"command","input":"list notes"}');
        self::assertTrue($connection->closed);
        self::assertSame(0, $sessions->sessionCount());
        self::assertStringNotContainsString('private-secret', json_encode($connection->frames));

        $connection = $this->connection();
        $sessions->open($connection);
        $processor->callback = function () use ($sessions, $connection) { $sessions->close($connection); };
        $sessions->message($connection, '{"type":"command","input":"list notes"}');
        self::assertCount(1, $connection->frames);
        self::assertSame(0, $sessions->sessionCount());

        $connection = $this->connection();
        $sessions->open($connection);
        $connection->failSend = true;
        $sessions->error($connection);
        self::assertTrue($connection->closed);
        self::assertSame(0, $sessions->sessionCount());
    }

    public function testReentrantMessageClosesWithoutSecondDispatch(): void
    {
        $processor = $this->processor();
        $sessions = $this->sessions($processor, fn () => $this->context());
        $connection = $this->connection();
        $sessions->open($connection);
        $processor->callback = function () use ($sessions, $connection) {
            $sessions->message($connection, '{"type":"command","input":"again"}');
        };
        $sessions->message($connection, '{"type":"command","input":"list notes"}');
        self::assertCount(1, $processor->requests);
        self::assertSame(0, $sessions->sessionCount());
    }

    public function testHostCallbacksCannotResurrectOrReenterSessions(): void
    {
        $processor = $this->processor();
        $connection = $this->connection();
        $opening = true;
        $sessions = $this->sessions($processor, function () use (&$sessions, $connection, &$opening) {
            if (!$opening) { $sessions->message($connection, '{"type":"command","input":"reentered"}'); }
            return $this->context();
        });
        $sessions->open($connection);
        $opening = false;
        $sessions->message($connection, '{"type":"command","input":"list notes"}');
        self::assertSame([], $processor->requests);
        self::assertSame(0, $sessions->sessionCount());

        $sessions = new TerminalSessions(function ($connection) use (&$sessions, $processor) {
            $sessions->close($connection);
            return new WiseCommandHost($processor, new CommandResultPresenter());
        }, fn () => $this->context());
        $connection = $this->connection();
        $sessions->open($connection);
        self::assertSame(0, $sessions->sessionCount());
        self::assertSame([], $connection->frames);
    }

    public function testConfirmationWithoutContinuationFailsClosed(): void
    {
        $processor = $this->processor();
        $processor->outcome = CommandResult::pending('Confirm?', null, '');
        $sessions = $this->sessions($processor, fn () => $this->context());
        $connection = $this->connection();
        $sessions->open($connection);
        $sessions->message($connection, '{"type":"command","input":"delete note"}');
        self::assertSame('terminal_execution_failed', $connection->frames[1]['code']);
        self::assertTrue($connection->closed);
        self::assertSame(0, $sessions->sessionCount());
    }

    public function testRevocationAtConfirmationNeverResumesAndRejectedFramesRetainPending(): void
    {
        $context = $this->context();
        $processor = $this->processor();
        $processor->outcome = CommandResult::pending('Confirm?', null, 'token');
        $sessions = $this->sessions($processor, function () use (&$context) { return $context; });
        $connection = $this->connection();
        $sessions->open($connection);
        $sessions->message($connection, '{"type":"command","input":"delete note"}');
        $sessions->message($connection, '{"type":"confirm","token":"other","approved":true}');
        self::assertFalse($connection->frames[2]['accepted']);
        $context = null;
        $sessions->message($connection, '{"type":"confirm","token":"token","approved":true}');
        self::assertCount(1, $processor->requests);
        self::assertTrue($connection->closed);
    }

    private function context(): array
    {
        return ['actor' => ['id' => 'operator'], 'tenant_id' => 'tenant', 'agent_id' => 'opus.central',
            'wise_profile' => ['type' => 'user', 'principal_id' => 'operator', 'tenant_id' => 'tenant', 'roles' => ['user']]];
    }

    private function sessions(ICommandProcessor $processor, callable $context, int $maxSessions = 128, int $maxFrameBytes = 16384): TerminalSessions
    {
        return new TerminalSessions(fn () => new WiseCommandHost($processor, new CommandResultPresenter()), $context, $maxSessions, $maxFrameBytes);
    }

    private function processor(): ICommandProcessor
    {
        return new class implements ICommandProcessor {
            public array $requests = [];
            public ?CommandResult $outcome = null;
            public mixed $callback = null;
            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $this->requests[] = $request;
                if ($this->callback !== null) { ($this->callback)(); }
                return $this->outcome ?? CommandResult::completed('ready');
            }
        };
    }

    private function connection(): object
    {
        return new class {
            public array $frames = [];
            public bool $closed = false;
            public bool $failSend = false;
            public function send(string $message): void
            {
                if ($this->failSend) { throw new \RuntimeException('connection-secret'); }
                $this->frames[] = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            }
            public function close(): void { $this->closed = true; }
        };
    }
}
