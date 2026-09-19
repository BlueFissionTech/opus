<?php

declare(strict_types=1);

namespace Tests\Unit;

use Opus\Tools\InstalledDependencyAudit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../tools/InstalledDependencyAudit.php';

final class InstalledDependencyAuditTest extends TestCase
{
    private function package(string $name = 'example/runtime', string $reference = 'abc'): array
    {
        return ['name' => $name, 'version' => '1.0.0',
            'source' => ['reference' => $reference, 'url' => 'https://secret@example.test/source'],
            'dist' => ['reference' => $reference]];
    }

    public function testExactMetadataPassesWithoutReportingRepositoryUrls(): void
    {
        $package = $this->package();
        $result = (new InstalledDependencyAudit())->audit(
            ['packages' => [$package]], ['packages' => [$package], 'dev' => true]
        );
        $this->assertSame('pass', $result['status']);
        $this->assertSame([], $result['differences']);
        $this->assertContains('file_integrity_not_checked', $result['limitations']);
        $this->assertStringNotContainsString('secret', json_encode($result));
    }

    public function testReferenceDriftIsDetectedEvenWhenVersionMatches(): void
    {
        $result = (new InstalledDependencyAudit())->audit(
            ['packages' => [$this->package()]], ['packages' => [$this->package(reference: 'def')]]
        );
        $this->assertSame('drift', $result['status']);
        $this->assertSame(['dist_reference_mismatch', 'source_reference_mismatch'],
            array_column($result['differences'], 'reason'));
        $this->assertSame('abc', $result['differences'][0]['expected']);
        $this->assertSame('def', $result['differences'][0]['installed']);
    }

    public function testMissingUnexpectedAndVersionDriftAreReportedTogether(): void
    {
        $changed = $this->package();
        $changed['version'] = '0.9.0';
        $result = (new InstalledDependencyAudit())->audit(
            ['packages' => [$this->package(), $this->package('example/missing')]],
            ['packages' => [$changed, $this->package('example/unexpected')]]
        );
        $this->assertSame(['missing', 'version_mismatch', 'unexpected'],
            array_column($result['differences'], 'reason'));
    }

    public function testProductionModeRejectsInstalledDevelopmentPackages(): void
    {
        $runtime = $this->package();
        $dev = $this->package('example/dev');
        $lock = ['packages' => [$runtime], 'packages-dev' => [$dev]];
        $audit = new InstalledDependencyAudit();
        $this->assertSame('pass', $audit->audit($lock, ['packages' => [$runtime]], false)['status']);
        $this->assertSame('missing', $audit->audit($lock, ['packages' => [$runtime]])['differences'][0]['reason']);
        $this->assertSame('unexpected', $audit->audit($lock, ['packages' => [$runtime, $dev]], false)['differences'][0]['reason']);
    }

    public function testComposerOneListAndPackagesWithoutReferencesAreSupported(): void
    {
        $package = ['name' => 'example/metapackage', 'version' => '1.0.0'];
        $result = (new InstalledDependencyAudit())->audit(['packages' => [$package]], [$package]);
        $this->assertSame('pass', $result['status']);
    }

    public function testMissingReferenceDoesNotSilentlyPass(): void
    {
        $actual = $this->package();
        unset($actual['source']);
        $result = (new InstalledDependencyAudit())->audit(
            ['packages' => [$this->package()]], ['packages' => [$actual]]
        );
        $this->assertSame('source_reference_mismatch', $result['differences'][0]['reason']);
    }

    /** @dataProvider malformedMetadata */
    public function testMalformedInputCannotProduceAPass(array $lock, array $installed): void
    {
        $this->expectException(RuntimeException::class);
        (new InstalledDependencyAudit())->audit($lock, $installed);
    }

    public static function malformedMetadata(): array
    {
        $package = ['name' => 'example/runtime', 'version' => '1.0.0'];
        return [
            'missing lock list' => [[], []],
            'invalid installed shape' => [['packages' => []], ['unexpected' => true]],
            'duplicate installed' => [['packages' => [$package]], [$package, $package]],
            'duplicate lock scopes' => [['packages' => [$package], 'packages-dev' => [$package]], [$package]],
            'invalid identity' => [['packages' => [['name' => 'invalid']]], []],
            'invalid reference' => [['packages' => [$package + ['source' => 'bad']]], []],
            'invalid package list' => [['packages' => ['named' => $package]], []],
        ];
    }

