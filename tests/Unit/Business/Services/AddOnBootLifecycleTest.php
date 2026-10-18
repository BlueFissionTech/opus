<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AddOnScaffoldService;
use App\Business\Services\BootAddOnManager as AddOnManager;
use App\Business\Services\SelectedAddOnManager;
use BlueFission\BlueCore\Domain\AddOn\Models\AddOnModel;
use BlueFission\BlueCore\Engine;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AddOnBootLifecycleTest extends TestCase
{
    private string $workspace;
    private array $active = [];

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/opus-boot-' . bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700);
        $result = (new AddOnScaffoldService($this->workspace))->generate('boot_probe', 'boot_probe');
        $this->assertTrue($result['created'], json_encode($result['errors']));
        $this->active = [[
            'addon_id' => 1, 'name' => 'boot_probe', 'namespace' => 'AddOns\\BootProbe',
            'path' => $this->workspace . '/boot_probe', 'primary_file' => 'main.php', 'is_active' => 1,
            'version' => '0.1.0-alpha',
        ]];
        file_put_contents($this->workspace . '/boot_probe/mapping/api.php', <<<'PHP'
<?php
use BlueFission\Services\Mapping;
Mapping::add('/boot/probe', static fn (): string => 'ready', 'boot.probe', 'get')->gateway('auth');
PHP);
        $validation = (new \App\Business\Services\AddOnContractValidator())->validate($this->workspace . '/boot_probe');
        $this->assertTrue($validation['valid'], json_encode($validation['errors']));
    }

    protected function tearDown(): void
    {
        $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->workspace, \FilesystemIterator::SKIP_DOTS
        ), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->workspace);
    }

    public function testGeneratedFactoryRegistersRoutesAndThemesOnlyAtEngineLoaded(): void
    {
        $engine = Engine::instance();
        $manager = $this->manager();
        $manager->loadActivatedAddOns();
        $this->assertArrayNotHasKey('get', $engine->maps());
        $this->assertNull($engine->theme('boot_probe/default'));

        $engine->dispatch('OnAppLoaded');

        $mapping = $engine->maps()['get']['boot/probe'] ?? null;
        $this->assertNotNull($mapping, 'The generated factory must be consumed during engine boot.');
        $this->assertSame('ready', ($mapping->callable)());
        $this->assertSame(['auth'], $mapping->gateways());
        $this->assertSame(
            $this->workspace . '/boot_probe/resource/markup' . DIRECTORY_SEPARATOR,
            $engine->theme('boot_probe/default')->location
        );
        $theme = $engine->theme('boot_probe/default');
        $manager->loadActivatedAddOns();
        $this->manager()->loadActivatedAddOns();
        $engine->dispatch('OnAppLoaded');
        $this->assertSame($mapping, $engine->maps()['get']['boot/probe']);
        $this->assertSame($theme, $engine->theme('boot_probe/default'));
        $this->assertSame('registered', $manager->bootState('boot_probe'));
        $this->assertSame(1, $manager->bootContributions('boot_probe')['agents']['version']);
    }

    public function testInactiveAddonsNeverLoadTheFactory(): void
    {
        $engine = Engine::instance();
        $this->active = [];
        $manager = $this->manager();
        $manager->loadActivatedAddOns();
        $engine->dispatch('OnAppLoaded');
        $this->assertSame('unloaded', $manager->bootState('boot_probe'));
        $this->assertFalse(function_exists('AddOns\\BootProbe\\boot_probe_install'));
        $this->assertSame([], $engine->maps());
    }

    public function testDeactivationBeforeDispatchPreventsRegistration(): void
    {
        $engine = Engine::instance();
        $manager = $this->manager();
        $manager->loadActivatedAddOns();
        $this->active = [];
        $engine->dispatch('OnAppLoaded');
        $engine->dispatch('OnAppLoaded');
        $this->assertSame('skipped', $manager->bootState('boot_probe'));
        $this->assertFalse(function_exists('AddOns\\BootProbe\\boot_probe_install'));
        $this->assertSame([], $engine->maps());
        $this->assertNull($engine->theme('boot_probe/default'));
    }

    public function testMissingEntrypointAtDispatchFailsBeforePackageExecution(): void
    {
        $engine = Engine::instance();
        $manager = $this->manager();
        $manager->loadActivatedAddOns();
        unlink($this->workspace . '/boot_probe/main.php');
        $this->assertBootFailure($engine);
        $this->assertSame('failed', $manager->bootState('boot_probe'));
        $this->assertFalse(function_exists('AddOns\\BootProbe\\boot_probe_install'));
        $this->assertSame([], $engine->maps());
    }

    public function testRegistrationFailureNeverReplaysPartialEffectsOrLeaksTheException(): void
    {
        $this->prependRegistration(<<<'PHP'
$GLOBALS['boot_attempts'] = ($GLOBALS['boot_attempts'] ?? 0) + 1;
require dirname(__DIR__, 2) . '/mapping/api.php';
throw new \Error('synthetic-private-credential');
PHP);
        $engine = Engine::instance();
        $manager = $this->manager();
        $manager->loadActivatedAddOns();
        $this->assertBootFailure($engine);
        $mapping = $engine->maps()['get']['boot/probe'];
        $manager->loadActivatedAddOns();
        $this->assertBootFailure($engine);
        $this->assertSame(1, $GLOBALS['boot_attempts']);
        $this->assertSame($mapping, $engine->maps()['get']['boot/probe']);
        $this->assertSame('failed', $manager->bootState('boot_probe'));
        $this->assertSame([], $manager->bootContributions('boot_probe'));
        $this->assertNull($engine->theme('boot_probe/default'));
    }

    public function testReentrantDispatchDoesNotRunTheSameProviderAgain(): void
    {
        $this->prependRegistration(<<<'PHP'
$GLOBALS['boot_attempts'] = ($GLOBALS['boot_attempts'] ?? 0) + 1;
\BlueFission\BlueCore\Engine::instance()->dispatch('OnAppLoaded');
PHP);
        $engine = Engine::instance();
        $this->manager()->loadActivatedAddOns();
        $engine->dispatch('OnAppLoaded');
        $this->assertSame(1, $GLOBALS['boot_attempts']);
        $this->assertSame('ready', ($engine->maps()['get']['boot/probe']->callable)());
    }

    public function testSelectedProfileConsumesFactoryEvenAfterLifecycleHookLoading(): void
    {
        $engine = Engine::instance();
        require_once $this->workspace . '/boot_probe/main.php';
        $manager = $this->manager(SelectedAddOnManager::class);
        $manager->loadSelected(['boot_probe', 'boot_probe']);
        $engine->dispatch('OnAppLoaded');
        $this->assertSame('registered', $manager->bootState('boot_probe'));
        $this->assertSame('ready', ($engine->maps()['get']['boot/probe']->callable)());
    }

    public function testASeparateEngineBootRegistersThePackageAgain(): void
    {
        $this->prependRegistration('$GLOBALS["boot_attempts"] = ($GLOBALS["boot_attempts"] ?? 0) + 1;');
        $first = Engine::instance();
        $manager = $this->manager();
        $manager->loadActivatedAddOns();
        $first->dispatch('OnAppLoaded');
        $instances = new ReflectionProperty(\BlueFission\Services\Application::class, '_instances');
        $instances->setAccessible(true);
        $instances->setValue(null, []);
        $second = Engine::instance();
        $this->assertNotSame($first, $second);
        $manager->loadActivatedAddOns();
        $second->dispatch('OnAppLoaded');
        $this->assertSame(2, $GLOBALS['boot_attempts']);
        $this->assertNotSame($first->maps()['get']['boot/probe'], $second->maps()['get']['boot/probe']);
    }

    public function testChangedStoredIdentityIsNotRegisteredFromAnEarlierSelection(): void
    {
        $engine = Engine::instance();
        $manager = $this->manager();
        $manager->loadActivatedAddOns();
        $this->active[0]['addon_id'] = 2;
        $engine->dispatch('OnAppLoaded');
        $this->assertSame('skipped', $manager->bootState('boot_probe'));
        $this->assertSame([], $engine->maps());
    }

    public function testRegistrationClassFromAnotherFileFailsWithoutRedeclaration(): void
    {
        file_put_contents($this->workspace . '/foreign.php',
            '<?php namespace AddOns\\BootProbe\\Registration; final class AddOnRegistration {}');
        require $this->workspace . '/foreign.php';
        $engine = Engine::instance();
        $manager = $this->manager();
        $manager->loadActivatedAddOns();
        $this->assertBootFailure($engine);
        $this->assertSame('failed', $manager->bootState('boot_probe'));
        $this->assertSame([], $engine->maps());
    }

    private function prependRegistration(string $source): void
    {
        $file = $this->workspace . '/boot_probe/logic/Registration/AddOnRegistration.php';
        file_put_contents($file, str_replace('        return [', $source . "\n        return [", file_get_contents($file)));
    }

    private function assertBootFailure(Engine $engine): void
    {
        try {
            $engine->dispatch('OnAppLoaded');
            $this->fail('Failed registration must stop boot.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('addon_boot_registration_failed', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    private function manager(string $class = AddOnManager::class): AddOnManager
    {
        $model = $this->getMockBuilder(AddOnModel::class)->disableOriginalConstructor()
            ->onlyMethods(['getActivatedAddOns'])->getMock();
        $model->method('getActivatedAddOns')->willReturnCallback(fn (): array => $this->active);
        $manager = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(AddOnManager::class, '_model');
        $property->setAccessible(true);
        $property->setValue($manager, $model);
        return $manager;
    }
}
