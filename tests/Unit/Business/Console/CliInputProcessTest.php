<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Console;

use BlueFission\System\Process;
use PHPUnit\Framework\TestCase;

final class CliInputProcessTest extends TestCase
{
    public function testRealStdinNeverSplitsOneLongCommand(): void
    {
        $command = str_repeat('x', 2048);
        $result = $this->runConsole($command . "\n.\n");
        self::assertSame(0, $result['exit']);
        self::assertCount(1, $result['requests']);
        self::assertSame($command, $result['requests'][0]['input']);
    }

    public function testRealStdinNeverExecutesAConfirmationSuffix(): void
    {
        $result = $this->runConsole("confirm\nyes             not-a-command\n.\n");
        self::assertCount(2, $result['requests']);
        self::assertTrue($result['requests'][1]['continuation']);
        self::assertFalse($result['requests'][1]['approved']);
    }

    private function runConsole(string $input): array
    {
        $files = [];
        foreach (['input', 'output', 'error'] as $name) {
            $files[$name] = tempnam(sys_get_temp_dir(), 'opus-cli-');
        }
        file_put_contents($files['input'], $input);
        $root = dirname(__DIR__, 4);
        $process = new Process([
            PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'),
            '-d', 'extension=mbstring', '-d', 'extension=fileinfo',
            $root . '/tests/Fixtures/console-input.php',
        ], $root, null, [
            0 => ['file', $files['input'], 'r'],
            1 => ['file', $files['output'], 'w'],
            2 => ['file', $files['error'], 'w'],
        ], ['bypass_shell' => true]);
        $started = false;
        try {
            $process->start();
            $started = true;
            $deadline = microtime(true) + 60;
            while ($process->status() === true && microtime(true) < $deadline) {
                usleep(10000);
            }
            if ($process->status() === true) {
                proc_terminate($process->process);
                self::fail('CLI fixture exceeded its deadline.');
            }
            $exit = $process->close();
            $started = false;
            $error = file_get_contents($files['error']);
            $result = json_decode($error, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($result['exit'], $exit);
            return $result;
        } finally {
            if ($started) { $process->close(); }
            foreach ($files as $file) { unlink($file); }
        }
    }
}
