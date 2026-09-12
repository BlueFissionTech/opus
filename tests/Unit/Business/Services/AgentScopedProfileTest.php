<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCommandContextProvider;
use App\Business\Services\AgentScopedCommandProcessor;
use App\Business\Services\WiseProfilePolicyResolver;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\IAgentContinuationScopeStore;
use App\Domain\Agents\WiseProfile;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use BlueFission\DevElation;
use PHPUnit\Framework\TestCase;

final class AgentScopedProfileTest extends TestCase
{
    public function testHumanCommandContextSelectsTheHumansPrivateProfile(): void
    {
        $context = (new AgentCommandContextProvider())->forActor('user-a', 'tenant-a');

        $this->assertSame(WiseProfile::USER, $context['wise_profile']['type']);
        $this->assertSame('user-a', $context['wise_profile']['principal_id']);
        $this->assertSame('tenant-a', $context['wise_profile']['tenant_id']);
        $this->assertSame(['user'], $context['wise_profile']['roles']);
    }

    public function testCommandContextHookCanDeriveAnOmittedTenant(): void
    {
        $reflection = new \ReflectionClass(DevElation::class);
        $active = $reflection->getProperty('_isActive');
        $filters = $reflection->getProperty('_filters');
        $originalActive = $active->getValue();
        $originalFilters = $filters->getValue();

        try {
            DevElation::up();
            DevElation::filter('opus.agent.command_context', static function (array $context): array {
                $context['tenant_id'] = 'tenant-a';
                $context['wise_profile']['tenant_id'] = 'tenant-a';
                $context['wise_profile']['roles'] = ['administrator'];

                return $context;
            });

            $context = (new AgentCommandContextProvider())->forActor('user-a');

            $this->assertSame('tenant-a', $context['tenant_id']);
            $this->assertSame('tenant-a', $context['wise_profile']['tenant_id']);
            $this->assertSame(['administrator'], $context['wise_profile']['roles']);
        } finally {
            $active->setValue(null, $originalActive);
            $filters->setValue(null, $originalFilters);
        }
    }

    public function testContinuationRefreshPreservesAgentProfileIdentityWithoutStaleRoles(): void
    {
        $context = (new AgentCommandContextProvider())->forContinuation([
            'actor' => ['id' => 'user-a'],
            'agent_id' => 'addon.reports',
            'tenant_id' => 'tenant-a',
            'wise_profile' => [
                'type' => WiseProfile::ADDON_AGENT,
                'principal_id' => 'addon.reports',
                'tenant_id' => 'tenant-a',
                'roles' => ['administrator'],
            ],
        ]);

        $this->assertSame('addon.reports', $context['agent_id']);
        $this->assertSame(WiseProfile::ADDON_AGENT, $context['wise_profile']['type']);
        $this->assertSame('addon.reports', $context['wise_profile']['principal_id']);
        $this->assertSame([], $context['wise_profile']['roles']);
    }

    public function testContinuationRefreshUsesCurrentUserRolesInsteadOfStaleRoles(): void
    {
        $context = (new AgentCommandContextProvider())->forContinuation([
            'actor' => ['id' => 'user-a'],
            'agent_id' => 'opus.central',
            'tenant_id' => 'tenant-a',
            'wise_profile' => [
                'type' => WiseProfile::USER,
                'principal_id' => 'user-a',
                'tenant_id' => 'tenant-a',
                'roles' => ['administrator'],
            ],
        ]);

        $this->assertSame(['user'], $context['wise_profile']['roles']);
    }

    public function testDiscoveryAndExecutionFailClosedForAnotherPrivateProfile(): void
    {
        $this->requireWiseProcessor();
        $processor = $this->processor();
        $scoped = $this->scoped($processor);
        $context = $this->userContext();
        $context['wise_profile_target'] = [
            'type' => WiseProfile::USER,
            'principal_id' => 'user-b',
            'tenant_id' => 'tenant-a',
            'roles' => ['user'],
        ];

        $discovery = $scoped->discover($context);
        $result = $scoped->process(new CommandRequest('list todo', CommandRequest::EXECUTE, $context));

        $this->assertSame([], $discovery['commands']);
        $this->assertSame(1, $discovery['metadata']['profile_commands_denied']);
        $this->assertSame(CommandResult::INVALID, $result->status());
        $this->assertSame(['wise_profile_access_denied'], $result->diagnostics());
        $this->assertSame(0, $processor->executions);
    }

    public function testOwnerExecutionAndContinuationRemainPinnedToTheProfile(): void
    {
        $this->requireWiseProcessor();
        $processor = $this->processor(true);
        $continuations = $this->continuations();
        $scoped = $this->scoped($processor, $continuations);
        $context = $this->userContext();

        $pending = $scoped->process(new CommandRequest('list todo', CommandRequest::EXECUTE, $context));
        $context['wise_profile_target'] = [
            'type' => WiseProfile::USER,
            'principal_id' => 'user-b',
            'tenant_id' => 'tenant-a',
            'roles' => ['user'],
        ];
        $switched = $scoped->process(CommandRequest::resume('profile-continuation', true, $context));

        $this->assertTrue($pending->confirmationRequired());
        $this->assertSame(
            (new WiseProfile(WiseProfile::USER, 'user-a', 'tenant-a'))->key(),
            $pending->metadata()['wise_profile_access']['target_profile']
        );
        $this->assertSame(CommandResult::INVALID, $switched->status());
        $this->assertSame(['agent_continuation_denied'], $switched->diagnostics());
        $this->assertSame(1, $processor->executions);
    }

