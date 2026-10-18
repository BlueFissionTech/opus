<?php

declare(strict_types=1);

namespace Tests\Unit;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Str;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\NativeHelperCalls;

final class DevElationHelperUsageTest extends TestCase
{
    private const FORBIDDEN_APPLICATION_HELPERS = [
        'json_encode' => 'native JSON helper',
        'json_decode' => 'native JSON helper',
        'array_keys' => 'native array inspection helper',
        'array_column' => 'native array inspection helper',
        'array_values' => 'native array inspection helper',
        'is_array' => 'native array inspection helper',
        'str_replace' => 'native string transformation helper',
        'strtolower' => 'native string transformation helper',
        'strtoupper' => 'native string transformation helper',
        'str_starts_with' => 'native string transformation helper',
        'str_ends_with' => 'native string transformation helper',
        'trim' => 'native string normalization helper',
        'strpos' => 'native string inspection helper',
        'explode' => 'native string splitting helper',
        'implode' => 'native array joining helper',
        'count' => 'native array counting helper',
        'compact' => 'native array construction helper',
    ];

    public function testApplicationCodeUsesPackageOwnedValueHelpers(): void
    {
        $violations = Arr::make([]);

        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = FileSystem::fileContents($file->getPathname());
            if (!Str::is($source)) {
                $violations->push($file->getPathname() . ': unreadable');
                continue;
            }

            $calls = NativeHelperCalls::find($source, self::FORBIDDEN_APPLICATION_HELPERS);
            if ($file->getPathname() === $root . DIRECTORY_SEPARATOR . 'Business'
                . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'RuntimePathResolver.php') {
                // This resolver loads Composer itself. Freeze its reviewed native boundary.
                $this->assertSame([
                    'str_ends_with' => 2, 'strtolower' => 2, 'json_decode' => 1,
                    'is_array' => 1, 'str_starts_with' => 4, 'explode' => 2, 'implode' => 2,
                ], array_count_values(array_column($calls, 'name')), 'Review changes to the pre-autoload boundary.');
                continue;
            }
            foreach ($calls as $call) {
                $violations->push(
                    $file->getPathname() . ':' . $call['line'] . ': ' . $call['reason'] . ' (' . $call['name'] . ')'
                );
            }
        }

        $this->assertSame([], $violations->val(), $violations->join(PHP_EOL)->val());
    }

    public function testBootstrapResolverWorksWithoutComposerOrDevelation(): void
    {
        $root = dirname(__DIR__, 2);
        $source = <<<'PHP'
require $argv[1];
$resolver = new App\Business\Services\RuntimePathResolver($argv[2], $argv[2]);
echo json_encode([
    $resolver->packageRoot(),
    App\Business\Services\RuntimePathResolver::packageInstallRootFromEntrypoint($argv[2] . '/terminal'),
    class_exists('BlueFission\\Arr', false),
    spl_autoload_functions(),
]);
PHP;
        $process = proc_open([PHP_BINARY, '-n', '-r', $source,
            $root . '/app/Business/Services/RuntimePathResolver.php', $root],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame([realpath($root), realpath($root), false, []], json_decode($output, true));
    }

}
