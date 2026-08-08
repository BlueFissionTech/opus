<?php

declare(strict_types=1);

namespace Opus\Tools;

use JsonException;
use RuntimeException;

final class ComposerVcsAudit
{
    private const PACKAGIST_PACKAGES = [
        'bluefission/automata',
        'bluefission/chronicler',
        'bluefission/develation',
    ];

    /**
     * @return array{errors: array<string>, packages: array<string>}
     */
    public function auditFiles(string $composerPath, string $lockPath, string $templatePath): array
    {
        return $this->audit(
            $this->readJson($composerPath),
            $this->readJson($lockPath),
            $this->readJson($templatePath)
        );
    }

    /**
     * @param array<string, mixed> $composer
     * @param array<string, mixed> $lock
     * @param array<string, mixed> $template
     * @return array{errors: array<string>, packages: array<string>}
     */
    public function audit(array $composer, array $lock, array $template): array
    {
        $errors = [];
        $rootRepositories = $this->repositoryMap($composer['repositories'] ?? []);
        $templateRepositories = $this->repositoryMap($template['repositories'] ?? []);
        $rootPackage = strtolower((string) ($composer['name'] ?? ''));

        if (($composer['config']['use-github-api'] ?? null) !== false) {
            $errors[] = 'Root composer.json must enable direct Git fallback with config.use-github-api=false.';
        }
        if (($template['config']['use-github-api'] ?? null) !== false) {
            $errors[] = 'Consumer template must enable direct Git fallback with config.use-github-api=false.';
        }

        foreach (self::PACKAGIST_PACKAGES as $package) {
            if (isset($rootRepositories[$package])) {
                $errors[] = "{$package} must resolve through Packagist, not a root VCS override.";
            }
            if (isset($templateRepositories[$package])) {
                $errors[] = "{$package} must not be present in the consumer VCS template.";
            }
        }

        foreach ($templateRepositories as $package => $repository) {
            $expectedUrl = 'https://github.com/BlueFissionTech/' . substr($package, strlen('bluefission/'));
            if (($repository['type'] ?? null) !== 'vcs' || ($repository['url'] ?? null) !== $expectedUrl) {
                $errors[] = "{$package} does not use its canonical GitHub VCS route.";
            }

            if (
                $package !== $rootPackage
                && !$this->repositoriesMatch($rootRepositories[$package] ?? null, $repository)
            ) {
                $errors[] = "Root composer.json is missing the canonical {$package} repository.";
            }
        }

        $packages = $this->blueFissionRequirements($composer, $lock);
        $lockedPackages = $this->lockedPackageMap($lock);
        foreach ($packages as $package) {
            $lockedPackage = $lockedPackages[$package] ?? [];
            $lockedSource = $lockedPackage['source']['url'] ?? null;
            if ($this->packageFromRepositoryUrl($lockedSource) !== $package) {
                $errors[] = "Locked {$package} does not resolve from its canonical GitHub source.";
            }

            if (in_array($package, self::PACKAGIST_PACKAGES, true)) {
                $lockedVersion = $lockedPackage['version'] ?? null;
                $normalizedVersion = is_string($lockedVersion) ? strtolower($lockedVersion) : '';
                if (
                    $normalizedVersion === ''
                    || str_starts_with($normalizedVersion, 'dev-')
                    || str_ends_with($normalizedVersion, '-dev')
                ) {
                    $errors[] = "Locked {$package} must use a tagged Packagist release.";
                }
                if (($lockedPackage['notification-url'] ?? null) !== 'https://packagist.org/downloads/') {
                    $errors[] = "Locked {$package} does not carry Packagist distribution metadata.";
                }
                continue;
            }
            if (!isset($templateRepositories[$package])) {
                $errors[] = "Canonical consumer template is missing {$package}.";
            }
            if (!isset($rootRepositories[$package])) {
                $errors[] = "Root composer.json is missing {$package}.";
            }

        }

        return [
            'errors' => array_values(array_unique($errors)),
            'packages' => $packages,
        ];
    }

