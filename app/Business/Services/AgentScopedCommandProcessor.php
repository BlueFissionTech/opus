<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\IAgentContinuationScopeStore;
use App\Domain\Agents\ProfileAccessDecision;
use App\Domain\Agents\ResolvedAgentToolMap;
use BlueFission\Arr;
use BlueFission\Str;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use InvalidArgumentException;

final class AgentScopedCommandProcessor implements ICommandProcessor
{
    public const CENTRAL_AGENT = 'opus.central';

    public function __construct(
        private ICommandProcessor $processor,
        private AgentCapabilityMapResolver $resolver,
        private IAgentContinuationScopeStore $continuations,
        private ?WiseProfilePolicyResolver $profilePolicies = null,
        private ?WiseProfileContextResolver $profileContexts = null
    ) {
        $this->profileContexts ??= new WiseProfileContextResolver();
    }

    public function process(CommandRequest|Command|array|string $request): CommandResult
    {
        $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
        $context = Arr::make($request->context());
        $resolved = $this->resolve($context);
        $metadata = $this->metadata($context, $resolved);

        if ($request->isContinuation()) {
            return $this->continue($request, $resolved, $metadata, $context);
        }

        $parsed = $this->processor->process(CommandRequest::parse($request->input(), $request->context()));
        if ($parsed->status() !== CommandResult::PARSED || !$parsed->command() instanceof Command) {
            return $this->withMetadata($parsed, Arr::merge($metadata, [
                'agent_decision' => 'deny',
                'agent_reason' => 'command_parse_failed',
                'agent_result_status' => $parsed->status(),
            ]));
        }

        $tool = $this->tool($parsed->command());
        if ($tool === null || !$resolved->allows($tool)) {
            return CommandResult::invalid(
                'Command is unavailable to this agent.',
                ['agent_capability_denied'],
                Arr::merge($metadata, [
                    'agent_tool' => $tool,
                    'agent_decision' => 'deny',
                    'agent_reason' => $tool === null ? 'command_tool_unresolved' : 'tool_not_granted',
                    'agent_result_status' => CommandResult::INVALID,
                ]),
                $parsed->command()
            );
        }

        $profileDecision = $this->profileDecision($tool, $resolved, $context);
        $metadata = Arr::merge($metadata, $this->profileMetadata($profileDecision));
        if ($profileDecision instanceof ProfileAccessDecision && !$profileDecision->allowed()) {
            return CommandResult::invalid(
                'Profile resource is unavailable in this context.',
                ['wise_profile_access_denied'],
                Arr::merge($metadata, [
                    'agent_tool' => $tool,
                    'agent_decision' => 'deny',
                    'agent_reason' => $profileDecision->reason(),
                    'agent_result_status' => CommandResult::INVALID,
                ]),
                $parsed->command()
            );
        }

        if (!$request->shouldExecute()) {
            return $this->withMetadata($parsed, Arr::merge($metadata, [
                'agent_tool' => $tool,
                'agent_decision' => 'allow',
                'agent_reason' => 'tool_granted',
                'agent_result_status' => $parsed->status(),
            ]));
        }

        $result = $this->processor->process(new CommandRequest(
            $parsed->command(),
            CommandRequest::EXECUTE,
            $request->context()
        ));
        $result = $this->withMetadata($result, Arr::merge($metadata, [
            'agent_tool' => $tool,
            'agent_decision' => 'allow',
            'agent_reason' => 'tool_granted',
            'agent_result_status' => $result->status(),
        ]));
        if ($result->confirmationRequired() && Str::isNotEmpty((string) $result->continuationToken())) {
            if (!$this->hasActorScope($context->get('actor'))) {
                return CommandResult::invalid(
                    'A trusted actor is required to continue this command.',
                    ['agent_actor_required'],
                    Arr::merge($metadata, [
                        'agent_tool' => $tool,
                        'agent_decision' => 'deny',
                        'agent_reason' => 'actor_scope_required',
                        'agent_result_status' => CommandResult::INVALID,
                    ]),
                    $result->command()
                );
            }
            $this->continuations->put((string) $result->continuationToken(), [
                'agent_id' => $resolved->agentId(),
                'tenant_id' => $resolved->tenantId(),
                'actor' => $context->get('actor'),
                'tool' => $tool,
                'wise_profile' => $profileDecision?->metadata()['target_profile'] ?? null,
            ]);
        }

        return $result;
    }

    public function discover(array $context): array
    {
        $context = Arr::make($context);
        $resolved = $this->resolve($context);
        $denied = 0;
        $commands = Arr::make($resolved->tools())->filter(function (string $tool) use (
            $resolved,
            $context,
            &$denied
        ): bool {
            $decision = $this->profileDecision($tool, $resolved, $context);
            $allowed = !$decision instanceof ProfileAccessDecision || $decision->allowed();
            if (!$allowed) {
                $denied++;
            }

            return $allowed;
        });

        return [
            'commands' => $commands->toArray(),
            'metadata' => Arr::merge($this->metadata($context, $resolved), [
                'profile_commands_denied' => $denied,
            ]),
        ];
    }

    public function availableCommands(array $context = []): array
    {
        return $this->discover($context)['commands'];
    }

