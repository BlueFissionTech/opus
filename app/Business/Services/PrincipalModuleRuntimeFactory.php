<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use BlueFission\Arr;
use BlueFission\Automata\LLM\Agent;
use BlueFission\Str;
use Closure;
use RuntimeException;

/** Explicit advisory profile installation; never selected by task content. */
final class PrincipalModuleRuntimeFactory implements IAgentRuntimeFactory
{
    private Arr $profiles;
    private Closure $buildModule;
    private Closure $authorize;

    public function __construct(array $profiles, callable $buildModule, callable $authorize)
    {
        $this->profiles = Arr::make($profiles)->map(static function ($value): array {
            $profile = Arr::make(Arr::is($value) ? $value : []);
            $snapshot = ['enabled' => $profile->get('enabled') === true];
            foreach (['capability_id', 'capability_version', 'input_key'] as $key) {
                $value = $profile->get($key);
                $snapshot[$key] = Str::is($value) ? Str::make($value)->val()
                    : ($key === 'input_key' && $value === null ? 'input' : '');
            }
            return $snapshot;
        });
        $this->buildModule = Closure::fromCallable($buildModule);
        $this->authorize = Closure::fromCallable($authorize);
    }

    public function available(AgentDescriptor $descriptor, AgentRuntimeContext $context): bool
    {
        $profile = $this->profiles->get($descriptor->profile());
        if (!Arr::is($profile)) { return false; }
        $profile = Arr::make($profile);
        $inputKey = $profile->get('input_key') ?? 'input';
        return $descriptor->owner() === 'application' && $descriptor->mode() === 'central'
            && $context->applicationId() !== null && $profile->get('enabled') === true
            && Str::is($profile->get('capability_id')) && Str::isNotEmpty($profile->get('capability_id'))
            && Str::is($profile->get('capability_version')) && Str::isNotEmpty($profile->get('capability_version'))
            && Str::is($inputKey) && Str::matches($inputKey, '/^[a-z][a-zA-Z0-9_]{0,63}$/D')
            && !Arr::has(['applicationId', 'agentId', 'tenantId', 'correlationId', 'executionId'], $inputKey, true)
            && class_exists(Agent::class);
    }

    public function create(AgentDescriptor $descriptor, AgentRuntimeContext $context): IAgentRuntime
    {
        if (!$this->available($descriptor, $context)) {
            throw new RuntimeException('principal_module_unavailable');
        }
        return new PrincipalModuleRuntime($descriptor, $context->applicationId(), $context->tenantId(),
            Arr::make($this->profiles->get($descriptor->profile()))->toArray(), $this->buildModule, $this->authorize);
    }
}
