<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AdvisoryModuleViolation;
use BlueFission\Arr;
use BlueFission\Automata\LLM\Agent\State\AgentModuleResult;
use BlueFission\Automata\LLM\Agent\State\AgentState;
use BlueFission\Automata\LLM\Agent\State\IAgentModule;

/** A data boundary for trusted installed modules, not a PHP execution sandbox. */
final class AdvisoryAgentModule implements IAgentModule
{
    public function __construct(private IAgentModule $module) {}

    public function name(): string { return $this->module->name(); }

    public function process(AgentState $state, array $context = []): AgentModuleResult
    {
        // Advisory evaluation cannot hold a reference to the retained Agent state.
        $isolated = new AgentState();
        $before = $isolated->snapshot();
        $result = $this->module->process($isolated, $context);
        $data = $result->toArray();
        if ($isolated->snapshot() !== $before || $result->writes() !== [] || $result->decision() !== null
            || Arr::getPath($data, 'writes') !== [] || Arr::getPath($data, 'decision') !== null) {
            throw new AdvisoryModuleViolation('agent_module_advisory_violation');
        }
        return new AgentModuleResult([
            'module' => $this->name(),
            'status' => Arr::getPath($data, 'status'),
            'metadata' => Arr::is(Arr::getPath($data, 'metadata'))
                ? Arr::make(Arr::getPath($data, 'metadata'))->toArray() : [],
        ]);
    }
}
