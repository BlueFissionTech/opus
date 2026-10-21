<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\BlueCore\Domain\AddOn\AddOn;
use RuntimeException;

final class SelectedAddOnManager extends BootAddOnManager
{
    public function loadSelected(array $names): void
    {
        $selected = Arr::make([]);
        foreach ($this->_model->getActivatedAddOns() as $row) {
            $addon = new AddOn();
            $addon->assign($row);
            if (Arr::has($names, $addon->name, true)) {
                $selected->set($addon->name, $addon);
            }
        }
        // Validate the whole selection before running any selected entrypoint.
        foreach ($names as $name) {
            $addon = $selected->get($name);
            if (!$addon instanceof AddOn || ($this->resolvePrimaryFile($addon)['ok'] ?? false) !== true) {
                throw new RuntimeException('selected_addon_unavailable');
            }
        }
        foreach (Arr::make($names)->unique() as $name) {
            $result = $this->loadAddOn($selected->get($name));
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException('selected_addon_unavailable');
            }
        }
    }
}
