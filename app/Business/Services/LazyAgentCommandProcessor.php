<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Data\Storage\Session;
use BlueFission\DevElation;
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
            $processor = $this->processor();
        } catch (Throwable) {
            $this->publishRuntimeAction(ExtensionPointCatalog::AGENT_COMMAND_RUNTIME_UNAVAILABLE, [
                'status' => 'unavailable',
                'reason' => 'command_runtime_unavailable',
                'retryable' => true,
            ]);

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

        return $processor->process($request);
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

        $this->processor = $processor;
        $this->publishRuntimeAction(ExtensionPointCatalog::AGENT_COMMAND_RUNTIME_READY, [
            'status' => 'ready',
            'source' => $this->factory === null ? 'application' : 'factory',
        ]);

        return $this->processor;
    }

    private function buildProcessor(): ICommandProcessor
    {
        $storage = new Session(['location' => 'cache', 'name' => 'system']);
        $loader = new AgentCapabilityMapLoader();
        $applicationRoot = $this->applicationRoot();

        return new AgentScopedCommandProcessor(
            new CommandProcessor($storage),
            new AgentCapabilityMapResolver(
                $loader->loadApplication($applicationRoot . 'mapping/agents.php'),
                catalog: new AgentCapabilityMapCatalog($applicationRoot . 'addons', $loader)
            ),
            new AgentContinuationScopeStore($storage),
            $this->profilePolicies(),
            $this->profileContexts()
        );
    }

    private function profilePolicies(): WiseProfilePolicyResolver
    {
        return new WiseProfilePolicyResolver(
            (array) require $this->applicationRoot() . 'mapping/wise_profiles.php'
        );
    }

    private function profileContexts(): WiseProfileContextResolver
    {
        return new WiseProfileContextResolver();
    }

    private function applicationRoot(): string
    {
        return defined('APP_ROOT')
            ? (string) constant('APP_ROOT')
            : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
    }

    private function publishRuntimeAction(string $name, array $payload): void
    {
        try {
            DevElation::do($name, [$payload]);
        } catch (Throwable) {
            // Observers cannot change command-runtime availability.
        }
    }
}