    private function continue(
        CommandRequest $request,
        ResolvedAgentToolMap $resolved,
        array $metadata,
        Arr $context
    ): CommandResult
    {
        $token = (string) $request->continuationToken();
        $continuation = Arr::make((array) $this->continuations->get($token));
        $tool = $continuation->get('tool');
        $profileDecision = Str::is($tool)
            ? $this->profileDecision((string) $tool, $resolved, $context)
            : null;
        $metadata = Arr::merge($metadata, $this->profileMetadata($profileDecision));
        $targetProfile = $profileDecision?->metadata()['target_profile'] ?? null;
        $scopeMismatch = $continuation->isEmpty()
            || !$this->hasActorScope($continuation->get('actor'))
            || !$this->hasActorScope($context->get('actor'))
            || $continuation->get('agent_id') !== $resolved->agentId()
            || $continuation->get('tenant_id') !== $resolved->tenantId()
            || $this->actorIdentity($continuation->get('actor'))
                !== $this->actorIdentity($context->get('actor'))
            || ($profileDecision instanceof ProfileAccessDecision
                && $continuation->get('wise_profile') !== $targetProfile)
            || !Str::is($tool);
        $capabilityRevoked = $request->approved() !== false
            && Str::is($tool)
            && (!$resolved->allows((string) $tool)
                || ($profileDecision instanceof ProfileAccessDecision && !$profileDecision->allowed()));
        if ($scopeMismatch || $capabilityRevoked) {
            return CommandResult::invalid(
                'Command continuation is unavailable to this agent.',
                ['agent_continuation_denied'],
                Arr::merge($metadata, [
                    'agent_tool' => $tool,
                    'agent_decision' => 'deny',
                    'agent_reason' => 'continuation_scope_mismatch',
                    'agent_result_status' => CommandResult::INVALID,
                ])
            );
        }

        $this->continuations->delete($token);

        $result = $this->processor->process($request);
        $rejected = $request->approved() === false;

        return $this->withMetadata($result, Arr::merge($metadata, [
            'agent_tool' => $tool,
            'agent_decision' => $rejected ? 'deny' : 'allow',
            'agent_reason' => $rejected ? 'continuation_rejected' : 'continuation_granted',
            'agent_result_status' => $result->status(),
        ]));
    }

    private function hasActorScope(mixed $actor): bool
    {
        return Str::isNotEmpty((string) $this->actorIdentity($actor));
    }

    private function actorIdentity(mixed $actor): ?string
    {
        $identity = Str::is($actor)
            ? Str::make((string) $actor)->trim()->val()
            : Str::make((string) Arr::getPath((array) $actor, 'id'))->trim()->val();

        return Str::isNotEmpty($identity) ? $identity : null;
    }

    private function resolve(Arr $context): ResolvedAgentToolMap
    {
        $agentId = Str::make((string) $context->get('agent_id'))->trim()->val();

        return $this->resolver->resolve(
            $agentId === '' ? self::CENTRAL_AGENT : $agentId,
            (array) $context->get('active_addons'),
            (array) $context->get('addon_states'),
            (array) $context->get('capabilities'),
            Str::is($context->get('tenant_id')) ? (string) $context->get('tenant_id') : null
        );
    }

    private function metadata(Arr $context, ResolvedAgentToolMap $resolved): array
    {
        return [
            'actor' => $context->get('actor'),
            'agent_id' => $resolved->agentId(),
            'tenant_id' => $resolved->tenantId(),
            'correlation_id' => $context->get('correlation_id'),
            'agent_map_versions' => $resolved->versions(),
            'agent_capability_decisions' => $resolved->decisions(),
        ];
    }

    private function tool(Command $command): ?string
    {
        $resources = Arr::make(Arr::is($command->resources) ? $command->resources : []);
        $resource = $resources->get(0);
        if (!Str::is($resource) || !Str::is($command->verb)) {
            return null;
        }

        return Str::make((string) $resource)
            ->trim()
            ->lower()
            ->append('.')
            ->append(Str::make((string) $command->verb)->trim()->lower()->val())
            ->val();
    }

    private function profileDecision(
        string $tool,
        ResolvedAgentToolMap $resolved,
        Arr $context
    ): ?ProfileAccessDecision {
        if ($this->profilePolicies === null || !$this->profilePolicies->isProfileTool($tool)) {
            return null;
        }

        try {
            $profiles = $this->profileContexts->resolve($resolved->agentId(), $context->toArray());
        } catch (InvalidArgumentException) {
            return ProfileAccessDecision::deny('profile_context_invalid');
        }

        return $this->profilePolicies->authorizeTool(
            $profiles['actor'],
            $profiles['target'],
            $tool,
            (array) Arr::getPath($context->toArray(), 'profile_policy.tenant', []),
            (array) Arr::getPath($context->toArray(), 'profile_policy.principal', []),
            (array) $context->get('capabilities')
        );
    }

    private function profileMetadata(?ProfileAccessDecision $decision): array
    {
        return $decision instanceof ProfileAccessDecision
            ? ['wise_profile_access' => $decision->toArray()]
            : [];
    }

    private function withMetadata(CommandResult $result, array $metadata): CommandResult
    {
        return new CommandResult(
            $result->status(),
            $result->output(),
            $result->command(),
            $result->confirmationRequired(),
            $result->exitCode(),
            $result->diagnostics(),
            Arr::merge($result->metadata(), $metadata),
            $result->continuationToken()
        );
    }
}
