<?php

declare(strict_types=1);

namespace Opus\Tools;

use RuntimeException;

/** Pre-autoload diagnostic: it must work when the installed libraries do not. */
final class InstalledDependencyAudit
{
    public function auditFiles(string $lockPath, string $installedPath, bool $includeDev = true): array
    {
        return $this->audit($this->readJson($lockPath), $this->readJson($installedPath), $includeDev);
    }

    public function audit(array $lock, array $installed, bool $includeDev = true): array
    {
        $production = $this->packageMap($lock['packages'] ?? null);
        $development = $this->packageMap($lock['packages-dev'] ?? []);
        if (array_intersect_key($production, $development) !== []) {
            throw new RuntimeException('Duplicate package in lock metadata.');
        }
        $expected = $includeDev ? $production + $development : $production;
        $actual = $this->packageMap($installed['packages'] ?? (array_is_list($installed) ? $installed : null));
        $differences = [];

        foreach ($expected as $name => $package) {
            if (!isset($actual[$name])) {
                $differences[] = ['package' => $name, 'reason' => 'missing'];
                continue;
            }
            foreach (['version', 'source_reference', 'dist_reference'] as $field) {
                if ($package[$field] !== $actual[$name][$field]) {
                    $differences[] = [
                        'package' => $name,
                        'reason' => $field . '_mismatch',
                        'expected' => $package[$field],
                        'installed' => $actual[$name][$field],
                    ];
                }
            }
        }
        foreach (array_diff_key($actual, $expected) as $name => $package) {
            $differences[] = ['package' => $name, 'reason' => 'unexpected'];
        }
        usort($differences, static fn (array $a, array $b): int =>
            [$a['package'], $a['reason']] <=> [$b['package'], $b['reason']]);

        return [
            'schema_version' => 1,
            'scope' => $includeDev ? 'development' : 'production',
            'status' => $differences === [] ? 'pass' : 'drift',
            'expected_count' => count($expected),
            'installed_count' => count($actual),
            'differences' => $differences,
            'limitations' => ['metadata_only', 'file_integrity_not_checked', 'runtime_not_tested'],
        ];
    }

    private function packageMap(mixed $packages): array
    {
        if (!is_array($packages) || !array_is_list($packages)) {
            throw new RuntimeException('Package metadata must contain a package list.');
        }
        $result = [];
        foreach ($packages as $package) {
            if (!is_array($package)
                || !is_string($package['name'] ?? null)
                || !preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $package['name'])
                || !is_string($package['version'] ?? null)
                || $package['version'] === ''
            ) {
                throw new RuntimeException('Invalid package identity in dependency metadata.');
            }
            $name = $package['name'];
            if (isset($result[$name])) {
                throw new RuntimeException('Duplicate package in dependency metadata.');
            }
            // Only identity fields are reported; repository URLs and authentication are never copied.
            $result[$name] = [
                'version' => $package['version'],
                'source_reference' => $this->reference($package, 'source'),
                'dist_reference' => $this->reference($package, 'dist'),
            ];
        }
        return $result;
    }

    private function reference(array $package, string $kind): ?string
    {
        $metadata = $package[$kind] ?? [];
        if (!is_array($metadata)) {
            throw new RuntimeException('Invalid package reference metadata.');
        }
        $reference = $metadata['reference'] ?? null;
        if ($reference !== null && (!is_string($reference) || $reference === '')) {
            throw new RuntimeException('Invalid package reference metadata.');
        }
        return $reference;
    }

    private function readJson(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Required dependency metadata is unavailable.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Required dependency metadata is unreadable.');
        }
        // Preserve JSON container types before associative decoding erases {} versus [].
        $container = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        if ($container instanceof \stdClass) {
            if (!property_exists($container, 'packages') || !is_array($container->packages)
                || (property_exists($container, 'packages-dev') && !is_array($container->{'packages-dev'}))
            ) {
                throw new RuntimeException('Package metadata must contain a package list.');
            }
        } elseif (!is_array($container)) {
            throw new RuntimeException('Dependency metadata must be a JSON object or package list.');
        }
        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
