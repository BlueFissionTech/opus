<?php

declare(strict_types=1);

namespace Opus\Tools;

use JsonException;
use RuntimeException;

final class ComposerVcsAudit
{
    private const PACKAGIST_PACKAGES = [
        'bluefission/automata',
        'bluefission/bluecore',
        'bluefission/chronicler',
        'bluefission/develation',
        'bluefission/simpleclients',
        'bluefission/synthetiq',
    ];

    private const TAGGED_PACKAGIST_PACKAGES = [
        'bluefission/automata',
        'bluefission/bluecore',
        'bluefission/chronicler',
        'bluefission/develation',
        'bluefission/simpleclients',
        'bluefission/synthetiq',
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
        $rootRequirements = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $templateRequirements = is_array($template['require'] ?? null) ? $template['require'] : [];
        $packages = $this->blueFissionRequirements($composer, $lock);
        $expectedRepositoryPackages = array_values(array_unique(array_filter([
            ...$packages,
            $rootPackage,
        ])));

        $errors = array_merge(
            $errors,
            $this->unverifiableRepositoryErrors(
                $composer['repositories'] ?? [],
                'Root composer.json',
                $expectedRepositoryPackages
            ),
            $this->unverifiableRepositoryErrors(
                $template['repositories'] ?? [],
                'Consumer template',
                $expectedRepositoryPackages
            )
        );

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

            $rootConstraint = $rootRequirements[$package] ?? null;
            $templateConstraint = $templateRequirements[$package] ?? null;
            $rootHasAlias = is_string($rootConstraint) && $this->hasDevelopmentAlias($rootConstraint);
            $templateHasAlias = is_string($templateConstraint) && $this->hasDevelopmentAlias($templateConstraint);
            if (
                $rootHasAlias
                && $templateConstraint !== $rootConstraint
            ) {
                $errors[] = "Consumer template must repeat the root-only {$package} alias {$rootConstraint}.";
            }
            if (!$rootHasAlias && $templateHasAlias) {
                $errors[] = "Consumer template must remove the stale {$package} alias {$templateConstraint}.";
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

        $lockedPackages = $this->lockedPackageMap($lock);
        foreach ($packages as $package) {
            $lockedPackage = $lockedPackages[$package] ?? [];
            $lockedSource = $lockedPackage['source']['url'] ?? null;
            if ($this->packageFromRepositoryUrl($lockedSource) !== $package) {
                $errors[] = "Locked {$package} does not resolve from its canonical GitHub source.";
            }

            if (in_array($package, self::PACKAGIST_PACKAGES, true)) {
                if (in_array($package, self::TAGGED_PACKAGIST_PACKAGES, true)) {
                    $lockedVersion = $lockedPackage['version'] ?? null;
                    $normalizedVersion = is_string($lockedVersion) ? strtolower($lockedVersion) : '';
                    if (
                        $normalizedVersion === ''
                        || str_starts_with($normalizedVersion, 'dev-')
                        || str_ends_with($normalizedVersion, '-dev')
                    ) {
                        $errors[] = "Locked {$package} must use a tagged Packagist release.";
                    }
                }
                if (($lockedPackage['notification-url'] ?? null) !== 'https://packagist.org/downloads/') {
                    $errors[] = "Locked {$package} does not carry Packagist distribution metadata.";
                }
                $sourceReference = $lockedPackage['source']['reference'] ?? null;
                $distributionReference = $lockedPackage['dist']['reference'] ?? null;
                if (!$this->isCanonicalPackagistDistribution(
                    $package,
                    $lockedPackage['dist']['url'] ?? null,
                    $distributionReference
                )) {
                    $errors[] = "Locked {$package} does not use its canonical Packagist distribution archive.";
                }
                if (
                    !is_string($sourceReference)
                    || !is_string($distributionReference)
                    || $sourceReference === ''
                    || $sourceReference !== $distributionReference
                ) {
                    $errors[] = "Locked {$package} source and distribution references do not match.";
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

    private function hasDevelopmentAlias(string $constraint): bool
    {
        return preg_match('/\bas\s+(?:dev-[^\s]+|[^\s]+-dev)\b/i', $constraint) === 1;
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
        foreach ($repositories as $repository) {
            if (!is_array($repository)) {
                continue;
            }

            $inlinePackages = $this->inlinePackageNamesForPackageRepository($repository);
            foreach ($inlinePackages as $inlinePackage) {
                if (str_starts_with($inlinePackage, 'bluefission/')) {
                    $mapped[$inlinePackage] = $repository;
                }
            }
            if ($inlinePackages !== []) {
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
     * @param mixed $repositories
     * @param array<string> $expectedPackages
     * @return array<string>
     */
    private function unverifiableRepositoryErrors($repositories, string $source, array $expectedPackages): array
    {
        if (!is_array($repositories)) {
            return [];
        }

        $errors = [];
        foreach ($repositories as $index => $repository) {
            if (
                is_string($index)
                && in_array(strtolower($index), ['packagist', 'packagist.org'], true)
                && $repository === false
            ) {
                $errors[] = "{$source} must not disable Packagist.";
                continue;
            }

            if (!is_array($repository)) {
                continue;
            }

            $inlinePackages = $this->inlinePackageNamesForPackageRepository($repository);
            if ($inlinePackages !== []) {
                foreach ($inlinePackages as $inlinePackage) {
                    if (in_array($inlinePackage, $expectedPackages, true)) {
                        $errors[] = "{$source} must not define {$inlinePackage} through an inline package repository.";
                    }
                }
                continue;
            }

            $url = $repository['url'] ?? null;
            $canonicalPackage = $this->packageFromRepositoryUrl($url);
            if ($canonicalPackage !== null) {
                $type = is_string($repository['type'] ?? null)
                    ? strtolower($repository['type'])
                    : 'unknown';
                if ($type !== 'vcs') {
                    $errors[] = "{$source} repository for {$canonicalPackage} must use type vcs.";
                }
                if (!in_array($canonicalPackage, $expectedPackages, true)) {
                    $errors[] = "{$source} has a repository for unexpected package {$canonicalPackage}.";
                }
                continue;
            }
            if ($this->packagistPackageFromRepositoryUrl($url) !== null) {
                continue;
            }

            $type = is_string($repository['type'] ?? null) ? strtolower($repository['type']) : 'unknown';
            $position = is_int($index) ? "at index {$index}" : "named {$index}";
            $errors[] = "{$source} has an unverifiable {$type} repository {$position}.";
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $repository
     * @return array<string>
     */
    private function inlinePackageNames(array $repository): array
    {
        $definitions = $repository['package'] ?? [];
        if (!is_array($definitions)) {
            return [];
        }
        if (isset($definitions['name'])) {
            $definitions = [$definitions];
        }

        $packages = [];
        foreach ($definitions as $definition) {
            $package = is_array($definition) ? ($definition['name'] ?? null) : null;
            if (is_string($package) && $package !== '') {
                $packages[] = strtolower($package);
            }
        }

        return array_values(array_unique($packages));
    }

    /**
     * @param array<string, mixed> $repository
     * @return array<string>
     */
    private function inlinePackageNamesForPackageRepository(array $repository): array
    {
        $type = $repository['type'] ?? null;
        return is_string($type) && strtolower($type) === 'package'
            ? $this->inlinePackageNames($repository)
            : [];
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

    private function isCanonicalPackagistDistribution(
        string $package,
        mixed $url,
        mixed $reference
    ): bool {
        if (!is_string($url) || !is_string($reference) || $reference === '') {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        if (
            !is_string($scheme)
            || strtolower($scheme) !== 'https'
            || !is_string($host)
            || strtolower($host) !== 'api.github.com'
            || !is_string($path)
        ) {
            return false;
        }

        $segments = array_map('rawurldecode', explode('/', trim($path, '/')));
        $repository = substr($package, strlen('bluefission/'));
        return count($segments) === 5
            && strtolower($segments[0]) === 'repos'
            && strtolower($segments[1]) === 'bluefissiontech'
            && strtolower($segments[2]) === strtolower($repository)
            && strtolower($segments[3]) === 'zipball'
            && $segments[4] === $reference;
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
