<?php

declare(strict_types=1);

namespace AddOns\Tributary\Business;

use App\Domain\Agents\AgentRuntimeContext;
use BlueFission\Arr;
use BlueFission\Func;
use BlueFission\Str;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use Closure;
use Throwable;

final class TaskCommandProcessor implements ICommandProcessor
{
    private Closure $resolveContext;

    public function __construct(private ICommandProcessor $processor, private TaskService $tasks, callable $resolveContext)
    {
        $this->resolveContext = Closure::fromCallable($resolveContext);
    }

    public function process(CommandRequest|Command|array|string $request): CommandResult
    {
        $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
        if ($request->isContinuation()) { return $this->processor->process($request); }
        $parsed = $this->processor->process(CommandRequest::parse($request->input(), $request->context()));
        $command = $parsed->command();
        if ($parsed->status() !== CommandResult::PARSED || !$command instanceof Command
            || !Arr::has((array) $command->resources, 'tributary-task', true)) {
            return $request->shouldExecute() ? $this->processor->process($request) : $parsed;
        }
        $args = $command->args;
        if ($command->resources !== ['tributary-task'] || !is_array($args) || !array_is_list($args)
            || !(($command->verb === 'get' && count($args) === 1) || ($command->verb === 'do' && count($args) === 2))) {
            return CommandResult::invalid('Invalid task command.', ['tributary_command_invalid']);
        }
        foreach ($args as $arg) {
            if (!Str::is($arg) || Str::isEmpty(Str::trim($arg)) || Str::len($arg) > 256) {
                return CommandResult::invalid('Invalid task command.', ['tributary_command_invalid']);
            }
        }
        if (!$request->shouldExecute()) { return CommandResult::parsed($command); }
        try {
            $context = ($this->resolveContext)();
            if (!$context instanceof AgentRuntimeContext) {
                return CommandResult::invalid('Task access is unavailable.', ['tributary_access_denied']);
            }
            $result = $command->verb === 'get' ? $this->tasks->discover($args[0], $context)
                : $this->tasks->run($args[0], $args[1], $context);
            return new CommandResult($result['ok'] ? CommandResult::COMPLETED : CommandResult::FAILED,
                $result['status'] === 'denied' ? null : $result, $command, false,
                $result['ok'] ? 0 : 1, $result['diagnostics'], ['task_status' => $result['status']]);
        } catch (Throwable) {
            return CommandResult::failure('Task operation could not be confirmed.', ['tributary_command_failed']);
        }
    }

    public function availableCommands(): array
    {
        $commands = Func::isCallable([$this->processor, 'availableCommands']) ? $this->processor->availableCommands() : [];
        return Arr::make($commands)->merge(['get tributary-task', 'do tributary-task'])->unique()->sort()->toArray();
    }
}
