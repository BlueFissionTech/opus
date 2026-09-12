<?php

declare(strict_types=1);

namespace Tests\Unit;

use BlueFission\Str;
use PHPUnit\Framework\TestCase;

class ApplicationRootCompatibilityTest extends TestCase
{
    public function testLegacyRootAliasUsesTheApplicationRoot(): void
    {
        require_once dirname(__DIR__, 2) . '/common/helpers/settings.php';

        $this->assertTrue(defined('APP_ROOT'));
        $this->assertTrue(defined('OPUS_ROOT'));
        $this->assertSame(APP_ROOT, OPUS_ROOT);
    }

    public function testDirectSettingsFallbackHonorsTheConfiguredHostRoot(): void
    {
        $root = dirname(__DIR__, 2);
        $host = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opus-settings-' . bin2hex(random_bytes(6));
        mkdir($host, 0777, true);
        $expected = realpath($host) . DIRECTORY_SEPARATOR;
        $code = Str::make('putenv(')
            ->append(var_export('OPUS_HOST_ROOT=' . $host, true))
            ->append('); require ')
            ->append(var_export($root . '/vendor/autoload.php', true))
            ->append('; require ')
            ->append(var_export($root . '/common/helpers/settings.php', true))
            ->append('; echo APP_ROOT;')
            ->val();

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        rmdir($host);

        $this->assertSame('', $errors);
        $this->assertSame(0, $exitCode);
        $this->assertSame($expected, $output);
    }
}
