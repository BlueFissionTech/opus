<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\IAgentContinuationScopeStore;
use App\Domain\Agents\ResolvedAgentToolMap;
use BlueFission\Arr;
use BlueFission\Str;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;

final class AgentScopedCommandProcessor implements ICommandProcessor
{
    public const CENTRAL_AGENT = 'opus.central';

    public function __construct(
        private ICommandProcessor $processor,
        private AgentCapabilityMapResolver $resolver,
        private IAgentContinuationScopeStore $continuations
    ) {
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
            return $parsed->withMetadata(Arr::merge($metadata, [
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

        if (!$request->shouldExecute()) {
            return $parsed->withMetadata(Arr::merge($metadata, [
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
        $result = $result->withMetadata(Arr::merge($metadata, [
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
            ]);
        }

        return $result;
    }

    public function discover(array $context): array
    {
        $context = Arr::make($context);
        $resolved = $this->resolve($context);

        return [
            'commands' => $resolved->tools(),
            'metadata' => $this->metadata($context, $resolved),
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
        if ($continuation->isEmpty()
            || !$this->hasActorScope($continuation->get('actor'))
            || !$this->hasActorScope($context->get('actor'))
            || $continuation->get('agent_id') !== $resolved->agentId()
            || $continuation->get('tenant_id') !== $resolved->tenantId()
            || $continuation->get('actor') !== $context->get('actor')
            || !Str::is($tool)
            || !$resolved->allows((string) $tool)
        ) {
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

        return $result->withMetadata(Arr::merge($metadata, [
            'agent_tool' => $tool,
            'agent_decision' => 'allow',
            'agent_reason' => 'continuation_granted',
            'agent_result_status' => $result->status(),
        ]));
    }

    private function hasActorScope(mixed $actor): bool
    {
        if (Str::is($actor)) {
            return Str::make((string) $actor)->trim()->isNotEmpty();
        }
        if (!Arr::is($actor)) {
            return false;
        }

        return Str::make((string) Arr::getPath((array) $actor, 'id'))->trim()->isNotEmpty();
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
}
