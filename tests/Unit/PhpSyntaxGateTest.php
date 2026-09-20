<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PhpSyntaxGateTest extends TestCase
{
    public function testGateCompilesTrackedWorkingFilesAndRejectsInvalidCheckout(): void
    {
        $root = sys_get_temp_dir() . '/opus-lint-' . bin2hex(random_bytes(8));
        mkdir($root);
        $gate = dirname(__DIR__, 2) . '/bin/lint-php.php';
        try {
            self::assertSame(1, $this->runProcess([PHP_BINARY, $gate, $root])[0]);
            self::assertSame(0, $this->runProcess(['git', 'init', '--quiet', $root])[0]);
            self::assertSame(1, $this->runProcess([PHP_BINARY, $gate, $root])[0]);
            file_put_contents($root . '/with spaces.php', '<?php return 1;');
            self::assertSame(0, $this->runProcess(['git', '-C', $root, 'add', '--', 'with spaces.php'])[0]);
            file_put_contents($root . '/untracked.php', '<?php function broken(');
            [$status, $output] = $this->runProcess([PHP_BINARY, $gate, $root]);
            self::assertSame(0, $status, $output);
            self::assertStringContainsString('1 files checked; 0 failures', $output);
            // Compile the working file, not the valid index blob. Duplicate declarations
            // are compiler errors even though a token-only parser may accept them.
            file_put_contents($root . '/with spaces.php', '<?php function twice() {} function twice() {}');
            [$status, $output] = $this->runProcess([PHP_BINARY, $gate, $root]);
            self::assertSame(1, $status, $output);
            self::assertStringContainsString('with spaces.php', $output);
            self::assertStringContainsString('1 files checked; 1 failures', $output);
            unlink($root . '/with spaces.php');
            self::assertSame(1, $this->runProcess([PHP_BINARY, $gate, $root])[0]);
        } finally {
            // Only remove the randomly created fixture, never a supplied path.
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
                \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) { rmdir($file->getPathname()); }
                else { chmod($file->getPathname(), 0600); unlink($file->getPathname()); }
            }
            rmdir($root);
        }
    }

    private function runProcess(array $command): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
