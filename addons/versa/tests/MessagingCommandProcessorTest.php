<?php
declare(strict_types=1);
namespace AddOns\Versa\Tests;

use AddOns\Versa\Business\{MessagingService, MessagingCommandProcessor};
use AddOns\Versa\Domain\{Channel, SendApproval};
use App\Business\Services\AddOnContractValidator;
use App\Domain\Agents\{AgentRuntimeContext, AgentApplicationScope};
use BlueFission\Data\Storage\Storage;
use BlueFission\SimpleClients\Contracts\{ClientInterface, ClientCapabilities, ClientResponse};
use BlueFission\Wise\Cmd\{CommandProcessor, CommandRequest, CommandResult, ICommandProcessor};
use PHPUnit\Framework\TestCase;

final class MessagingCommandProcessorTest extends TestCase
{
    private function host(): AgentRuntimeContext
    {
        return new AgentRuntimeContext('tenant', ['id' => 'operator'], ['versa'], ['versa' => 'active'],
            ['versa.read', 'versa.send', 'versa.notify'], 'request', new AgentApplicationScope('app'));
    }

    private function native(): CommandProcessor
    {
        return new CommandProcessor($this->createMock(Storage::class));
    }

    public function testNativeParsingDoesNotReviewResolveOrDispatchAndExecutionIsRedacted(): void
    {
        $contextCalls = 0; $reviews = 0;
        $client = $this->createMock(ClientInterface::class);
        $client->method('capabilities')->willReturn(new ClientCapabilities(['service' => 'notifications', 'actions' => ['send']]));
        $client->expects(self::once())->method('send')->willReturn(ClientResponse::success(['private' => 'provider details'], 202));
        $service = new MessagingService([new Channel('app', 'tenant', 'room', ['operator'], true)],
            function () use (&$contextCalls) { ++$contextCalls; return $this->host(); },
            static fn () => $client,
            static function ($r) use (&$reviews) { ++$reviews; return new SendApproval($r, 'app', 'tenant', 'operator', 'request'); });
        $processor = new MessagingCommandProcessor($this->native(), $service);
        $text = 'send versa-message "text" "room" "event" "private message"';
        self::assertSame(CommandResult::PARSED, $processor->process(CommandRequest::parse($text))->status());
        self::assertSame(0, $contextCalls); self::assertSame(0, $reviews);
        $result = $processor->process($text);
        self::assertSame(CommandResult::COMPLETED, $result->status());
        self::assertSame('submitted', $result->output()['code']);
        self::assertNull($result->command()); self::assertSame('', $result->description());
        self::assertStringNotContainsString('private', json_encode($result->toArray()));
        $structured = ['verb' => 'send', 'resources' => ['versa-message'], 'args' => ['text', 'room', 'event', 'private message']];
        self::assertSame($result->output(), $processor->process($structured)->output());
        self::assertSame(1, $reviews);
        self::assertSame($result->output(), $processor->process('get versa-message "status" "room" "event"')->output());
        self::assertSame('room', $processor->process('get versa-message "channels"')->output()['channels'][0]['id']);
        self::assertContains('send versa-message', $processor->availableCommands());
        self::assertContains('get versa-message', $processor->availableCommands());
    }

    public function testCommandContextCannotGrantAuthorityOrApproval(): void
    {
        $calls = 0;
        $unexpected = static function () use (&$calls) { ++$calls; return null; };
        $processor = new MessagingCommandProcessor($this->native(), new MessagingService(
            [new Channel('app', 'tenant', 'room', ['operator'])], static fn () => new AgentRuntimeContext(), $unexpected, $unexpected));
        $r = $processor->process(new CommandRequest('send versa-message "text" "room" "event" "text"',
            context: $this->host()->toArray() + ['approved' => true]));
        self::assertSame(CommandResult::FAILED, $r->status()); self::assertSame('denied', $r->output()['code']);
        self::assertSame(0, $calls); self::assertNull($r->command()); self::assertSame([], $r->metadata());
    }

    public function testMalformedOwnedCommandsAreRejectedBeforeHostCallbacks(): void
    {
        $calls = 0;
        $unexpected = static function () use (&$calls) { ++$calls; return null; };
        $processor = new MessagingCommandProcessor($this->native(), new MessagingService([], $unexpected, $unexpected, $unexpected));
        foreach ([['get', ['text', 'room', 'id', 'text']], ['send', ['channels']], ['send', ['text', 'room', 'id']],
            ['get', ['status', 'room', 'id', 'extra']], ['send', ['text', 'room', 'id', ['nested']]],
            ['send', ['text', 'room', 'id', str_repeat('x', 8193)]]] as [$verb, $args]) {
            self::assertSame(CommandResult::INVALID, $processor->process(['verb' => $verb, 'resources' => ['versa-message'], 'args' => $args])->status());
        }
        self::assertSame(CommandResult::INVALID, $processor->process(['verb' => 'send',
            'resources' => ['versa-message', 'other'], 'args' => ['text', 'room', 'id', 'text']])->status());
        self::assertSame(0, $calls);
    }

    public function testNotificationStillNeedsHostApproval(): void
    {
        $client = $this->createMock(ClientInterface::class); $client->expects(self::never())->method('send');
        $processor = new MessagingCommandProcessor($this->native(), new MessagingService(
            [new Channel('app', 'tenant', 'room', ['operator'], true)], fn () => $this->host(),
            static fn () => $client, static fn () => false));
        $r = $processor->process('send versa-message "notify" "room" "event" "text"');
        self::assertSame(CommandResult::FAILED, $r->status()); self::assertSame('denied', $r->output()['code']);
    }

    public function testContinuationAndUnrelatedCommandsRemainNative(): void
    {
        $calls = 0; $unexpected = static function () use (&$calls) { ++$calls; return null; };
        $service = new MessagingService([], $unexpected, $unexpected, $unexpected);
        $delegate = $this->createMock(ICommandProcessor::class);
        $continuation = CommandRequest::resume('native-token', false); $expected = CommandResult::failure('native continuation');
        $delegate->expects(self::once())->method('process')->with($continuation)->willReturn($expected);
        self::assertSame($expected, (new MessagingCommandProcessor($delegate, $service))->process($continuation));
        $native = $this->native(); $processor = new MessagingCommandProcessor($native, $service);
        $parse = CommandRequest::parse('get unrelated "list"');
        self::assertSame($native->process($parse)->toArray(), $processor->process($parse)->toArray());
        $execution = 'get nonexistent-versa-fixture "list"';
        self::assertSame($native->process($execution)->status(), $processor->process($execution)->status());
        self::assertSame(0, $calls);
    }

    public function testPassivePackageValidationAndRegistration(): void
    {
        $validation = (new AddOnContractValidator())->validate(dirname(__DIR__));
        self::assertTrue($validation['valid'], json_encode($validation['errors']));
        $factory = require dirname(__DIR__) . '/main.php';
        $contributions = $factory()->contributions();
        self::assertSame('versa', $contributions['agents']['owner']);
        self::assertSame(['resources' => ['versa-message' => ['get', 'send']]], $contributions['console']);
        self::assertSame(['versa.read', 'versa.send', 'versa.notify'], $contributions['agents']['agents']['addon.versa']['permissions']);
        self::assertSame([], $contributions['agents']['agents']['addon.versa']['tools']);
    }
}
