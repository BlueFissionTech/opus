<?php

declare(strict_types=1);

namespace App\Business\Console;

use App\Business\Services\RuntimeContractProofService;
use BlueFission\Arr;
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
            $report = Arr::make($this->_contractProof->readinessReport());
        } catch (Throwable $e) {
            $this->reportUnavailable($e);
            return;
        }

        echo "Runtime contract proof: {$report->get('name')}\n";
        echo "Runtime: {$report->get('runtime')}\n";
        echo "Scripts: {$report->get('script_count')} ({$report->get('required_count')} required, {$report->get('optional_count')} optional)\n";
        echo "Ready: " . ($report->get('ready') ? 'yes' : 'no') . "\n";
        echo "Validation: {$this->_contractProof->validationCommand()}\n";

        $missing = Arr::make($report->get('missing'));
        if ($missing->isNotEmpty()) {
            echo "Missing:\n";
            foreach ($missing as $path) {
                echo "- {$path}\n";
            }
        }

        $invalid = Arr::make($report->get('invalid'));
        if ($invalid->isNotEmpty()) {
            echo "Invalid:\n";
            foreach ($invalid as $item) {
                echo "- {$item}\n";
            }
        }
    }

    public function targets(): void
    {
        try {
            $targets = Arr::make($this->_contractProof->optionalTargets());
        } catch (Throwable $e) {
            $this->reportUnavailable($e);
            return;
        }

        if ($targets->isEmpty()) {
            echo "No optional contract target scripts are registered.\n";
            return;
        }

        echo "Optional contract target scripts:\n";
        foreach ($targets as $target) {
            $target = Arr::make($target);
            $path = (string) ($target->get('path') ?? '');
            $capabilities = $target->get('capabilities');
            $capabilityList = Arr::is($capabilities)
                ? Arr::make($capabilities)->join(', ')->val()
                : '';
            echo "- {$path}";
            if ($capabilityList !== '') {
                echo " ({$capabilityList})";
            }
            echo "\n";
        }
    }

    public function validate(): void
    {
        try {
            $report = Arr::make($this->_contractProof->readinessReport());
        } catch (Throwable $e) {
            $this->reportUnavailable($e);
            return;
        }

        if (!$report->get('ready')) {
            echo "Runtime contract proof is not ready for interpreter validation.\n";
            $this->proof();
            return;
        }

        echo "Runtime contract proof files are present.\n";
        echo "Run {$this->_contractProof->validationCommand()} with Jenerator available on Composer autoload for interpreter validation.\n";
    }

    private function reportUnavailable(Throwable $error): void
    {
        echo "Runtime contract proof unavailable: {$error->getMessage()}\n";
    }
}
