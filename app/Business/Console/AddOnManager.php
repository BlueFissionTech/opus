<?php

declare(strict_types=1);

namespace App\Business\Console;

use App\Business\Services\AddOnLifecycleReadinessService;
use BlueFission\Arr;
use BlueFission\Services\Service;
use BlueFission\Str;

class AddOnManager extends Service
{
    private object $manager;
    private AddOnLifecycleReadinessService $readiness;

    public function __construct(
        ?object $manager = null,
        ?AddOnLifecycleReadinessService $readiness = null
    ) {
        parent::__construct();

        $this->manager = $manager ?? instance('addons');
        $this->readiness = $readiness ?? new AddOnLifecycleReadinessService();
    }

    public function install($behavior): array
    {
        $name = $this->addOnName($behavior);
        if ($name === null) {
            return $this->readiness->failure('install', 'addon_name_required', 'Add-on name must be provided.');
        }

        return $this->readiness->normalize($this->manager->install($name));
    }

    public function install_all(): array
    {
        return $this->readiness->normalize($this->manager->installAll());
    }

    public function uninstall($behavior): array
    {
        $resolved = $this->installedAddOn($behavior, 'uninstall');
        if (Arr::hasKey($resolved, 'failure')) {
            return (array) Arr::getPath($resolved, 'failure');
        }

        return $this->readiness->normalize($this->manager->uninstall(Arr::getPath($resolved, 'id')));
    }

    public function activate($behavior): array
    {
        $resolved = $this->installedAddOn($behavior, 'activate');
        if (Arr::hasKey($resolved, 'failure')) {
            return (array) Arr::getPath($resolved, 'failure');
        }

        return $this->readiness->normalize($this->manager->activate(Arr::getPath($resolved, 'id')));
    }

    public function activate_all(): array
    {
        return $this->readiness->normalize($this->manager->activateAll());
    }

    public function deactivate($behavior): array
    {
        $resolved = $this->installedAddOn($behavior, 'deactivate');
        if (Arr::hasKey($resolved, 'failure')) {
            return (array) Arr::getPath($resolved, 'failure');
        }

        return $this->readiness->normalize($this->manager->deactivate(Arr::getPath($resolved, 'id')));
    }

    public function showAll(): array
    {
        return $this->readiness->listing((array) $this->manager->showAllAddOns());
    }

    private function addOnName($behavior): ?string
    {
        $name = Arr::getPath((array) ($behavior?->context ?? []), 'data');
        if (!Str::is($name) || Str::make((string) $name)->trim()->isEmpty()) {
            return null;
        }

        return Str::make((string) $name)->trim()->val();
    }

    private function installedAddOn($behavior, string $action): array
    {
        $name = $this->addOnName($behavior);
        if ($name === null) {
            return ['failure' => $this->readiness->failure(
                $action,
                'addon_name_required',
                'Add-on name must be provided.'
            )];
        }

        $addOn = $this->manager->getAddOnData($name);
        $id = is_object($addOn) ? ($addOn->addon_id ?? null) : null;
        if ($id === null) {
            return ['failure' => $this->readiness->failure(
                $action,
                'addon_not_found',
                "Add-on {$name} was not found."
            )];
        }

        return ['id' => $id];
    }
}
