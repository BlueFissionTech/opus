<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use BlueFission\Arr;
use BlueFission\Func;
use BlueFission\Str;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use Closure;
use Throwable;

/** Optional runtime administration behind the existing Wise parse/host surface. */
final class AgentRuntimeCommandProcessor implements ICommandProcessor
{
    private Closure $resolveContext;
    private const OPERATIONS = ['start', 'suspend', 'resume', 'stop', 'cancel', 'register', 'unregister', 'retire'];

    public function __construct(private ICommandProcessor $processor,
        private AgentCompositionService $composition, callable $resolveContext)
    {
        $this->resolveContext = Closure::fromCallable($resolveContext);
    }

    public function process(CommandRequest|Command|array|string $request): CommandResult
    {
        $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
        // This boundary never issues continuations; existing ones belong to the wrapped processor.
        if ($request->isContinuation()) { return $this->processor->process($request); }
        $parsed = $this->processor->process(CommandRequest::parse($request->input(), $request->context()));
        $command = $parsed->command();
        if ($parsed->status() !== CommandResult::PARSED || !$command instanceof Command
            || !Arr::has((array) $command->resources, 'agent-runtime', true)) {
            return $request->shouldExecute() ? $this->processor->process($request) : $parsed;
        }
        $operation = $this->operation($command);
        if ($operation === null) {
            return CommandResult::invalid('Invalid runtime command.', ['agent_runtime_command_invalid']);
        }
        if (!$request->shouldExecute()) { return CommandResult::parsed($command); }

        $started = false;
        $context = null;
        try {
            // Identity comes from the host session/service, never command context or arguments.
            $context = ($this->resolveContext)();
            if (!$context instanceof AgentRuntimeContext || $context->applicationId() === null
                || !Str::is(Arr::getPath($context->actor(), 'id'))
                || Str::isEmpty(Str::trim(Arr::getPath($context->actor(), 'id')))
                || !Arr::has($context->capabilities(), 'opus.runtime.' . $operation['action'], true)) {
                return CommandResult::invalid('Runtime operation is unavailable.', ['agent_runtime_command_denied']);
            }
            $started = true;
            $result = match ($operation['action']) {
                'inspect' => $this->composition->inspect($operation['agent'], $context),
                'start' => $this->composition->start($operation['agent'], $context),
                'suspend' => $this->composition->suspend($operation['agent'], $context),
                'resume' => $this->composition->resume($operation['agent'], $context),
                'stop' => $this->composition->stop($operation['agent'], $context),
                'cancel' => $this->composition->cancel($operation['agent'], $context),
                'register' => $this->composition->registerRuntime($operation['agent'], $context),
                'unregister' => $this->composition->unregister($operation['agent'], $context),
                'retire' => $this->composition->retire($operation['agent'], $context),
            };
            return new CommandResult(
                $result->ok() ? CommandResult::COMPLETED : CommandResult::FAILED,
                $result->status() === AgentRuntimeResult::DENIED ? null : $result->toArray(),
                $command, false, $result->ok() ? 0 : 1, $result->diagnostics(),
                ['runtime_status' => $result->status(), 'correlation_id' => $context->correlationId()]
            );
        } catch (Throwable) {
            return CommandResult::failure('Runtime operation could not be confirmed.',
                ['agent_runtime_command_failed'], [
                    'operation_started' => $started, 'effects' => 'unknown',
                    'correlation_id' => $context instanceof AgentRuntimeContext ? $context->correlationId() : null,
                ]);
        }
    }

    /** Passive command metadata; consumers still apply their normal tool policy. */
    public function availableCommands(): array
    {
        $commands = Func::isCallable([$this->processor, 'availableCommands'])
            ? $this->processor->availableCommands() : [];
        return Arr::make($commands)->merge(['get agent-runtime', 'do agent-runtime'])->unique()->sort()->toArray();
    }

    private function operation(Command $command): ?array
    {
        if ($command->resources !== ['agent-runtime'] || !Arr::is($command->args)) { return null; }
        $args = Arr::make($command->args)->values();
        foreach ($args->toArray() as $arg) {
            if (!Str::is($arg) || Str::isEmpty(Str::trim($arg)) || Str::len($arg) > 256) { return null; }
        }
        if ($command->verb === 'get' && $args->count() === 1) {
            return ['action' => 'inspect', 'agent' => $args->get(0)];
        }
        $action = $args->get(0);
        if ($command->verb !== 'do' || !Arr::has(self::OPERATIONS, $action, true)
            || $args->count() !== 2) {
            return null;
        }
        return ['action' => $action, 'agent' => $args->get(1)];
    }
}
