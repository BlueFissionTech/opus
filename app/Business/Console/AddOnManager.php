<?php

namespace App\Business\Console;

use BlueFission\Services\Service;

class AddOnManager extends Service
{
    protected $_addonManager;

    public function __construct( )
    {
        $addonManager = instance('addons');
        $this->_addonManager = $addonManager;
        parent::__construct();
    }

    public function install($behavior)
    {
        $addonName = $behavior?->context['data'] ?? null;

        if (empty($addonName)) {
            echo "Addon name must be provided.\n";
            return;
        }

        $status = $this->_addonManager->install($addonName);
        echo "Installation completed with status: $status\n";
    }

    public function uninstall($behavior)
    {
        $addonName = $behavior?->context['data'] ?? null;

        if (empty($addonName)) {
            echo "Addon name must be provided.\n";
            return;
        }

        $addon = $this->_addonManager->getAddOnData($addonName);
        if (!$addon) {
            echo "Addon $addonName not found.\n";
            return;
        }

        $status = $this->_addonManager->uninstall($addon->addon_id);
        echo "Uninstallation completed with status: $status\n";
    }

    public function activate($behavior)
    {
        $addonName = $behavior?->context['data'] ?? null;

        if (empty($addonName)) {
            echo "Addon name must be provided.\n";
            return;
        }

        $addon = $this->_addonManager->getAddOnData($addonName);
        if (!$addon) {
            echo "Addon $addonName not found.\n";
            return;
        }

        $status = $this->_addonManager->activate($addon->addon_id);
        echo "Activation completed with status: $status\n";
    }

    public function deactivate($behavior)
    {
        $addonName = $behavior?->context['data'] ?? null;
        
        if (empty($addonName)) {
            echo "Addon name must be provided.\n";
            return;
        }

        $addon = $this->_addonManager->getAddOnData($addonName);
        if (!$addon) {
            echo "Addon $addonName not found.\n";
            return;
        }

        $status = $this->_addonManager->deactivate($addon->addon_id);
        echo "Deactivation completed with status: $status\n";
    }

    public function showAll()
    {
        $addons = $this->_addonManager->showAllAddOns();

        if (empty($addons)) {
            echo "No addons found.\n";
            return;
        }

        echo "Showing all addons:\n";
        foreach ($addons as $addon) {
            echo "Addon: {$addon->name}\n";
            echo "Description: {$addon->description}\n";
            echo "Path: {$addon->path}\n";
            echo "-----------------------------------\n";
        }
    }
}
