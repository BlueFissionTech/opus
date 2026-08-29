<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Data\Storage\Session;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandProcessor;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use Closure;
use RuntimeException;
use Throwable;

final class LazyAgentCommandProcessor implements ICommandProcessor
{
    private ?ICommandProcessor $processor = null;
    private ?Closure $factory;

    public function __construct(?callable $factory = null)
    {
        $this->factory = $factory === null ? null : Closure::fromCallable($factory);
    }

    public function process(CommandRequest|Command|array|string $request): CommandResult
    {
        try {
            return $this->processor()->process($request);
        } catch (Throwable) {
            return CommandResult::invalid(
                'The command runtime is unavailable.',
                ['agent_command_runtime_unavailable'],
                [
                    'agent_decision' => 'deny',
                    'agent_reason' => 'command_runtime_unavailable',
                    'agent_result_status' => CommandResult::INVALID,
                ]
            );
        }
    }

    private function processor(): ICommandProcessor
    {
        if ($this->processor !== null) {
            return $this->processor;
        }

        $processor = $this->factory === null
            ? $this->buildProcessor()
            : ($this->factory)();
        if (!$processor instanceof ICommandProcessor || $processor === $this) {
            throw new RuntimeException('invalid_agent_command_processor');
        }

        return $this->processor = $processor;
    }

    private function buildProcessor(): ICommandProcessor
    {
        $storage = new Session(['location' => 'cache', 'name' => 'system']);
        $loader = new AgentCapabilityMapLoader();

        return new AgentScopedCommandProcessor(
            new CommandProcessor($storage),
            new AgentCapabilityMapResolver(
                $loader->loadApplication(APP_ROOT . 'mapping/agents.php'),
                catalog: new AgentCapabilityMapCatalog(APP_ROOT . 'addons', $loader)
            ),
            new AgentContinuationScopeStore($storage)
        );
    }
}
