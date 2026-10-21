<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\SelectedAddOnManager;
use BlueFission\BlueCore\Domain\AddOn\Models\AddOnModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SelectedAddOnManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/opus-selected-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        define('APP_ROOT', $this->directory);
        define('PROJECT_ROOT', $this->directory);
        mkdir($this->directory . '/addons/selected', 0700, true);
        mkdir($this->directory . '/addons/other', 0700, true);
        file_put_contents($this->directory . '/addons/selected/main.php', '<?php $GLOBALS["profile_addon_runs"][] = "selected";');
        file_put_contents($this->directory . '/addons/other/main.php', '<?php $GLOBALS["profile_addon_runs"][] = "other";');
        file_put_contents($this->directory . '/outside.php', '<?php $GLOBALS["profile_addon_runs"][] = "outside";');
        $GLOBALS['profile_addon_runs'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['profile_addon_runs']);
        unlink($this->directory . '/addons/selected/main.php');
        unlink($this->directory . '/addons/other/main.php');
        unlink($this->directory . '/outside.php');
        rmdir($this->directory . '/addons/selected');
        rmdir($this->directory . '/addons/other');
        rmdir($this->directory . '/addons');
        rmdir($this->directory);
    }

    public function testOnlySelectedActiveEntrypointsRunAndDuplicatesRunOnce(): void
    {
        $this->manager()->loadSelected(['selected', 'selected']);
        $this->assertSame(['selected'], $GLOBALS['profile_addon_runs']);
    }

    public function testMissingSelectionFailsBeforeAnyEntrypointRuns(): void
    {
        try {
            $this->manager()->loadSelected(['selected', 'missing']);
            $this->fail('An unavailable selection must stop startup.');
        } catch (RuntimeException $exception) {
            $this->assertSame('selected_addon_unavailable', $exception->getMessage());
        }
        $this->assertSame([], $GLOBALS['profile_addon_runs']);
    }

    public function testUpstreamPathRejectionStopsTheWholeSelectionBeforeExecution(): void
    {
        try {
            $this->manager(true)->loadSelected(['selected', 'other']);
            $this->fail('A reachable file outside its canonical add-on root must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('selected_addon_unavailable', $exception->getMessage());
        }
        $this->assertSame([], $GLOBALS['profile_addon_runs']);
    }

    private function manager(bool $outside = false): SelectedAddOnManager
    {
        $model = $this->getMockBuilder(AddOnModel::class)->disableOriginalConstructor()
            ->onlyMethods(['getActivatedAddOns'])->getMock();
        $model->method('getActivatedAddOns')->willReturn([
            ['name' => 'selected', 'path' => $this->directory . '/addons/selected', 'primary_file' => 'main.php'],
            ['name' => 'other', 'path' => $outside ? $this->directory : $this->directory . '/addons/other',
                'primary_file' => $outside ? 'outside.php' : 'main.php'],
        ]);
        $manager = (new ReflectionClass(SelectedAddOnManager::class))->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(SelectedAddOnManager::class, '_model');
        $property->setAccessible(true);
        $property->setValue($manager, $model);
        return $manager;
    }
}
