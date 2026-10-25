<?php
declare(strict_types=1);
namespace AddOns\Versa\Business;

use AddOns\Versa\Domain\SendRequest;
use BlueFission\{Arr, Func, Str};
use BlueFission\Wise\Cmd\{Command, CommandRequest, CommandResult, ICommandProcessor};

/** Native Wise owns parsing and continuations; host review owns each new send. */
final class MessagingCommandProcessor implements ICommandProcessor
{
    public function __construct(private ICommandProcessor $processor, private MessagingService $messaging) {}

    public function process(CommandRequest|Command|array|string $request): CommandResult
    {
        $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
        if ($request->isContinuation()) { return $this->processor->process($request); }
        $input = $request->input();
        $resources = $input instanceof Command ? $input->resources : (Arr::is($input) ? ($input['resources'] ?? null) : null);
        if (Arr::is($resources) && Arr::has($resources, 'versa-message', true)) {
            $arguments = $input instanceof Command ? $input->args : ($input['args'] ?? null);
            if (!$this->validArguments($arguments)) { return CommandResult::invalid('Invalid messaging argument.'); }
        }
        $parsed = $this->processor->process(CommandRequest::parse($request->input(), $request->context()));
        $command = $parsed->command();
        if ($parsed->status() !== CommandResult::PARSED || !$command instanceof Command
            || !Arr::has((array) $command->resources, 'versa-message', true)) {
            return $request->shouldExecute() ? $this->processor->process($request) : $parsed;
        }
        $args = $command->args;
        if ($command->resources !== ['versa-message'] || !Arr::is($args) || !Arr::isList($args)) {
            return CommandResult::invalid('Invalid messaging command.');
        }
        $operation = $args[0] ?? null;
        $shape = ['channels' => ['get', 1], 'status' => ['get', 3], 'text' => ['send', 4], 'notify' => ['send', 4]];
        if (!Str::is($operation) || !Arr::hasKey($shape, $operation)
            || $command->verb !== $shape[$operation][0] || Arr::size($args) !== $shape[$operation][1]) {
            return CommandResult::invalid('Invalid messaging arguments.');
        }
        if (!$this->validArguments($args)) { return CommandResult::invalid('Invalid messaging argument.'); }
        if (!$request->shouldExecute()) { return CommandResult::parsed($command); }
        if ($operation === 'channels') {
            return CommandResult::completed(['channels' => $this->messaging->catalog()]);
        }
        $output = $operation === 'status' ? $this->messaging->status($args[1], $args[2])->toArray()
            : $this->messaging->send(new SendRequest($args[1], $args[2], $args[3], notification: $operation === 'notify'))->toArray();
        $ok = Arr::has(['submitted', 'not_found'], $output['code'], true);
        // Executed results never retain a command/description containing message text.
        return new CommandResult($ok ? CommandResult::COMPLETED : CommandResult::FAILED,
            $output, null, false, $ok ? 0 : 1, $ok ? [] : ['versa_' . $output['code']]);
    }

    public function availableCommands(): array
    {
        $commands = Func::isCallable([$this->processor, 'availableCommands']) ? $this->processor->availableCommands() : [];
        return Arr::make($commands)->merge(['get versa-message', 'send versa-message'])->unique()->sort()->toArray();
    }

    private function validArguments(mixed $args): bool
    {
        if (!Arr::is($args) || !Arr::isList($args) || Arr::size($args) > 4) { return false; }
        foreach ($args as $arg) {
            if (!Str::is($arg) || strlen($arg) > 8192) { return false; }
        }
        return true;
    }
}
