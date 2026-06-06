<?php

namespace App\Business\Console;

use App\Business\Services\RuntimeContractProofService;
use BlueFission\Services\Service;
use Throwable;

class RuntimeContractManager extends Service
{
    private RuntimeContractProofService $_contractProof;

    public function __construct(?RuntimeContractProofService $contractProof = null)
    {
        parent::__construct();

        $this->_contractProof = $contractProof ?: new RuntimeContractProofService();
    }

    public function proof(): void
    {
        try {
            $report = $this->_contractProof->readinessReport();
        } catch (Throwable $e) {
            echo "Runtime contract proof unavailable: {$e->getMessage()}\n";
            return;
        }

        echo "Runtime contract proof: {$report['name']}\n";
        echo "Runtime: {$report['runtime']}\n";
        echo "Scripts: {$report['script_count']} ({$report['required_count']} required, {$report['optional_count']} optional)\n";
        echo "Ready: " . ($report['ready'] ? 'yes' : 'no') . "\n";
        echo "Validation: {$this->_contractProof->validationCommand()}\n";

        if ($report['missing'] !== []) {
            echo "Missing:\n";
            foreach ($report['missing'] as $path) {
                echo "- {$path}\n";
            }
        }

        if ($report['invalid'] !== []) {
            echo "Invalid:\n";
            foreach ($report['invalid'] as $item) {
                echo "- {$item}\n";
            }
        }
    }

    public function targets(): void
    {
        $targets = $this->_contractProof->optionalTargets();
        if ($targets === []) {
            echo "No optional contract target scripts are registered.\n";
            return;
        }

        echo "Optional contract target scripts:\n";
        foreach ($targets as $target) {
            $path = (string) ($target['path'] ?? '');
            $capabilities = $target['capabilities'] ?? [];
            $capabilityList = is_array($capabilities) ? implode(', ', $capabilities) : '';
            echo "- {$path}";
            if ($capabilityList !== '') {
                echo " ({$capabilityList})";
            }
            echo "\n";
        }
    }

    public function validate(): void
    {
        $report = $this->_contractProof->readinessReport();
        if (!$report['ready']) {
            echo "Runtime contract proof is not ready for interpreter validation.\n";
            $this->proof();
            return;
        }

        echo "Runtime contract proof files are present.\n";
        echo "Run {$this->_contractProof->validationCommand()} with Jenerator available on Composer autoload for interpreter validation.\n";
    }
}
