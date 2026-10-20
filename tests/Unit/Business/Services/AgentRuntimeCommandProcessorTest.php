<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Console\CliManager;
use App\Business\Presentation\CommandResultPresenter;
use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCompositionService;
use App\Business\Services\AgentRuntimeCommandProcessor;
use App\Business\Services\AgentRuntimeStateStore;
use App\Business\Services\AgentScopedCommandProcessor;
use App\Business\Services\ApplicationAgentRuntimeStateStore;
use App\Business\Services\CommandHost;
use App\Domain\Agents\AgentApplicationScope;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateSynchronizer;
use App\Domain\Agents\IAgentContinuationScopeStore;
use BlueFission\Data\Storage\Storage;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandProcessor;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeCommandProcessorTest extends TestCase
{
    private ?AgentRuntimeContext $current = null;
    private int $contextReads = 0;
    private int $probes = 0;
    private int $builds = 0;
    private int $stops = 0;
    private bool $contextFailure = false;
    private bool $available = true;

    public function testTextAndStructuredInspectionShareNativeWiseParsingAndPolicy(): void
    {
        [$processor] = $this->fixture();
        $host = new CommandHost($processor, new CommandResultPresenter());
        $text = $host->execute('get agent-runtime "principal"');
        $structured = $host->execute(['verb' => 'get', 'resources' => ['agent-runtime'], 'args' => ['principal']]);
        self::assertSame(CommandResult::COMPLETED, $text->status());
        self::assertSame($text->toArray(), $structured->toArray());
        self::assertSame($text->toArray(), (new CliManager($host))->execute('get agent-runtime "principal"')->toArray());
        self::assertTrue($text->output()['output']['available']);
        self::assertSame('correlation', $text->metadata()['correlation_id']);
        self::assertSame(0, $this->builds);
        self::assertSame(3, $this->probes);
    }

    public function testParseIsPassiveAndNeverResolvesAuthorityOrProbesRuntime(): void
    {
        [$processor] = $this->fixture();
        $parsed = $processor->process(CommandRequest::parse('do agent-runtime "stop" "principal"'));
        self::assertSame(CommandResult::PARSED, $parsed->status());
        self::assertSame(['stop', 'principal'], $parsed->command()->args);
        self::assertSame(0, $this->contextReads);
        self::assertSame(0, $this->probes);
        self::assertSame(0, $this->builds);
    }

    public function testPayloadCannotMintIdentityScopeOrPermission(): void
    {
        [$processor] = $this->fixture();
        $this->current = $this->context([]);
        $result = $processor->process(new CommandRequest('get agent-runtime "principal"', context: [
            'actor' => ['id' => 'admin'], 'application_id' => 'app', 'tenant_id' => 'a',
            'capabilities' => ['operate', 'opus.runtime.inspect'],
        ]));
        self::assertSame(CommandResult::INVALID, $result->status());
        self::assertSame(['agent_runtime_command_denied'], $result->diagnostics());
        self::assertSame(0, $this->probes);
        self::assertSame(0, $this->builds);
    }

    public function testFreshRevocationBlocksCleanupButAuthorizedCleanupUsesExistingInstance(): void
    {
        [$processor, $composition] = $this->fixture();
        self::assertTrue($composition->start('principal', $this->current)->ok());
        self::assertSame(1, $this->builds);
        $this->current = $this->context(['operate', 'opus.runtime.inspect']);
        self::assertSame(CommandResult::INVALID, $processor->process('do agent-runtime "stop" "principal"')->status());
        self::assertSame(0, $this->stops);
        $this->current = $this->context(['operate', 'opus.runtime.stop']);
        $this->available = false;
        $before = $this->probes;
        $result = $processor->process('do agent-runtime "stop" "principal"');
        self::assertSame(CommandResult::COMPLETED, $result->status());
        self::assertSame('stopped', $result->output()['state']);
        self::assertSame(1, $this->stops);
        self::assertSame(1, $this->builds);
        self::assertSame($before, $this->probes);
    }

    /** @dataProvider invalidCommands */
    public function testMalformedOrUnsupportedRuntimeRequestsNeverExecute(array $input): void
    {
        [$processor] = $this->fixture();
        self::assertSame(CommandResult::INVALID, $processor->process($input)->status());
        self::assertSame(0, $this->contextReads);
        self::assertSame(0, $this->builds);
        self::assertSame(0, $this->probes);
    }

    public static function invalidCommands(): array
    {
        return [
            [['verb' => 'get', 'resources' => ['agent-runtime'], 'args' => []]],
            [['verb' => 'get', 'resources' => ['agent-runtime'], 'args' => ['principal', 'extra']]],
            [['verb' => 'do', 'resources' => ['agent-runtime'], 'args' => ['retry', 'principal']]],
            [['verb' => 'do', 'resources' => ['agent-runtime', 'file'], 'args' => ['stop', 'principal']]],
            [['verb' => 'delete', 'resources' => ['agent-runtime'], 'args' => ['principal']]],
            [['verb' => 'do', 'resources' => ['agent-runtime'], 'args' => ['stop', 'principal', 'extra']]],
        ];
    }

    public function testLifecycleCommandsUseTheCompositionStateMachine(): void
    {
        [$processor] = $this->fixture();
        foreach (['start' => 'running', 'suspend' => 'suspended', 'resume' => 'running',
            'stop' => 'stopped', 'unregister' => 'unregistered', 'register' => 'registered'] as $action => $state) {
            $this->current = $this->context(['operate', 'opus.runtime.' . $action]);
            $result = $processor->process('do agent-runtime "' . $action . '" "principal"');
            self::assertSame(CommandResult::COMPLETED, $result->status(), $action);
            self::assertSame($state, $result->output()['state']);
        }
    }

    public function testCancellationUsesHostExecutionScopeAndRetirementCannotRemoveCurrentOwner(): void
    {
        [$processor, $composition] = $this->fixture();
        self::assertTrue($composition->start('principal', $this->current)->ok());
        $this->current = $this->context(['operate', 'opus.runtime.cancel', 'opus.runtime.retire']);
        self::assertSame(CommandResult::COMPLETED, $processor->process('do agent-runtime "cancel" "principal"')->status());
        $result = $processor->process('do agent-runtime "retire" "principal"');
        self::assertSame(CommandResult::FAILED, $result->status());
        self::assertSame(['agent_runtime_not_stale'], $result->diagnostics());
        self::assertNull($result->output());
    }

    public function testWrongApplicationAndMissingActorAreDeniedWithoutProbe(): void
    {
        [$processor] = $this->fixture();
        $this->current = new AgentRuntimeContext('a', actor: ['id' => 'operator'],
            capabilities: ['operate', 'opus.runtime.inspect'], applicationScope: new AgentApplicationScope('foreign'));
        $result = $processor->process('get agent-runtime "principal"');
        self::assertSame(CommandResult::FAILED, $result->status());
        self::assertNull($result->output());
        $this->current = new AgentRuntimeContext('a', capabilities: ['operate', 'opus.runtime.inspect'],
            applicationScope: new AgentApplicationScope('app'));
        self::assertSame(CommandResult::INVALID, $processor->process('get agent-runtime "principal"')->status());
        self::assertSame(0, $this->probes);
    }

    public function testContextFailureIsPrivateAndNeverConstructsRuntime(): void
    {
        [$processor] = $this->fixture();
        $this->contextFailure = true;
        $result = $processor->process('get agent-runtime "principal"');
        self::assertSame(CommandResult::FAILED, $result->status());
        self::assertFalse($result->metadata()['operation_started']);
        self::assertSame('unknown', $result->metadata()['effects']);
        self::assertStringNotContainsString('private', serialize($result->toArray()));
        self::assertSame(0, $this->probes);
    }

    public function testUnrelatedCommandsAndContinuationsRetainWrappedProcessorBehavior(): void
    {
        [, $composition] = $this->fixture();
        $delegate = $this->createMock(ICommandProcessor::class);
        $ordinary = new Command();
        $ordinary->verb = 'get';
        $ordinary->resources = ['info'];
        $delegate->expects($this->exactly(3))->method('process')->willReturnOnConsecutiveCalls(
            CommandResult::parsed($ordinary), CommandResult::completed('ordinary'), CommandResult::failure('unknown token')
        );
        $processor = new AgentRuntimeCommandProcessor($delegate, $composition,
            function (): never { self::fail('Unrelated commands must not resolve runtime identity.'); });
        self::assertSame('ordinary', $processor->process('get info')->output());
        self::assertSame('unknown token', $processor->process(CommandRequest::resume('unrelated', true))->output());
    }

    public function testUnavailableInspectionPreservesAvailabilityWithoutConstructingRuntime(): void
    {
        [$processor] = $this->fixture();
        $this->available = false;
        $result = $processor->process('get agent-runtime "principal"');
        self::assertSame(CommandResult::FAILED, $result->status());
        self::assertSame('unavailable', $result->metadata()['runtime_status']);
        self::assertFalse($result->output()['output']['available']);
        self::assertSame('registered', $result->output()['state']);
        self::assertSame(0, $this->builds);
    }

    public function testGovernorToolPolicyAndRuntimeOperationPolicyBothApply(): void
    {
        [$processor] = $this->fixture();
        $map = new AgentCapabilityMap(1, 'application', ['principal' => [
            'mode' => 'central', 'profile' => 'local', 'tools' => ['agent-runtime.get'], 'permissions' => ['operate'],
        ]]);
        $scoped = new AgentScopedCommandProcessor($processor, new AgentCapabilityMapResolver($map),
            $this->createMock(IAgentContinuationScopeStore::class));
        $context = ['agent_id' => 'principal', 'tenant_id' => 'a', 'capabilities' => ['operate']];
        self::assertSame(CommandResult::INVALID, $scoped->process(new CommandRequest(
            'do agent-runtime "stop" "principal"', context: $context))->status());
        self::assertSame(0, $this->contextReads);
        self::assertSame(CommandResult::COMPLETED, $scoped->process(new CommandRequest(
            'get agent-runtime "principal"', context: $context))->status());
        $this->current = $this->context(['operate']);
        self::assertSame(CommandResult::INVALID, $scoped->process(new CommandRequest(
            'get agent-runtime "principal"', context: $context))->status());
        self::assertSame(1, $this->probes);
    }

    public function testDiscoveryRetainsWrappedCommandsWithoutProbingOrResolvingAuthority(): void
    {
        [$processor] = $this->fixture();
        self::assertContains('get agent-runtime', $processor->availableCommands());
        self::assertContains('do agent-runtime', $processor->availableCommands());
        self::assertSame(0, $this->contextReads);
        self::assertSame(0, $this->probes);
        self::assertSame(0, $this->builds);
    }

    private function fixture(): array
    {
        $this->current = $this->context(['operate', 'opus.runtime.inspect', 'opus.runtime.stop']);
        $map = new AgentCapabilityMap(1, 'application', ['principal' => [
            'mode' => 'central', 'profile' => 'local', 'tools' => [], 'permissions' => ['operate'],
        ]]);
        $runtime = $this->createMock(IAgentRuntime::class);
        $runtime->method('start')->willReturn(AgentRuntimeResult::completed('start', 'principal', 'a', 'running'));
        $runtime->method('suspend')->willReturn(AgentRuntimeResult::completed('suspend', 'principal', 'a', 'suspended'));
        $runtime->method('resume')->willReturn(AgentRuntimeResult::completed('resume', 'principal', 'a', 'running'));
        $runtime->method('cancel')->willReturn(AgentRuntimeResult::completed('cancel', 'principal', 'a', 'running'));
        $runtime->method('stop')->willReturnCallback(function (): AgentRuntimeResult {
            ++$this->stops;
            return AgentRuntimeResult::completed('stop', 'principal', 'a', 'stopped');
        });
        $factory = $this->createMock(IAgentRuntimeFactory::class);
        $factory->method('available')->willReturnCallback(function (): bool { ++$this->probes; return $this->available; });
        $factory->method('create')->willReturnCallback(function () use ($runtime): IAgentRuntime { ++$this->builds; return $runtime; });
        $lock = $this->createMock(IAgentRuntimeStateSynchronizer::class);
        $lock->method('synchronized')->willReturnCallback(fn ($scope, $operation) => $operation());
        $states = new ApplicationAgentRuntimeStateStore(new AgentApplicationScope('app'), new AgentRuntimeStateStore(new Storage(), $lock));
        $composition = new AgentCompositionService(new AgentCapabilityMapResolver($map), $factory, $states);
        $composition->registerMap($map);
        $storage = new Storage();
        (new \ReflectionProperty(Storage::class, '_source'))->setValue($storage, []);
        $processor = new AgentRuntimeCommandProcessor(new CommandProcessor($storage), $composition, function (): ?AgentRuntimeContext {
            ++$this->contextReads;
            if ($this->contextFailure) { throw new \RuntimeException('private-context-failure'); }
            return $this->current;
        });
        return [$processor, $composition];
    }

    private function context(array $capabilities): AgentRuntimeContext
    {
        return new AgentRuntimeContext('a', actor: ['id' => 'operator'], capabilities: $capabilities,
            correlationId: 'correlation', applicationScope: new AgentApplicationScope('app'));
    }
}