    public function testMalformedExplicitProfileContextFailsClosed(): void
    {
        $this->requireWiseProcessor();
        $processor = $this->processor();
        $scoped = $this->scoped($processor);
        $context = $this->userContext();
        $context['wise_profile_target'] = ['principal_id' => 'user-b'];

        $discovery = $scoped->discover($context);
        $result = $scoped->process(new CommandRequest('list todo', CommandRequest::EXECUTE, $context));

        $this->assertSame([], $discovery['commands']);
        $this->assertSame(1, $discovery['metadata']['profile_commands_denied']);
        $this->assertSame(CommandResult::INVALID, $result->status());
        $this->assertSame('profile_context_invalid', $result->metadata()['agent_reason']);
        $this->assertSame(0, $processor->executions);
    }

    public function testNonStringExplicitProfileTenantFailsClosed(): void
    {
        $this->requireWiseProcessor();
        $processor = $this->processor();
        $scoped = $this->scoped($processor);
        $context = $this->userContext();
        $context['wise_profile_target']['tenant_id'] = 123;

        $result = $scoped->process(new CommandRequest('list todo', CommandRequest::EXECUTE, $context));

        $this->assertSame(CommandResult::INVALID, $result->status());
        $this->assertSame('profile_context_invalid', $result->metadata()['agent_reason']);
        $this->assertSame(0, $processor->executions);
    }

    public function testNonStringCommandTenantFailsClosed(): void
    {
        $this->requireWiseProcessor();
        $processor = $this->processor();
        $scoped = $this->scoped($processor);
        $context = $this->userContext();
        $context['tenant_id'] = 123;

        $result = $scoped->process(new CommandRequest('list todo', CommandRequest::EXECUTE, $context));

        $this->assertSame(CommandResult::INVALID, $result->status());
        $this->assertSame('profile_context_invalid', $result->metadata()['agent_reason']);
        $this->assertSame(0, $processor->executions);
    }

    private function scoped(
        ICommandProcessor $processor,
        ?IAgentContinuationScopeStore $continuations = null
    ): AgentScopedCommandProcessor {
        $map = new AgentCapabilityMap(1, 'application', [
            'opus.central' => [
                'mode' => 'central',
                'description' => 'Test central agent.',
                'profile' => 'opus.central',
                'tools' => ['todo.list'],
                'imports' => [],
                'exports' => [],
                'permissions' => [],
                'lifecycle' => ['states' => ['active']],
            ],
        ]);

        return new AgentScopedCommandProcessor(
            $processor,
            new AgentCapabilityMapResolver($map),
            $continuations ?? $this->continuations(),
            new WiseProfilePolicyResolver(
                (array) require dirname(__DIR__, 4) . '/mapping/wise_profiles.php'
            )
        );
    }

    private function processor(bool $confirmation = false): ICommandProcessor
    {
        return new class($confirmation) implements ICommandProcessor {
            public int $executions = 0;

            public function __construct(private bool $confirmation)
            {
            }

            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
                $command = new Command();
                $command->resources = ['todo'];
                $command->verb = 'list';

                if (!$request->shouldExecute()) {
                    return CommandResult::parsed($command);
                }

                $this->executions++;
                if ($this->confirmation && !$request->isContinuation()) {
                    return CommandResult::pending('Confirm profile command.', $command, 'profile-continuation');
                }

                return CommandResult::completed(['ok' => true], $command);
            }
        };
    }

    private function userContext(): array
    {
        return [
            'actor' => ['id' => 'user-a'],
            'agent_id' => 'opus.central',
            'tenant_id' => 'tenant-a',
            'capabilities' => [],
            'active_addons' => [],
            'addon_states' => [],
            'wise_profile' => [
                'type' => WiseProfile::USER,
                'principal_id' => 'user-a',
                'tenant_id' => 'tenant-a',
                'roles' => ['user'],
            ],
        ];
    }

    private function continuations(): IAgentContinuationScopeStore
    {
        return new class implements IAgentContinuationScopeStore {
            private array $items = [];

            public function get(string $token): ?array
            {
                return $this->items[$token] ?? null;
            }

            public function put(string $token, array $scope): void
            {
                $this->items[$token] = $scope;
            }

            public function delete(string $token): void
            {
                unset($this->items[$token]);
            }
        };
    }

    private function requireWiseProcessor(): void
    {
        if (!interface_exists(ICommandProcessor::class)
            || !class_exists(CommandRequest::class)
            || !class_exists(CommandResult::class)
        ) {
            $this->markTestSkipped('The installed Wise checkout predates the typed command processor contract.');
        }
    }
}