    /** @dataProvider malformedJsonContainers */
    public function testJsonObjectsCannotMasqueradeAsPackageLists(string $lock, string $installed): void
    {
        $root = sys_get_temp_dir() . '/opus-metadata-shape-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            file_put_contents($root . '/composer.lock', $lock);
            file_put_contents($root . '/installed.json', $installed);
            $this->expectException(RuntimeException::class);
            (new InstalledDependencyAudit())->auditFiles($root . '/composer.lock', $root . '/installed.json');
        } finally {
            unlink($root . '/composer.lock');
            unlink($root . '/installed.json');
            rmdir($root);
        }
    }

    public static function malformedJsonContainers(): array
    {
        return [
            'empty installed object' => ['{"packages":[]}', '{}'],
            'installed package object' => ['{"packages":[]}', '{"packages":{}}'],
            'lock package object' => ['{"packages":{}}', '[]'],
            'lock dev package object' => ['{"packages":[],"packages-dev":{}}', '[]'],
        ];
    }

    public function testEmptyComposerOneAndTwoPackageListsRemainValid(): void
    {
        $root = sys_get_temp_dir() . '/opus-empty-metadata-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            file_put_contents($root . '/composer.lock', '{"packages":[],"packages-dev":[]}');
            foreach (['[]', '{"packages":[]}'] as $installed) {
                file_put_contents($root . '/installed.json', $installed);
                $report = (new InstalledDependencyAudit())->auditFiles($root . '/composer.lock', $root . '/installed.json');
                $this->assertSame('pass', $report['status']);
            }
        } finally {
            unlink($root . '/composer.lock');
            unlink($root . '/installed.json');
            rmdir($root);
        }
    }

    public function testAbsentMetadataFailsBeforeAutoload(): void
    {
        $this->expectException(RuntimeException::class);
        (new InstalledDependencyAudit())->auditFiles(__DIR__ . '/missing-composer.lock', __DIR__ . '/missing-installed.json');
    }

    /** @dataProvider cliCases */
    public function testCliRunsWithoutAutoloadFromAnotherDirectory(string $metadata, array $flags, int $exitCode, string $status): void
    {
        $root = sys_get_temp_dir() . '/opus-dependency-proof-' . bin2hex(random_bytes(8));
        foreach (['bin', 'tools', 'vendor', 'vendor/composer'] as $directory) {
            mkdir($root . '/' . $directory, 0777, true);
        }
        $files = ['bin/audit-installed-dependencies.php', 'tools/InstalledDependencyAudit.php'];
        try {
            foreach ($files as $file) {
                copy(dirname(__DIR__, 2) . '/' . $file, $root . '/' . $file);
            }
            file_put_contents($root . '/composer.lock', '{"packages":[{"name":"example/runtime","version":"1.0.0"}]}');
            file_put_contents($root . '/vendor/composer/installed.json', $metadata);
            $process = proc_open(
                [PHP_BINARY, '-n', $root . '/bin/audit-installed-dependencies.php', ...$flags],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                sys_get_temp_dir()
            );
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame($exitCode, proc_close($process), $errors);
            $this->assertSame('', $errors);
            $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($status, $report['status']);
            $this->assertStringNotContainsString($root, $output);
        } finally {
            foreach ([...$files, 'composer.lock', 'vendor/composer/installed.json'] as $file) {
                if (is_file($root . '/' . $file)) {
                    unlink($root . '/' . $file);
                }
            }
            foreach (['vendor/composer', 'vendor', 'tools', 'bin'] as $directory) {
                rmdir($root . '/' . $directory);
            }
            rmdir($root);
        }
    }

    public static function cliCases(): array
    {
        $matching = '{"packages":[{"name":"example/runtime","version":"1.0.0"}]}';
        return [
            'matching development' => [$matching, [], 0, 'pass'],
            'matching production' => [$matching, ['--no-dev'], 0, 'pass'],
            'missing package' => ['{"packages":[]}', [], 1, 'drift'],
            'empty installed object' => ['{}', [], 2, 'unavailable'],
            'corrupt metadata' => ['{"secret":"do-not-print",', [], 2, 'unavailable'],
        ];
    }
}
