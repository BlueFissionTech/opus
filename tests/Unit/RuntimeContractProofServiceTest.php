<?php

namespace Tests\Unit;

use App\Business\Services\RuntimeContractProofService;
use PHPUnit\Framework\TestCase;

class RuntimeContractProofServiceTest extends TestCase
{
    public function testReadinessReportDescribesRuntimeContractProof(): void
    {
        $service = new RuntimeContractProofService(dirname(__DIR__, 2));
        $report = $service->readinessReport();

        $this->assertSame('opus-runtime-contract-proof', $report['name']);
        $this->assertSame('jenerator', $report['runtime']);
        $this->assertSame(5, $report['script_count']);
        $this->assertSame(3, $report['required_count']);
        $this->assertSame(2, $report['optional_count']);
        $this->assertSame([], $report['missing']);
        $this->assertSame([], $report['invalid']);
        $this->assertTrue($report['ready']);
    }

    public function testOptionalTargetsExposeInterpreterMilestones(): void
    {
        $service = new RuntimeContractProofService(dirname(__DIR__, 2));
        $targets = $service->optionalTargets();
        $paths = array_column($targets, 'path');

        $this->assertContains('examples/jenss/targets/opus-resource-map-target.jss', $paths);
        $this->assertContains('examples/jenss/targets/opus-linqr-target.jss', $paths);
    }
}
