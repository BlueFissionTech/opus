<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AddOnContractValidator;
use App\Business\Services\AddOnScaffoldService;
use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Net\HTTP;
use BlueFission\Str;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AddOnScaffoldServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opus-addon-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        if (FileSystem::directoryExists($this->workspace)) {
            $this->removeDirectory($this->workspace);
        }
    }

    public function testItGeneratesAndValidatesTheCanonicalStructure(): void
    {
        $service = new AddOnScaffoldService($this->workspace);
        $result = $service->generate('sample_tools', 'sample_tools');

        $this->assertTrue($result['created'], Arr::make($result['errors'])->toJson());
        $this->assertFileExists($this->workspace . '/sample_tools/resource/markup/default.vibe');
        $this->assertFileExists($this->workspace . '/sample_tools/datasources/structure/.gitkeep');
        $composer = Arr::make($this->readJson($this->workspace . '/sample_tools/composer.json'));
        $definition = Arr::make($this->readJson($this->workspace . '/sample_tools/definition.json'));
        $this->assertSame('proprietary', $composer->get('license'));
        $this->assertSame('bluefission/opus-addon-sample-tools', $composer->get('name'));
        $this->assertSame('sample_tools', Arr::make($composer->get('extra'))->get('installer-name'));
        $this->assertSame(
            'AddOns\\SampleTools\\Registration\\AddOnRegistration',
            Arr::make($definition->get('registration'))->get('class')
        );
        $this->assertSame(
            'default.vibe',
            Arr::make(Arr::make($definition->get('themes'))->get('default'))->get('entrypoint')
        );

        $validation = (new AddOnContractValidator())->validate($this->workspace . '/sample_tools');
        $this->assertTrue($validation['valid'], Arr::make($validation['errors'])->toJson());

        $menus = require $this->workspace . '/sample_tools/mapping/menus.php';
        $this->assertIsArray($menus);
        $this->assertSame([], $menus['register']());

        require $this->workspace . '/sample_tools/logic/Registration/AddOnRegistration.php';
        $factory = require $this->workspace . '/sample_tools/main.php';
        $registration = $factory();
        $this->assertCount(5, $registration->contributions());
    }

    public function testItRejectsUnsafeNamesWithoutWritingOutput(): void
    {
        $result = (new AddOnScaffoldService($this->workspace))->generate('../unsafe', 'unsafe');

        $this->assertFalse($result['created']);
        $this->assertSame('name_invalid', $result['errors'][0]['code']);
        $this->assertDirectoryDoesNotExist($this->workspace . '/unsafe');
    }

    public function testItProtectsExistingDestinations(): void
    {
        mkdir($this->workspace . '/existing');

        $result = (new AddOnScaffoldService($this->workspace))->generate('existing', 'existing');

        $this->assertFalse($result['created']);
        $this->assertSame('destination_exists', $result['errors'][0]['code']);
    }

    public function testInstalledDirectoryMustMatchTheLifecycleName(): void
    {
        mkdir($this->workspace . '/addons');

        $result = (new AddOnScaffoldService($this->workspace))->generate(
            'sample_tools',
            'addons/tools'
        );

        $this->assertFalse($result['created']);
        $this->assertSame('destination_name_mismatch', $result['errors'][0]['code']);
        $this->assertDirectoryDoesNotExist($this->workspace . '/addons/tools');
    }

    public function testPublicationDoesNotReplaceARacedDestination(): void
    {
        $staging = $this->workspace . '/staging';
        $destination = $this->workspace . '/raced';
        mkdir($staging);
        file_put_contents($staging . '/payload.txt', 'generated');
        mkdir($destination);
        file_put_contents($destination . '/owner.txt', 'existing');

        $service = new AddOnScaffoldService($this->workspace);
        $publish = new \ReflectionMethod($service, 'publish');

        $this->assertFalse($publish->invoke($service, $staging, $destination));
        $this->assertSame('existing', FileSystem::fileContents($destination . '/owner.txt'));
        $this->assertFileDoesNotExist($destination . '/payload.txt');
    }

    public function testPublicationLockKeepsTheStagedTreePrivate(): void
    {
        $staging = $this->workspace . '/staging';
        $destination = $this->workspace . '/published';
        mkdir($staging);
        file_put_contents($staging . '/definition.json', '{}');
        file_put_contents($staging . '/main.php', '<?php return [];');

        $service = new AddOnScaffoldService($this->workspace);
        $lockPath = (new \ReflectionMethod($service, 'publicationLockPath'))
            ->invoke($service, $destination);
        $lock = fopen($lockPath, 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $published = (new \ReflectionMethod($service, 'publish'))
                ->invoke($service, $staging, $destination);

            $this->assertFalse($published);
            $this->assertDirectoryDoesNotExist($destination);
            $this->assertFileExists($staging . '/definition.json');
            $this->assertFileExists($staging . '/main.php');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            unlink($lockPath);
        }
    }

    public function testItReportsInvalidComposerAndTemplateContracts(): void
    {
        $service = new AddOnScaffoldService($this->workspace);
        $service->generate('broken', 'broken');
        file_put_contents($this->workspace . '/broken/composer.json', '{"type":"library"}');
        file_put_contents($this->workspace . '/broken/resource/markup/legacy.html', '<h1>Legacy</h1>');
        file_put_contents($this->workspace . '/broken/resource/markup/default.vibe', '{#if ready}');
        file_put_contents($this->workspace . '/broken/mapping/api.php', "<?php\nreturn 'invalid';\n");
        file_put_contents(
            $this->workspace . '/broken/mapping/app.php',
            "<?php\nfile_put_contents('side-effect', 'run');\nreturn [];\n"
        );
        file_put_contents(
            $this->workspace . '/broken/mapping/console.php',
            "<?php\nreturn [file_put_contents('side-effect', 'run')];\n"
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/broken');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('composer_type', $codes);
        $this->assertContains('template_extension', $codes);
        $this->assertContains('template_syntax', $codes);
        $this->assertContains('mapping_contract', $codes);
        $this->assertGreaterThanOrEqual(3, Arr::make($codes)->filter(
            fn (string $code): bool => $code === 'mapping_contract'
        )->count());
    }

    public function testItAcceptsSupportedExecutableMappings(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('mapped', 'mapped');
        file_put_contents(
            $this->workspace . '/mapped/mapping/api.php',
            <<<'PHP'
<?php

declare(strict_types=1);

use BlueFission\Services\Mapping;

Mapping::add('/health', ['HealthController', 'index'], 'health', 'get')->gateway('auth');
PHP
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/mapped');

        $this->assertTrue($result['valid'], Arr::make($result['errors'])->toJson());
    }

    public function testItUsesDeclaredRegistrationAndDirectoryThemeEntrypoints(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('declared', 'declared');
        $root = $this->workspace . '/declared';
        mkdir($root . '/logic/Bootstrap');
        rename(
            $root . '/logic/Registration/AddOnRegistration.php',
            $root . '/logic/Bootstrap/PackageRegistration.php'
        );
        file_put_contents(
            $root . '/logic/Bootstrap/PackageRegistration.php',
            Str::make((string) FileSystem::fileContents($root . '/logic/Bootstrap/PackageRegistration.php'))
                ->replace('namespace AddOns\\Declared\\Registration;', 'namespace AddOns\\Declared\\Bootstrap;')
                ->replace('class AddOnRegistration', 'class PackageRegistration')
                ->val()
        );
        file_put_contents(
            $root . '/main.php',
            Str::make((string) FileSystem::fileContents($root . '/main.php'))
                ->replace(
                    'AddOns\\Declared\\Registration\\AddOnRegistration',
                    'AddOns\\Declared\\Bootstrap\\PackageRegistration'
                )
                ->replace('AddOnRegistration', 'PackageRegistration')
                ->val()
        );
        mkdir($root . '/resource/markup/default');
        rename(
            $root . '/resource/markup/default.vibe',
            $root . '/resource/markup/default/index.vibe'
        );

        $definition = Arr::make($this->readJson($root . '/definition.json'));
        $definition->set('registration', [
            'class' => 'AddOns\\Declared\\Bootstrap\\PackageRegistration',
            'factory' => 'main.php',
        ]);
        $definition->set('themes', [
            'default' => [
                'directory' => 'resource/markup/default',
                'entrypoint' => 'index.vibe',
            ],
        ]);
        file_put_contents($root . '/definition.json', Str::make($definition->toJson())->append(PHP_EOL)->val());

        $result = (new AddOnContractValidator())->validate($root);

        $this->assertTrue($result['valid'], Arr::make($result['errors'])->toJson());
    }

    public function testGeneratedBootstrapSupportsStandaloneAndInstalledPackages(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('standalone', 'standalone');
        mkdir($this->workspace . '/standalone/vendor');
        file_put_contents(
            $this->workspace . '/standalone/vendor/autoload.php',
            "<?php\n\$GLOBALS['opus_standalone_autoload'] = true;\n"
        );
        require $this->workspace . '/standalone/tests/bootstrap.php';
        $this->assertTrue((bool) ($GLOBALS['opus_standalone_autoload'] ?? false));

        mkdir($this->workspace . '/addons');
        mkdir($this->workspace . '/vendor');
        file_put_contents(
            $this->workspace . '/vendor/autoload.php',
            "<?php\n\$GLOBALS['opus_root_autoload'] = true;\n"
        );
        (new AddOnScaffoldService($this->workspace))->generate('installed', 'addons/installed');
        require $this->workspace . '/addons/installed/tests/bootstrap.php';
        $this->assertTrue((bool) ($GLOBALS['opus_root_autoload'] ?? false));
    }

    public function testItRejectsDestinationsOutsideTheWorkspace(): void
    {
        $result = (new AddOnScaffoldService($this->workspace))->generate(
            'outside',
            dirname($this->workspace) . DIRECTORY_SEPARATOR . 'outside'
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('inside', $result['errors'][0]['message']);
    }

    private function readJson(string $path): array
    {
        return HTTP::jsonDecode((string) FileSystem::fileContents($path), true, []);
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
