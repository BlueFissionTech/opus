<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Business\Services\RuntimeContractProofService;
use BlueFission\Arr;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RuntimeContractProofServiceTest extends TestCase
{
    public function testReadinessReportDescribesRuntimeContractProof(): void
    {
        $service = new RuntimeContractProofService(dirname(__DIR__, 2));
        $report = Arr::make($service->readinessReport());

        $this->assertSame('opus-runtime-contract-proof', $report->get('name'));
        $this->assertSame('jenerator', $report->get('runtime'));
        $this->assertSame(5, $report->get('script_count'));
        $this->assertSame(3, $report->get('required_count'));
        $this->assertSame(2, $report->get('optional_count'));
        $this->assertSame([], $report->get('missing'));
        $this->assertSame([], $report->get('invalid'));
        $this->assertTrue($report->get('ready'));
    }

    public function testOptionalTargetsExposeInterpreterMilestones(): void
    {
        $service = new RuntimeContractProofService(dirname(__DIR__, 2));
        $paths = Arr::make($service->optionalTargets())
            ->map(fn ($target) => Arr::make($target)->get('path'))
            ->values()
            ->val();

        $this->assertContains('examples/jenss/targets/opus-resource-map-target.jss', $paths);
        $this->assertContains('examples/jenss/targets/opus-linqr-target.jss', $paths);
    }

    public function testReadinessReportTracksInvalidAndMissingContractEntries(): void
    {
        $root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures'
            . DIRECTORY_SEPARATOR . 'runtime-contract-invalid';
        $service = new RuntimeContractProofService($root);
        $report = Arr::make($service->readinessReport());

        $this->assertFalse($report->get('ready'));
        $this->assertSame([
            'script entry is not an object',
            'script entry is missing a path',
        ], $report->get('invalid'));
        $this->assertSame([
            'examples/jenss/missing.jss',
            'examples/jenss/fixtures/missing.json',
        ], $report->get('missing'));
    }

    public function testMissingManifestRaisesAContractError(): void
    {
        $service = new RuntimeContractProofService(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'missing'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Runtime contract manifest not found.');

        $service->manifest();
    }
}
