<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AddOnContractValidator;
use App\Business\Services\AddOnScaffoldService;
use App\Business\Services\AddOnValidationProfile;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AddOnValidationProfileTest extends TestCase
{
    private string $host;
    private string $root;

    protected function setUp(): void
    {
        $this->host = sys_get_temp_dir() . '/opus-profile-' . bin2hex(random_bytes(8));
        mkdir($this->host . '/addons', 0700, true);
        mkdir($this->host . '/tests');
        $this->root = $this->host . '/addons/profile_probe';
        $result = (new AddOnScaffoldService($this->host))->generate('profile_probe', 'addons/profile_probe');
        $this->assertTrue($result['created'], json_encode($result['errors']));
        $this->writeHostComposer('addons/profile_probe/logic/');
        file_put_contents($this->host . '/phpunit.xml', '<phpunit bootstrap="tests/bootstrap.php"/>');
        file_put_contents($this->host . '/tests/bootstrap.php', '<?php throw new RuntimeException("must not execute");');
    }

    protected function tearDown(): void
    {
        $this->remove($this->host);
    }

    public function testPackageRemainsDefaultAndDoesNotInheritAmbientHostFiles(): void
    {
        $this->assertTrue((new AddOnContractValidator())->validate($this->root)['valid']);
        $this->removeLocalTooling();
        $result = (new AddOnContractValidator())->validate($this->root);
        $this->assertFalse($result['valid']);
        $this->assertContains('profile_file_missing', array_column($result['errors'], 'code'));
    }

    public function testEmbeddedProfileInheritsExplicitHostToolingWithoutExecution(): void
    {
        $this->removeLocalTooling();
        $result = $this->validate();
        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame(3, count(array_filter(
            $result['warnings'], static fn (array $warning): bool => $warning['code'] === 'host_tooling_inherited'
        )));
        $this->assertFileDoesNotExist($this->root . '/composer.json');
    }

    public function testBadLocalComposerIsNotHiddenByHostInheritance(): void
    {
        file_put_contents($this->root . '/composer.json', '{invalid');
        $result = $this->validate();
        $this->assertFalse($result['valid']);
        $this->assertContains('profile_composer_invalid', array_column($result['errors'], 'code'));
    }

    public function testDirectoryCannotMasqueradeAsMissingLocalTooling(): void
    {
        unlink($this->root . '/phpunit.xml');
        mkdir($this->root . '/phpunit.xml');
        $result = $this->validate();
        $this->assertFalse($result['valid']);
        $this->assertContains('profile_file_missing', array_column($result['errors'], 'code'));
    }

    public function testToolingLinkCannotEscapeItsOwningRoot(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symbolic-link creation requires a Windows privilege; POSIX CI covers link ownership.');
        }
        unlink($this->root . '/phpunit.xml');
        $link = $this->root . '/phpunit.xml';
        $this->assertTrue(symlink($this->host . '/phpunit.xml', $link));
        try {
            $result = $this->validate();
            $this->assertFalse($result['valid']);
            $this->assertContains('path_ownership', array_column($result['errors'], 'code'));
            $package = (new AddOnContractValidator())->validate($this->root);
            $this->assertContains('path_ownership', array_column($package['errors'], 'code'));
        } finally {
            unlink($link);
        }
    }

    /** @dataProvider invalidHostContexts */
    public function testEmbeddedProfileRequiresContainingHost(string $choice): void
    {
        $this->removeLocalTooling();
        $host = match ($choice) {
            'missing' => null,
            'same' => $this->root,
            'sibling' => $this->host . '/tests',
            'null-byte' => $this->host . "\0",
        };
        $result = (new AddOnContractValidator())->validate($this->root, 'embedded', $host);
        $this->assertFalse($result['valid']);
        $this->assertContains('embedded_host', array_column($result['errors'], 'code'));
    }

    public static function invalidHostContexts(): array
    {
        return [['missing'], ['same'], ['sibling'], ['null-byte']];
    }

    public function testUnknownProfileFailsInsteadOfFallingBack(): void
    {
        $result = (new AddOnContractValidator())->validate($this->root, 'automatic', $this->host);
        $this->assertFalse($result['valid']);
        $this->assertContains('validation_profile', array_column($result['errors'], 'code'));
    }

    public function testHostAutoloadMustReachThisPackagesLogic(): void
    {
        $this->removeLocalTooling();
        $this->writeHostComposer('addons/profile_probe/');
        $result = $this->validate();
        $this->assertFalse($result['valid']);
        $this->assertContains('host_psr4_mapping', array_column($result['errors'], 'code'));
        $this->writeHostComposer(['other/', 'addons/profile_probe/logic/']);
        $this->assertTrue($this->validate()['valid']);
    }

    public function testMissingHostToolingFails(): void
    {
        $this->removeLocalTooling();
        unlink($this->host . '/tests/bootstrap.php');
        $result = $this->validate();
        $this->assertFalse($result['valid']);
        $this->assertContains('profile_file_missing', array_column($result['errors'], 'code'));
    }

    public function testUnusedLayersAreOptionalAndDeclaredLayersAreRequired(): void
    {
        foreach (AddOnValidationProfile::LAYERS as $path) {
            $this->remove($this->root . '/' . $path);
        }
        $this->assertTrue((new AddOnContractValidator())->validate($this->root)['valid']);
        $this->definition(['layers' => ['models', 'values']]);
        $result = $this->validate();
        $this->assertFalse($result['valid']);
        $this->assertSame(['logic/Domain/Models', 'logic/Domain/Values'], array_column(
            array_filter($result['errors'], static fn (array $error): bool => $error['code'] === 'layer_missing'), 'path'
        ));
    }

    public function testLifecycleDatasourceDirectoriesRemainRequired(): void
    {
        $this->remove($this->root . '/datasources/generator');
        $result = $this->validate();
        $this->assertFalse($result['valid']);
        $this->assertContains('directory_missing', array_column($result['errors'], 'code'));
    }

    /** @dataProvider malformedLayers */
    public function testMalformedLayerDeclarationsFail(mixed $layers): void
    {
        $this->definition(['layers' => $layers]);
        $result = $this->validate();
        $this->assertFalse($result['valid']);
        $this->assertContains('layers_manifest', array_column($result['errors'], 'code'));
    }

    public static function malformedLayers(): array
    {
        return [[null], ['models'], [['unknown']], [['models', 'models']], [[3]], [['models' => true]]];
    }

    public function testLegacyLayerDeclarationGetsWarningWithoutHidingSourceErrors(): void
    {
        $definition = json_decode(file_get_contents($this->root . '/definition.json'), true);
        unset($definition['layers']);
        file_put_contents($this->root . '/definition.json', json_encode($definition));
        $result = $this->validate();
        $this->assertTrue($result['valid']);
        $this->assertContains('layers_undeclared', array_column($result['warnings'], 'code'));
        file_put_contents($this->root . '/logic/Domain/Values/Expected.php', '<?php namespace Other; class Wrong {}');
        file_put_contents($this->root . '/mapping/app.php', '<?php return "bad";');
        file_put_contents($this->root . '/resource/markup/legacy.html', '<p>bad extension</p>');
        $result = $this->validate();
        $this->assertFalse($result['valid']);
        foreach (['namespace_mismatch', 'class_file_mismatch', 'mapping_contract', 'template_extension'] as $code) {
            $this->assertContains($code, array_column($result['errors'], 'code'));
        }
    }

    private function validate(): array
    {
        return (new AddOnContractValidator())->validate($this->root, 'embedded', $this->host);
    }

    private function definition(array $changes): void
    {
        $definition = json_decode(file_get_contents($this->root . '/definition.json'), true);
        file_put_contents($this->root . '/definition.json', json_encode(array_replace($definition, $changes)));
    }

    private function writeHostComposer(string|array $paths): void
    {
        file_put_contents($this->host . '/composer.json', json_encode([
            'name' => 'example/host', 'type' => 'project',
            'autoload' => ['psr-4' => ['AddOns\\ProfileProbe\\' => $paths]],
        ]));
    }

    private function removeLocalTooling(): void
    {
        foreach (['composer.json', 'phpunit.xml', 'tests/bootstrap.php'] as $path) {
            unlink($this->root . '/' . $path);
        }
    }

    private function remove(string $directory): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
