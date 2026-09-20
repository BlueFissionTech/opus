<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCommandContextProvider;
use App\Business\Services\ExtensionPointCatalog;
use App\Domain\Agents\AgentCapabilityMap;
use BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery;
use BlueFission\DevElation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class AgentCommandContextLifecycleTest extends TestCase
{
    private mixed $originalActive;
    private mixed $originalFilters;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(DevElation::class);
        $this->originalActive = $reflection->getProperty('_isActive')->getValue();
        $this->originalFilters = $reflection->getProperty('_filters')->getValue();
        $reflection->getProperty('_filters')->setValue(null, []);
        DevElation::up();
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(DevElation::class);
        $reflection->getProperty('_isActive')->setValue(null, $this->originalActive);
        $reflection->getProperty('_filters')->setValue(null, $this->originalFilters);
    }

    /** @dataProvider authoritativeStates */
    public function testFilterCannotForgeActivation(array $records, bool $unavailable, array $expected): void
    {
        $query = $this->query($records, $unavailable);
        $this->forgeActivation();

        $context = (new AgentCommandContextProvider($query))->forActor('operator-a', 'tenant-a');

        $this->assertSame($expected, $context['active_addons']);
        $this->assertSame($expected === [] ? [] : ['reports' => 'active'], $context['addon_states']);
        $this->assertSame('operator-a', $context['actor']['id']);
        $this->assertSame('tenant-a', $context['tenant_id']);
        $this->assertSame('hook-observed', $context['correlation_id']);
    }

    public static function authoritativeStates(): array
    {
        return [
            'no active add-ons' => [[], false, []],
            'one genuinely active add-on' => [[['name' => 'reports']], false, ['reports']],
            'unavailable lifecycle query' => [[], true, []],
        ];
    }

    public function testFilterCannotEraseAuthoritativeActivation(): void
    {
        DevElation::filter(ExtensionPointCatalog::AGENT_COMMAND_CONTEXT, static function (array $context): array {
            unset($context['active_addons'], $context['addon_states']);
            $context['correlation_id'] = 'kept';
            return $context;
        });

        $context = (new AgentCommandContextProvider($this->query([['name' => 'reports']])))->forActor('operator-a');

        $this->assertSame(['reports'], $context['active_addons']);
        $this->assertSame(['reports' => 'active'], $context['addon_states']);
        $this->assertSame('kept', $context['correlation_id']);
    }

    public function testContinuationCannotResurrectARevokedAddOn(): void
    {
        $query = $this->query([['name' => 'reports']]);
        $provider = new AgentCommandContextProvider($query);
        $prior = $provider->forActor('operator-a', 'tenant-a');
        $this->assertSame(['reports'], $prior['active_addons']);
        $query->records = [];
        $this->forgeActivation();

        $refreshed = $provider->forContinuation($prior);

        $this->assertSame([], $refreshed['active_addons']);
        $this->assertSame([], $refreshed['addon_states']);
        $this->assertSame('tenant-a', $refreshed['tenant_id']);
        $this->assertSame(2, $query->calls);
    }

    public function testForgedActivationCannotUnlockSpecialistTools(): void
    {
        $resolver = new AgentCapabilityMapResolver(
            new AgentCapabilityMap(1, 'application', [
                'opus.central' => ['mode' => 'central', 'tools' => []],
            ]),
            ['reports' => new AgentCapabilityMap(1, 'reports', [
                'addon.reports' => [
                    'mode' => 'specialist',
                    'tools' => ['reports.read'],
                    'lifecycle' => ['states' => ['active']],
                ],
            ])]
        );
        $this->forgeActivation();
        $inactive = (new AgentCommandContextProvider($this->query([])))->forActor('operator-a');
        $active = (new AgentCommandContextProvider($this->query([['name' => 'reports']])))->forActor('operator-a');

        $denied = $resolver->resolve('addon.reports', $inactive['active_addons'], $inactive['addon_states']);
        $allowed = $resolver->resolve('addon.reports', $active['active_addons'], $active['addon_states']);

        $this->assertSame([], $denied->tools());
        $this->assertSame('agent_inactive', $denied->decisions()[0]['reason']);
        $this->assertSame(['reports.read'], $allowed->tools());
    }

    public function testNoHandlerPreservesTheQueriedActivationState(): void
    {
        $context = (new AgentCommandContextProvider($this->query([
            ['name' => ' Reports '], ['name' => 'reports'],
        ])))->forActor('operator-a');

        $this->assertSame(['reports'], $context['active_addons']);
        $this->assertSame(['reports' => 'active'], $context['addon_states']);
        $this->assertSame([], $context['capabilities']);
    }

    public function testMalformedLifecycleRecordDiscardsPreviouslyReadActivation(): void
    {
        $query = $this->query([
            ['name' => 'reports'],
            ['name' => new \stdClass()],
        ]);
        $this->forgeActivation();

        $context = (new AgentCommandContextProvider($query))->forActor('operator-a', 'tenant-a');

        $this->assertSame([], $context['active_addons']);
        $this->assertSame([], $context['addon_states']);
        $this->assertSame('operator-a', $context['actor']['id']);
        $this->assertSame('tenant-a', $context['tenant_id']);
        $this->assertSame('hook-observed', $context['correlation_id']);
    }

    public function testFailedContinuationRefreshDiscardsPriorAndPartialActivation(): void
    {
        $query = $this->query([['name' => 'reports']]);
        $provider = new AgentCommandContextProvider($query);
        $prior = $provider->forActor('operator-a', 'tenant-a');
        $query->records = [['name' => 'reports'], ['name' => new \stdClass()]];

        $refreshed = $provider->forContinuation($prior);

        $this->assertSame([], $refreshed['active_addons']);
        $this->assertSame([], $refreshed['addon_states']);
        $this->assertSame(2, $query->calls);
    }

    private function forgeActivation(): void
    {
        DevElation::filter(ExtensionPointCatalog::AGENT_COMMAND_CONTEXT, static function (array $context): array {
            $context['active_addons'] = ['reports', 'forged'];
            $context['addon_states'] = ['reports' => 'active', 'forged' => 'active'];
            $context['correlation_id'] = 'hook-observed';
            return $context;
        });
    }

    private function query(array $records, bool $unavailable = false): IActivatedAddOnsQuery
    {
        return new class($records, $unavailable) implements IActivatedAddOnsQuery {
            public int $calls = 0;

            public function __construct(public array $records, private bool $unavailable) {}

            public function fetch(): array
            {
                $this->calls++;
                if ($this->unavailable) {
                    throw new RuntimeException('Lifecycle query unavailable.');
                }
                return $this->records;
            }
        };
    }
}
