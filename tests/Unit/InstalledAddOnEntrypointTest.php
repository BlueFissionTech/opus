<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Business\Services\AddOnScaffoldService;
use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Net\HTTP;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class InstalledAddOnEntrypointTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opus-installed-' . bin2hex(random_bytes(6));
        mkdir($this->workspace . '/core/bin', 0777, true);
        mkdir($this->workspace . '/core/common/bootstrap', 0777, true);
        mkdir($this->workspace . '/core/app/Business/Services', 0777, true);
        mkdir($this->workspace . '/vendor', 0777, true);
        mkdir($this->workspace . '/addons', 0777, true);

        $packageRoot = dirname(__DIR__, 2);
        copy($packageRoot . '/bin/opus-addon.php', $this->workspace . '/core/bin/opus-addon.php');
        copy($packageRoot . '/common/bootstrap/runtime.php', $this->workspace . '/core/common/bootstrap/runtime.php');
        copy(
            $packageRoot . '/app/Business/Services/RuntimePathResolver.php',
            $this->workspace . '/core/app/Business/Services/RuntimePathResolver.php'
        );
        file_put_contents(
            $this->workspace . '/vendor/autoload.php',
            '<?php return require ' . var_export($packageRoot . '/vendor/autoload.php', true) . ";\n"
        );
    }

    protected function tearDown(): void
    {
        if (!FileSystem::directoryExists($this->workspace)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspace, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            if (!$path instanceof SplFileInfo) {
                continue;
            }
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->workspace);
    }

    public function testInstalledValidatorRunsDirectlyWithoutAnApplicationWrapper(): void
    {
        $generated = (new AddOnScaffoldService($this->workspace))->generate('runtime_probe', 'runtime_probe');
        $this->assertTrue($generated['created'], Arr::make($generated['errors'])->toJson());

        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                $this->workspace . '/core/bin/opus-addon.php',
                'validate',
                $this->workspace . '/runtime_probe',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->workspace
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame('', $errors);
        $this->assertSame(0, $exitCode, (string) $output);
        $result = HTTP::jsonDecode((string) $output, true, []);
        $this->assertTrue(Arr::make($result)->get('valid'));
    }

    public function testInstalledGeneratorPublishesInsideTheResolvedHost(): void
    {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                $this->workspace . '/core/bin/opus-addon.php',
                'generate',
                'runtime_probe',
                'addons/runtime_probe',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->workspace
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame('', $errors);
        $this->assertSame(0, $exitCode, (string) $output);
        $result = HTTP::jsonDecode((string) $output, true, []);
        $this->assertTrue(Arr::make($result)->get('created'));
        $this->assertFileExists($this->workspace . '/addons/runtime_probe/definition.json');
        $this->assertFileDoesNotExist($this->workspace . '/core/addons/runtime_probe/definition.json');
    }
}
