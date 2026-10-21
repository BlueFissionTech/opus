<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Business;

use AddOns\Kwikle\Domain\RunRequest;
use App\Domain\Agents\AgentRuntimeContext;
use BlueFission\{Arr, Func, Str};
use BlueFission\Wise\Cmd\{Command, CommandRequest, CommandResult, ICommandProcessor};
use Closure;
use Throwable;

class WorkflowCommandProcessor implements ICommandProcessor
{
    private Closure $context;
    public function __construct(private ICommandProcessor $processor, private WorkflowService $workflows, callable $context)
    { $this->context = Closure::fromCallable($context); }

    public function process(CommandRequest|Command|array|string $request): CommandResult
    {
        $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
        if ($request->isContinuation()) { return $this->processor->process($request); }
        $parsed = $this->processor->process(CommandRequest::parse($request->input(), $request->context()));
        $command = $parsed->command();
        if ($parsed->status() !== CommandResult::PARSED || !$command instanceof Command
            || !Arr::has((array) $command->resources, 'kwikle-workflow', true)) {
            return $request->shouldExecute() ? $this->processor->process($request) : $parsed;
        }
        $args = $command->args;
        if (!Arr::is($args) || !Arr::isList($args)) { return CommandResult::invalid('Invalid workflow arguments.'); }
        $operation = $args[0] ?? null;
        $lengths = ['list' => 1, 'validate' => 2, 'explain' => 2, 'dry-run' => 4, 'run' => 4, 'retry' => 5, 'status' => 3];
        if ($command->resources !== ['kwikle-workflow'] || !Arr::is($args) || !Arr::isList($args)
            || !Str::is($operation) || !Arr::hasKey($lengths, $operation) || Arr::size($args) !== $lengths[$operation]
            || $command->verb !== (Arr::has(['run', 'retry'], $operation, true) ? 'do' : 'get')) {
            return CommandResult::invalid('Invalid workflow command.', ['kwikle_command_invalid']);
        }
        foreach ($args as $arg) {
            if (!Str::is($arg) || Str::len($arg) > 4096) { return CommandResult::invalid('Invalid argument.'); }
        }
        if ($operation === 'retry' && !Arr::has(['2', '3'], $args[4], true)) { return CommandResult::invalid('Invalid attempt.'); }
        if (!$request->shouldExecute()) { return CommandResult::parsed($command); }
        try {
            $context = ($this->context)();
            if (!$context instanceof AgentRuntimeContext) { return CommandResult::failure('Workflow access unavailable.'); }
            $output = match ($operation) {
                'list' => ['status' => 'completed', 'workflows' => $this->workflows->catalog($context)],
                'validate', 'explain' => $this->workflows->describe($args[1], $context),
                'status' => $this->workflows->status($args[1], $args[2], $context)?->toArray() ?? ['status' => 'unavailable'],
                'dry-run' => $this->workflows->dryRun(new RunRequest($args[1], $args[2], $args[3]), $context)->toArray(),
                default => $this->workflows->run(new RunRequest($args[1], $args[2], $args[3], (int) ($args[4] ?? 1)), $context)->toArray(),
            };
            $ok = Arr::has(['completed', 'valid', 'dry_run', 'compensated'], $output['status'], true);
            return new CommandResult($ok ? CommandResult::COMPLETED : CommandResult::FAILED, $output, $command,
                false, $ok ? 0 : 1, $ok ? [] : ['kwikle_' . $output['status']]);
        } catch (Throwable) { return CommandResult::failure('Workflow operation could not be confirmed.', ['kwikle_failed']); }
    }

    public function availableCommands(): array
    {
        $commands = Func::isCallable([$this->processor, 'availableCommands']) ? $this->processor->availableCommands() : [];
        return Arr::make($commands)->merge(['get kwikle-workflow', 'do kwikle-workflow'])->unique()->sort()->toArray();
    }
}
