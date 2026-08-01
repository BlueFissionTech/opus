<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Business\Console\RuntimeContractManager;
use App\Business\Services\RuntimeContractProofService;
use PHPUnit\Framework\TestCase;

class RuntimeContractManagerTest extends TestCase
{
    public function testProofOutputSummarizesRuntimeContractReadiness(): void
    {
        $manager = new RuntimeContractManager(
            new RuntimeContractProofService(dirname(__DIR__, 2))
        );

        ob_start();
        $manager->proof();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Runtime contract proof: opus-runtime-contract-proof', $output);
        $this->assertStringContainsString('Runtime: jenerator', $output);
        $this->assertStringContainsString('Scripts: 5 (3 required, 2 optional)', $output);
        $this->assertStringContainsString('Ready: yes', $output);
        $this->assertStringContainsString('Validation: php examples/jenss/validate.php', $output);
    }

    public function testTargetsOutputListsOptionalContractTargets(): void
    {
        $manager = new RuntimeContractManager(
            new RuntimeContractProofService(dirname(__DIR__, 2))
        );

        ob_start();
        $manager->targets();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Optional contract target scripts:', $output);
        $this->assertStringContainsString('examples/jenss/targets/opus-resource-map-target.jss', $output);
        $this->assertStringContainsString('examples/jenss/targets/opus-linqr-target.jss', $output);
    }

    public function testProofReportsUnavailableContractManifest(): void
    {
        $manager = $this->managerWithMissingManifest();

        ob_start();
        $manager->proof();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString(
            'Runtime contract proof unavailable: Runtime contract manifest not found.',
            $output
        );
    }

    public function testTargetsReportsUnavailableContractManifest(): void
    {
        $manager = $this->managerWithMissingManifest();

        ob_start();
        $manager->targets();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString(
            'Runtime contract proof unavailable: Runtime contract manifest not found.',
            $output
        );
    }

    public function testValidateReportsUnavailableContractManifest(): void
    {
        $manager = $this->managerWithMissingManifest();

        ob_start();
        $manager->validate();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString(
            'Runtime contract proof unavailable: Runtime contract manifest not found.',
            $output
        );
    }

    private function managerWithMissingManifest(): RuntimeContractManager
    {
        return new RuntimeContractManager(
            new RuntimeContractProofService(
                dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'missing'
            )
        );
    }
}
