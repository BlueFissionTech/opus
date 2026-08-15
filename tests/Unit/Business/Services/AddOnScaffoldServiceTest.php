<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AddOnContractValidator;
use App\Business\Services\AddOnScaffoldService;
use BlueFission\Data\FileSystem;
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

        $this->assertTrue($result['created'], json_encode($result['errors']));
        $this->assertFileExists($this->workspace . '/sample_tools/resource/markup/default.vibe');
        $this->assertFileExists($this->workspace . '/sample_tools/datasources/structure/.gitkeep');
        $composer = json_decode((string) file_get_contents($this->workspace . '/sample_tools/composer.json'), true);
        $this->assertSame('proprietary', $composer['license']);

        $validation = (new AddOnContractValidator())->validate($this->workspace . '/sample_tools');
        $this->assertTrue($validation['valid'], json_encode($validation['errors']));

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

    public function testItReportsInvalidComposerAndTemplateContracts(): void
    {
        $service = new AddOnScaffoldService($this->workspace);
        $service->generate('broken', 'broken');
        file_put_contents($this->workspace . '/broken/composer.json', '{"type":"library"}');
        file_put_contents($this->workspace . '/broken/resource/markup/legacy.html', '<h1>Legacy</h1>');
        file_put_contents($this->workspace . '/broken/resource/markup/default.vibe', '{#if ready}');
        file_put_contents($this->workspace . '/broken/mapping/api.php', "<?php\nreturn 'invalid';\n");

        $result = (new AddOnContractValidator())->validate($this->workspace . '/broken');
        $codes = array_column($result['errors'], 'code');

        $this->assertFalse($result['valid']);
        $this->assertContains('composer_type', $codes);
        $this->assertContains('template_extension', $codes);
        $this->assertContains('template_syntax', $codes);
        $this->assertContains('mapping_contract', $codes);
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
