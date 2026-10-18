<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\BlueCore\Domain\AddOn\AddOn;
use BlueFission\Utils\File;
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
            if (!$addon instanceof AddOn || !(new File())->exists($addon->path . DIRECTORY_SEPARATOR . $addon->primary_file)) {
                throw new RuntimeException('selected_addon_unavailable');
            }
        }
        foreach (Arr::make($names)->unique() as $name) {
            $this->loadAddOn($selected->get($name));
        }
    }
}
