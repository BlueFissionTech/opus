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

    public function testItRejectsNamesThatCannotProduceValidComposerSlugs(): void
    {
        $service = new AddOnScaffoldService($this->workspace);

        foreach (['sample_', 'sample__tools'] as $name) {
            $result = $service->generate($name, $name);

            $this->assertFalse($result['created']);
            $this->assertSame('name_invalid', $result['errors'][0]['code']);
            $this->assertDirectoryDoesNotExist($this->workspace . '/' . $name);
        }
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

    public function testValidatorRejectsARenamedInstalledDirectory(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('sample_tools', 'sample_tools');
        mkdir($this->workspace . '/addons');
        rename($this->workspace . '/sample_tools', $this->workspace . '/addons/tools');

        $result = (new AddOnContractValidator())->validate($this->workspace . '/addons/tools');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('installed_identity', $codes);
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
        file_put_contents(
            $this->workspace . '/broken/mapping/default.php',
            "<?php\nreturn [\\file_put_contents('side-effect', 'run')];\n"
        );
        file_put_contents(
            $this->workspace . '/broken/mapping/menus.php',
            <<<'PHP'
<?php

return [(static function (): array {
    file_put_contents('side-effect', 'run');
    return [];
})()];
PHP
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
        $this->assertGreaterThanOrEqual(5, Arr::make($codes)->filter(
            fn (string $code): bool => $code === 'mapping_contract'
        )->count());
    }

    public function testItAcceptsLazyArrowClosuresInDeclarativeMappings(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('lazy', 'lazy');
        file_put_contents(
            $this->workspace . '/lazy/mapping/menus.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'register' => static fn (): array => service('navigation')->all(),
];
PHP
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/lazy');

        $this->assertTrue($result['valid'], Arr::make($result['errors'])->toJson());
    }

    public function testNestedArrowClosuresCannotHideAnEagerSiblingExpression(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('nested_arrow', 'nested_arrow');
        file_put_contents(
            $this->workspace . '/nested_arrow/mapping/api.php',
            <<<'PHP'
<?php

return [
    static fn (): callable => static fn (): int => 1,
    file_put_contents('side-effect', 'run'),
];
PHP
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/nested_arrow');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('mapping_contract', $codes);
    }

    public function testItRejectsIndirectEagerCallableExpressions(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('indirect', 'indirect');
        file_put_contents(
            $this->workspace . '/indirect/mapping/api.php',
            "<?php\nreturn [(('file_put_contents'))('side-effect', 'run')];\n"
        );
        file_put_contents(
            $this->workspace . '/indirect/mapping/app.php',
            "<?php\nreturn [(['Example', 'run'])()];\n"
        );
        file_put_contents(
            $this->workspace . '/indirect/mapping/default.php',
            "<?php\nreturn [true()];\n"
        );
        file_put_contents(
            $this->workspace . '/indirect/mapping/console.php',
            "<?php\nreturn [stdClass::class()];\n"
        );
        file_put_contents(
            $this->workspace . '/indirect/mapping/menus.php',
            "<?php\nreturn [(static function (): void {})[0]];\n"
        );
        file_put_contents(
            $this->workspace . '/indirect/mapping/arrow-key.php',
            "<?php\nreturn [static fn (): int => 1 => file_put_contents('side-effect', 'run')];\n"
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/indirect');
        $mappingErrors = Arr::make($result['errors'])->filter(
            fn (array $error): bool => Arr::make($error)->get('code') === 'mapping_contract'
        );

        $this->assertFalse($result['valid']);
        $this->assertCount(6, $mappingErrors->val());
    }

    public function testItRejectsShellExpressionsInDeclarativeMappings(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('shell', 'shell');
        file_put_contents(
            $this->workspace . '/shell/mapping/api.php',
            "<?php\nreturn [`echo unsafe`];\n"
        );
        file_put_contents(
            $this->workspace . '/shell/mapping/app.php',
            "<?php\nreturn [print 'unsafe'];\n"
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/shell');
        $mappingErrors = Arr::make($result['errors'])->filter(
            fn (array $error): bool => Arr::make($error)->get('code') === 'mapping_contract'
        );

        $this->assertFalse($result['valid']);
        $this->assertCount(2, $mappingErrors->val());
    }

    public function testItRejectsUnsafeEagerOperators(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('operator', 'operator');
        file_put_contents(
            $this->workspace . '/operator/mapping/api.php',
            "<?php\nreturn [1 / 0];\n"
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/operator');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('mapping_contract', $codes);
    }

    public function testItValidatesEveryDeclaredLibraryName(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('libraries', 'libraries');
        $definitionPath = $this->workspace . '/libraries/definition.json';
        $definition = Arr::make($this->readJson($definitionPath));
        $definition->set('libraries', ['bluefission/develation', 'not-a-package']);
        file_put_contents(
            $definitionPath,
            Str::make($definition->toJson())->append(PHP_EOL)->val()
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/libraries');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('libraries_manifest', $codes);
    }

    public function testItAcceptsClassReferencesAsSafeDeclarativeValues(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('class_ref', 'class_ref');
        file_put_contents(
            $this->workspace . '/class_ref/mapping/api.php',
            "<?php\nreturn ['handler' => HealthController::class];\n"
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/class_ref');

        $this->assertTrue($result['valid'], Arr::make($result['errors'])->toJson());
    }

    public function testItRejectsUnsafeExecutableMappingArguments(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('executable', 'executable');
        file_put_contents(
            $this->workspace . '/executable/mapping/api.php',
            <<<'PHP'
<?php

use BlueFission\Services\Mapping;

Mapping::add('/x', ['Controller', 'index'], 1 / 0, 'get');
PHP
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/executable');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('mapping_contract', $codes);
    }

    public function testRegistrationFactoryMustBeTheCompleteReturnExpression(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('factory', 'factory');
        $main = $this->workspace . '/factory/main.php';
        file_put_contents(
            $main,
            Str::make((string) FileSystem::fileContents($main))
                ->replace(
                    'return static fn (): AddOnRegistration => new AddOnRegistration();',
                    <<<'PHP'
return static function (): AddOnRegistration {
    return new AddOnRegistration();
} ? 1 : 0;
PHP
                )
                ->val()
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/factory');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('registration_factory', $codes);
    }

    public function testRegistrationFactoryMustProduceTheConfiguredClass(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('factory_result', 'factory_result');
        $main = $this->workspace . '/factory_result/main.php';
        file_put_contents(
            $main,
            Str::make((string) FileSystem::fileContents($main))
                ->replace(
                    'return static fn (): AddOnRegistration => new AddOnRegistration();',
                    'return static fn (): int => 1;'
                )
                ->val()
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/factory_result');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('registration_factory', $codes);
    }

    public function testRegistrationClassMustBeDeclaredAtNamespaceScope(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('nested_registration', 'nested_registration');
        $registration = $this->workspace
            . '/nested_registration/logic/Registration/AddOnRegistration.php';
        file_put_contents(
            $registration,
            Str::make((string) FileSystem::fileContents($registration))
                ->replace(
                    'final class AddOnRegistration',
                    "function declare_registration(): void\n{\nfinal class AddOnRegistration"
                )
                ->append("\n}\n")
                ->val()
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/nested_registration');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('registration_class', $codes);
    }

    public function testRegistrationClassCannotBeConditionallyDeclaredWithAlternativeSyntax(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('conditional_registration', 'conditional_registration');
        $registration = $this->workspace
            . '/conditional_registration/logic/Registration/AddOnRegistration.php';
        file_put_contents(
            $registration,
            Str::make((string) FileSystem::fileContents($registration))
                ->replace('final class AddOnRegistration', "if (false):\nfinal class AddOnRegistration")
                ->append("\nendif;\n")
                ->val()
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/conditional_registration');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('registration_class', $codes);
    }

    public function testRegistrationClassMustBelongToItsDeclaredNamespace(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('split_namespace', 'split_namespace');
        $registration = $this->workspace
            . '/split_namespace/logic/Registration/AddOnRegistration.php';
        file_put_contents(
            $registration,
            <<<'PHP'
<?php

declare(strict_types=1);

namespace AddOns\SplitNamespace\Registration {
}

namespace Other {
    final class AddOnRegistration
    {
    }
}
PHP
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/split_namespace');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('registration_class', $codes);
    }

    public function testRegistrationFactoryRejectsTopLevelExecution(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('factory_execution', 'factory_execution');
        $root = $this->workspace . '/factory_execution';
        $main = $root . '/main.php';
        $marker = $root . '/executed.txt';
        $eagerWrite = 'file_put_contents(' . var_export($marker, true) . ", 'run');";
        file_put_contents(
            $main,
            Str::make((string) FileSystem::fileContents($main))
                ->replace(
                    'return static fn (): AddOnRegistration => new AddOnRegistration();',
                    $eagerWrite . "\n"
                        . 'return static fn (): AddOnRegistration => new AddOnRegistration();'
                )
                ->val()
        );

        $result = (new AddOnContractValidator())->validate($root);
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('registration_factory', $codes);
        $this->assertFalse(FileSystem::fileExists($marker));
    }

    public function testRegistrationFactoryHonorsImportedAliases(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('factory_alias', 'factory_alias');
        $main = $this->workspace . '/factory_alias/main.php';
        file_put_contents(
            $main,
            Str::make((string) FileSystem::fileContents($main))
                ->replace(
                    'use AddOns\\FactoryAlias\\Registration\\AddOnRegistration;',
                    'use AddOns\\FactoryAlias\\Registration\\AddOnRegistration as RenamedRegistration;'
                )
                ->val()
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/factory_alias');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('registration_factory', $codes);
    }

    public function testRegistrationFactoryIgnoresTraitUseStatementsWhenResolvingImports(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('trait_import', 'trait_import');
        $main = $this->workspace . '/trait_import/main.php';
        file_put_contents(
            $main,
            Str::make((string) FileSystem::fileContents($main))
                ->replace('use AddOns\\TraitImport\\Registration\\AddOnRegistration;', '')
                ->replace(
                    'function trait_import_install(): void',
                    <<<'PHP'
function trait_import_decoy(): void
{
    class Decoy
    {
        use AddOns\TraitImport\Registration\AddOnRegistration;
    }
}

function trait_import_install(): void
PHP
                )
                ->val()
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/trait_import');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('registration_factory', $codes);
    }

    public function testRegistrationFactoryAcceptsInterpolationInsideLifecycleHooks(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('hook_interpolation', 'hook_interpolation');
        $main = $this->workspace . '/hook_interpolation/main.php';
        $source = Str::make((string) FileSystem::fileContents($main))
            ->replace(
                'use AddOns\\HookInterpolation\\Registration\\AddOnRegistration;',
                <<<'PHP'
use function Vendor\Package\prepare;
use const Vendor\Package\STATUS;

use AddOns\HookInterpolation\Registration\AddOnRegistration;
PHP
            )
            ->replace(
                "function hook_interpolation_install(): void\n{\n}",
                <<<'PHP'
function hook_interpolation_install(): void
{
    $name = 'hook_interpolation';
    $message = "Installing {$name}";
}
PHP
            )
            ->val();
        file_put_contents(
            $main,
            $source
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/hook_interpolation');

        $this->assertStringContainsString('use function Vendor\\Package\\prepare;', $source);
        $this->assertStringContainsString('use const Vendor\\Package\\STATUS;', $source);
        $this->assertTrue($result['valid'], Arr::make($result['errors'])->toJson());
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
Mapping::add('/lazy', static function (): array {
    return [];
}, 'lazy', 'get');
Mapping::add('/nested', static function (): array {
    $inner = static function (): array {
        return [];
    };

    return $inner();
}, 'nested', 'get');
Mapping::add('/nested-arrow', static fn (): array => helper(
    static fn (): array => [],
    service('items')
), 'nested-arrow', 'get');
PHP
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/mapped');

        $this->assertTrue($result['valid'], Arr::make($result['errors'])->toJson());
    }

    public function testItAcceptsInterpolatedStringsInsideDeferredMappings(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('interpolation', 'interpolation');
        file_put_contents(
            $this->workspace . '/interpolation/mapping/api.php',
            <<<'PHP'
<?php

use BlueFission\Services\Mapping;

Mapping::add(
    '/hello',
    static fn (string $name): string => "Hello {$name}",
    'hello',
    'get'
);
PHP
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/interpolation');

        $this->assertTrue($result['valid'], Arr::make($result['errors'])->toJson());
    }

    public function testExecutableMappingsRequireTheShortNameImport(): void
    {
        (new AddOnScaffoldService($this->workspace))->generate('missing_import', 'missing_import');
        file_put_contents(
            $this->workspace . '/missing_import/mapping/api.php',
            <<<'PHP'
<?php

Mapping::add('/health', ['HealthController', 'index'], 'health', 'get');
PHP
        );

        $result = (new AddOnContractValidator())->validate($this->workspace . '/missing_import');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => (string) Arr::make($error)->get('code'))
            ->val();

        $this->assertFalse($result['valid']);
        $this->assertContains('mapping_contract', $codes);
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
