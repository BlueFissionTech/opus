<?php
declare(strict_types=1);
namespace AddOns\Meridia\Domain;

use App\Domain\Agents\AgentRuntimeContext;
use BlueFission\{Arr, Obj, Str};

/** Captured afresh from authenticated host state, never from command arguments. */
class ResolutionContext extends Obj
{
    protected $_immutable = true;
    public readonly ?string $application;
    public readonly ?string $tenant;
    public readonly array $capabilities;
    public readonly bool $admitted;

    public function __construct(AgentRuntimeContext $host)
    {
        $this->application = $host->applicationId();
        $this->tenant = $host->tenantId();
        $this->capabilities = $host->capabilities();
        $this->admitted = Str::isNotEmpty((string) $this->application)
            && Str::isNotEmpty((string) ($host->actor()['id'] ?? ''))
            && Arr::has($host->activeAddOns(), 'meridia', true)
            && ($host->lifecycleStates()['meridia'] ?? null) === 'active'
            && Arr::has($this->capabilities, 'meridia.read', true);
        $this->_data = ['version' => 1, 'application' => $this->application,
            'tenant' => $this->tenant, 'capabilities' => $this->capabilities, 'admitted' => $this->admitted];
        parent::__construct();
    }
}
