<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Services\Service;
use BlueFission\Wise\Res\ResourceCatalog;
use Closure;
use RuntimeException;

final class WiseResourceCatalogRegistration extends Service
{
    private WiseIntegrationGuard $guard;
    private Closure $catalogFactory;

    public function __construct(?WiseIntegrationGuard $guard = null, ?callable $catalogFactory = null)
    {
        parent::__construct();
        $this->guard = $guard ?? new WiseIntegrationGuard();
        $this->catalogFactory = $catalogFactory === null
            ? static fn (): ResourceCatalog => new ResourceCatalog()
            : Closure::fromCallable($catalogFactory);
    }

    /** Inspect package metadata without constructing resources or touching an application. */
    public function inspectCatalog(string $profile): array
    {
        if (!$this->guard->allows($profile, [ResourceCatalog::class])) {
            return ['ready' => false, 'reason' => 'catalog_unavailable', 'bindings' => [], 'aliases' => [], 'unavailable' => []];
        }
        $catalog = ($this->catalogFactory)();
        if (!$catalog instanceof ResourceCatalog) {
            throw new RuntimeException('wise_resource_catalog_invalid');
        }
        $definitions = $catalog->definitions();
        $aliases = $catalog->aliases();
        $unavailable = $catalog->unavailable();
        if (!$this->guard->allows($profile, Arr::values($definitions))) {
            return ['ready' => false, 'reason' => 'resource_classes_unavailable', 'bindings' => [], 'aliases' => [], 'unavailable' => $unavailable];
        }

        $bindings = $definitions;
        foreach ($aliases as $alias => $canonical) {
            if (Arr::hasKey($unavailable, $canonical) && !Arr::hasKey($definitions, $canonical)) {
                $unavailable[$alias] = $unavailable[$canonical];
                continue;
            }
            if (!Arr::hasKey($definitions, $canonical)
                || (Arr::hasKey($definitions, $alias) && $alias !== $canonical)) {
                throw new RuntimeException('wise_resource_catalog_alias_invalid');
            }
            $bindings[$alias] = $definitions[$canonical];
        }

        return ['ready' => true, 'reason' => null, 'bindings' => $bindings, 'aliases' => $aliases, 'unavailable' => $unavailable];
    }

    /** The host's delegate implementation retains ownership of resource construction. */
    public function installMappings(object $application, string $profile, array $actions = []): array
    {
        $report = $this->inspectCatalog($profile);
        $report['registered'] = false;
        if (!$report['ready']) {
            return $report;
        }
        foreach ($report['bindings'] as $name => $class) {
            $application->delegate($name, $class);
            $canonical = $report['aliases'][$name] ?? $name;
            foreach ($actions[$canonical] ?? [] as $verb => $method) {
                $application->register($name, $verb, $method);
            }
        }

        $report['registered'] = true;
        return $report;
    }
}
