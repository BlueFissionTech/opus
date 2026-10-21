<?php
declare(strict_types=1);
namespace AddOns\Meridia\Business;

use AddOns\Meridia\Domain\ResolutionContext;
use App\Domain\Agents\AgentRuntimeContext;
use BlueFission\{Arr, Func, Str};
use BlueFission\Wise\Cmd\{Command, CommandRequest, CommandResult, ICommandProcessor};
use Throwable;

class RouteCommandProcessor implements ICommandProcessor
{
    private Func $context;
    public function __construct(private ICommandProcessor $processor, private RouteService $routes, callable $context)
    { $this->context = Func::make($context); }

    public function process(CommandRequest|Command|array|string $request): CommandResult
    {
        $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
        if ($request->isContinuation()) { return $this->processor->process($request); }
        $parsed = $this->processor->process(CommandRequest::parse($request->input(), $request->context()));
        $command = $parsed->command();
        if ($parsed->status() !== CommandResult::PARSED || !$command instanceof Command
            || !Arr::has((array) $command->resources, 'meridia-route', true)) {
            return $request->shouldExecute() ? $this->processor->process($request) : $parsed;
        }
        $args = $command->args;
        if (!Arr::is($args) || !Arr::isList($args) || $command->resources !== ['meridia-route'] || $command->verb !== 'get') {
            return CommandResult::invalid('Invalid route command.');
        }
        $operation = $args[0] ?? null;
        $lengths = ['list' => 1, 'validate' => 3, 'explain' => 3, 'resolve' => 3, 'dry-run' => 3];
        if (!Str::is($operation) || !Arr::hasKey($lengths, $operation) || Arr::size($args) !== $lengths[$operation]) {
            return CommandResult::invalid('Invalid route arguments.');
        }
        foreach ($args as $arg) {
            if (!Str::is($arg) || Str::len($arg) > 2048) { return CommandResult::invalid('Invalid route argument.'); }
        }
        if (!$request->shouldExecute()) { return CommandResult::parsed($command); }
        try {
            $host = ($this->context)();
            if (!$host instanceof AgentRuntimeContext) { return CommandResult::failure('Route access unavailable.'); }
            $context = new ResolutionContext($host);
            if (!$context->admitted) { return CommandResult::failure('Route access denied.', ['meridia_denied']); }
            $output = $operation === 'list'
                ? ['version' => 1, 'status' => 'listed', 'effects' => 'none', 'routes' => $this->routes->catalog($context)]
                : $this->routes->resolve($args[1], $args[2], $context)->toArray();
            $ok = Arr::has(['listed', 'resolved'], $output['status'], true);
            return new CommandResult($ok ? CommandResult::COMPLETED : CommandResult::FAILED,
                $output, $command, false, $ok ? 0 : 1, $ok ? [] : ['meridia_' . $output['status']]);
        } catch (Throwable) { return CommandResult::failure('Route resolution unavailable.', ['meridia_unavailable']); }
    }

    public function availableCommands(): array
    {
        $commands = Func::isCallable([$this->processor, 'availableCommands']) ? $this->processor->availableCommands() : [];
        return Arr::make($commands)->merge(['get meridia-route'])->unique()->sort()->toArray();
    }
}