    /**
     * @param mixed $repositories
     * @return array<string, array{type?: mixed, url?: mixed}>
     */
    private function repositoryMap($repositories): array
    {
        if (!is_array($repositories)) {
            return [];
        }

        $mapped = [];
        foreach ($repositories as $package => $repository) {
            if (!is_array($repository)) {
                continue;
            }

            if (is_string($package) && str_starts_with(strtolower($package), 'bluefission/')) {
                $mapped[strtolower($package)] = $repository;
                continue;
            }

            $repositoryUrl = $repository['url'] ?? null;
            $repositoryPackage = $this->packageFromRepositoryUrl($repositoryUrl)
                ?? $this->packagistPackageFromRepositoryUrl($repositoryUrl);
            if ($repositoryPackage !== null) {
                $mapped[$repositoryPackage] = $repository;
            }
        }

        ksort($mapped);
        return $mapped;
    }

    /**
     * @param array{type?: mixed, url?: mixed}|null $left
     * @param array{type?: mixed, url?: mixed} $right
     */
    private function repositoriesMatch(?array $left, array $right): bool
    {
        if ($left === null || ($left['type'] ?? null) !== ($right['type'] ?? null)) {
            return false;
        }

        $leftUrl = $left['url'] ?? null;
        $rightUrl = $right['url'] ?? null;
        return is_string($leftUrl)
            && is_string($rightUrl)
            && strtolower(rtrim($leftUrl, '/')) === strtolower(rtrim($rightUrl, '/'));
    }

    private function packageFromRepositoryUrl(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }

        if (preg_match('/^git@github\\.com:([^\\/]+)\\/(.+)$/i', $url, $matches) === 1) {
            $owner = $matches[1];
            $repository = $matches[2];
        } else {
            $host = parse_url($url, PHP_URL_HOST);
            $path = parse_url($url, PHP_URL_PATH);
            if (!is_string($host) || strtolower($host) !== 'github.com' || !is_string($path)) {
                return null;
            }

            $segments = explode('/', trim($path, '/'));
            if (count($segments) !== 2) {
                return null;
            }
            [$owner, $repository] = $segments;
        }

        if (strtolower($owner) !== 'bluefissiontech') {
            return null;
        }

        $repository = preg_replace('/\\.git$/i', '', $repository);
        return is_string($repository) && $repository !== ''
            ? 'bluefission/' . strtolower($repository)
            : null;
    }

    private function packagistPackageFromRepositoryUrl(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }

        $repository = basename(str_replace('\\', '/', rtrim($url, '/')));
        $repository = preg_replace('/\.git$/i', '', $repository);
        if (!is_string($repository) || $repository === '') {
            return null;
        }

        $package = 'bluefission/' . strtolower($repository);
        return in_array($package, self::PACKAGIST_PACKAGES, true) ? $package : null;
    }

    /**
     * @param array<string, mixed> $composer
     * @param array<string, mixed> $lock
     * @return array<string>
     */
    private function blueFissionRequirements(array $composer, array $lock): array
    {
        $locked = $this->lockedPackageMap($lock);

        $queue = [];
        foreach (array_keys($composer['require'] ?? []) as $package) {
            if (is_string($package) && str_starts_with(strtolower($package), 'bluefission/')) {
                $queue[] = strtolower($package);
            }
        }

        $found = [];
        while ($queue !== []) {
            $package = array_shift($queue);
            if (!is_string($package) || isset($found[$package])) {
                continue;
            }
            $found[$package] = true;

            foreach (array_keys($locked[$package]['require'] ?? []) as $dependency) {
                if (is_string($dependency) && str_starts_with(strtolower($dependency), 'bluefission/')) {
                    $queue[] = strtolower($dependency);
                }
            }
        }

        $packages = array_keys($found);
        sort($packages);
        return $packages;
    }

    /**
     * @param array<string, mixed> $lock
     * @return array<string, array<string, mixed>>
     */
    private function lockedPackageMap(array $lock): array
    {
        $locked = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (is_array($package) && isset($package['name']) && is_string($package['name'])) {
                $locked[strtolower($package['name'])] = $package;
            }
        }

        return $locked;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read {$path}.");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException("Invalid JSON in {$path}: {$error->getMessage()}", 0, $error);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("Expected a JSON object in {$path}.");
        }
        return $decoded;
    }
}
