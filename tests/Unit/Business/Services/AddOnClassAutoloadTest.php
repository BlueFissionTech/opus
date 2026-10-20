<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AddOnContractValidator;
use App\Business\Services\AddOnScaffoldService;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AddOnClassAutoloadTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/opus-class-contract-' . bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700);
        $result = (new AddOnScaffoldService($this->workspace))->generate('class_probe', 'class_probe');
        $this->assertTrue($result['created'], json_encode($result['errors']));
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspace, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->workspace);
    }

    /** @dataProvider declarations */
    public function testNamedDeclarationsMatchTheirPsr4Path(string $declaration, bool $valid): void
    {
        $root = $this->workspace . '/class_probe';
        $path = $root . '/logic/Domain/Values/Probe.php';
        file_put_contents($path, "<?php\nnamespace AddOn\\ClassProbe\\Domain\\Values;\n" . $declaration);
        $result = (new AddOnContractValidator())->validate($root);
        $this->assertSame($valid, $result['valid'], json_encode($result['errors']));
        if (!$valid) {
            $this->assertContains('class_file_mismatch', array_column($result['errors'], 'code'));
            $problem = array_values(array_filter(
                $result['errors'],
                static fn (array $error): bool => $error['code'] === 'class_file_mismatch'
            ))[0];
            $this->assertSame('logic/Domain/Values/Probe.php', $problem['path']);
        }
        $this->assertFileDoesNotExist(dirname($path) . '/executed.txt');
        $this->assertFalse(class_exists('AddOn\\ClassProbe\\Domain\\Values\\Probe', false));
    }

    public static function declarations(): array
    {
        return [
            'class' => ['final class Probe {}', true],
            'abstract class' => ['abstract class Probe {}', true],
            'readonly class' => ['readonly class Probe {}', true],
            'interface' => ['interface Probe {}', true],
            'trait' => ['trait Probe {}', true],
            'enum' => ["enum Probe: string { case Ready = 'ready'; }", true],
            'wrong class' => ['class Other {}', false],
            'wrong interface' => ['interface Other {}', false],
            'wrong trait' => ['trait Other {}', false],
            'wrong enum' => ['enum Other {}', false],
            'case matters' => ['class probe {}', false],
            'second named declaration' => ['class Probe {} class Hidden {}', false],
            'later namespace' => ['namespace Other; class Probe {}', false],
            'anonymous and class constants' => [
                'class Probe { public function create(): object { return new class {}; } '
                    . 'public function name(): string { return self::class; } }', true,
            ],
            'functions only' => ['function probe(): void {}', true],
            'never execute source' => ["file_put_contents(__DIR__ . '/executed.txt', 'ran'); class Probe {}", true],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAcceptedDeclarationLoadsThroughTheRealComposerPsr4Loader(): void
    {
        $root = $this->workspace . '/class_probe';
        $path = $root . '/logic/Domain/Values/Available.php';
        file_put_contents(
            $path,
            '<?php namespace AddOn\\ClassProbe\\Domain\\Values; final class Available {}'
        );
        $result = (new AddOnContractValidator())->validate($root);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $class = 'AddOn\\ClassProbe\\Domain\\Values\\Available';
        $this->assertFalse(class_exists($class, false));

        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('AddOn\\ClassProbe\\', $root . '/logic');
        $this->assertTrue($loader->loadClass($class));
        $this->assertTrue(class_exists($class, false));
        $this->assertSame(realpath($path), (new \ReflectionClass($class))->getFileName());
    }
}
