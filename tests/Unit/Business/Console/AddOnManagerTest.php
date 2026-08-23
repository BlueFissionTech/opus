<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Console;

use App\Business\Console\AddOnManager;
use PHPUnit\Framework\TestCase;

final class AddOnManagerTest extends TestCase
{
    public function testInstallReturnsStructuredReadinessWithoutRenderingArrays(): void
    {
        $manager = new class {
            public function install(string $name): array
            {
                return [
                    'ok' => true,
                    'action' => 'install',
                    'addon' => $name,
                    'changed' => false,
                    'hooks' => [],
                    'migrations' => ['ok' => false, 'error' => 'Schema failed.'],
                    'population' => [],
                ];
            }
        };
        $command = new AddOnManager($manager);
        $behavior = (object) ['context' => ['data' => 'Sample']];

        ob_start();
        $result = $command->install($behavior);
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertFalse($result['ok']);
        $this->assertSame('sample', $result['addon']);
        $this->assertSame('addon_migration_failed', $result['readiness']['reasons'][0]['code']);
    }

    public function testMissingAddOnNameReturnsStableRequestFailure(): void
    {
        $command = new AddOnManager(new \stdClass());

        $result = $command->install((object) ['context' => []]);

        $this->assertFalse($result['ok']);
        $this->assertSame('request', $result['stage']);
        $this->assertSame('addon_name_required', $result['readiness']['reasons'][0]['code']);
    }

    public function testShowReturnsAStableCollectionSummary(): void
    {
        $manager = new class {
            public function showAllAddOns(): array
            {
                return [
                    'first' => (object) ['name' => 'first'],
                    'second' => (object) ['name' => 'second'],
                ];
            }
        };
        $command = new AddOnManager($manager);

        $result = $command->showAll();

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['results']);
    }
}
