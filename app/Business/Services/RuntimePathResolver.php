<?php

declare(strict_types=1);

namespace App\Business\Services;

/**
 * Resolves package and host paths before Composer-managed classes are available.
 */
final class RuntimePathResolver
{
    private string $packageRoot;
    private string $packageInstallRoot;
    private ?string $hostRoot;
    private ?string $activeAutoloader;
    private ?string $activeAutoloaderInstallPath;

    public function __construct(
        ?string $packageRoot = null,
        ?string $hostRoot = null,
        ?string $activeAutoloader = null
    ) {
        $packageRoot = $packageRoot ?? dirname(__DIR__, 3);
        $this->packageInstallRoot = self::normalizeLexical($packageRoot);
        $this->packageRoot = self::normalize($packageRoot);
        $this->hostRoot = $hostRoot !== null && $hostRoot !== '' ? self::normalize($hostRoot) : null;
        $this->activeAutoloaderInstallPath = $activeAutoloader !== null && $activeAutoloader !== ''
            ? self::normalizeLexical($activeAutoloader)
            : null;
        $this->activeAutoloader = $this->activeAutoloaderInstallPath !== null
            ? self::normalize($this->activeAutoloaderInstallPath)
            : null;
    }

    public static function discover(
        ?string $packageRoot = null,
        ?string $hostRoot = null,
        ?string $activeAutoloader = null
    ): self {
        return new self($packageRoot, $hostRoot, $activeAutoloader);
    }

    public static function packageInstallRootFromEntrypoint(string $entrypoint): ?string
    {
        if (!self::isAbsolute($entrypoint)) {
            $entrypoint = getcwd() . DIRECTORY_SEPARATOR . $entrypoint;
        }
        $entrypoint = self::normalizeLexical($entrypoint);
        $entrypoints = [
            'public' . DIRECTORY_SEPARATOR . 'index.php' => 2,
            'bin' . DIRECTORY_SEPARATOR . 'opus-addon.php' => 2,
            'terminal' => 1,
            'websocket-server.php' => 1,
        ];

        foreach ($entrypoints as $suffix => $levels) {
            if (self::pathEndsWith($entrypoint, DIRECTORY_SEPARATOR . $suffix)) {
                return self::normalizeLexical(dirname($entrypoint, $levels));
            }
        }

        return null;
    }

    public static function isPackageInstallRoot(string $root): bool
    {
        return is_file(self::join($root, 'common/bootstrap/runtime.php'));
    }

    public static function composerProxyAutoloaderFromEntrypoint(string $entrypoint): ?string
    {
        if (!self::isAbsolute($entrypoint)) {
            $entrypoint = getcwd() . DIRECTORY_SEPARATOR . $entrypoint;
        }
        $entrypoint = self::normalizeLexical($entrypoint);
        if (!self::pathEndsWith($entrypoint, DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'opus-addon.php')) {
            return null;
        }

        $candidate = self::normalizeLexical(
            dirname($entrypoint, 2) . DIRECTORY_SEPARATOR . 'autoload.php'
        );

        return is_file($candidate) ? $candidate : null;
    }

    public function packageRoot(): string
    {
        return $this->packageRoot;
    }

    public function hostRoot(): string
    {
        if ($this->hostRoot !== null) {
            return $this->hostRoot;
        }
        if (basename($this->packageInstallRoot) === 'core') {
            return self::normalize(dirname($this->packageInstallRoot));
        }

        $autoloadPath = $this->autoloadPath();
        $searchRoots = [$this->packageInstallRoot, dirname($autoloadPath)];
        if ($this->activeAutoloaderInstallPath !== null) {
            $searchRoots[] = dirname($this->activeAutoloaderInstallPath);
        }
        $configuredHost = self::configuredVendorHost(
            $searchRoots,
            $autoloadPath
        );

        return $configuredHost ?? self::normalize(dirname(dirname($autoloadPath)));
    }

    public function autoloadPath(): string
    {
        $candidates = [];
        if ($this->activeAutoloader !== null) {
            $candidates[] = $this->activeAutoloader;
        }
        $candidateHostRoot = $this->hostRoot;
        if ($candidateHostRoot === null && basename($this->packageInstallRoot) === 'core') {
            $candidateHostRoot = dirname($this->packageInstallRoot);
        }
        if ($candidateHostRoot !== null) {
            $configuredAutoloader = self::configuredVendorAutoloader($candidateHostRoot);
            if ($configuredAutoloader !== null) {
                $candidates[] = $configuredAutoloader;
            }
            $candidates[] = self::join($candidateHostRoot, 'vendor/autoload.php');
        }
        if (basename($this->packageInstallRoot) === 'core') {
            $candidates[] = self::join(dirname($this->packageInstallRoot), 'vendor/autoload.php');
        }

        foreach (self::ancestorDirectories($this->packageInstallRoot) as $ancestor) {
            $configuredAutoloader = self::configuredVendorAutoloader($ancestor);
            $configuredVendorRoot = self::configuredVendorRoot($ancestor);
            if (
                $configuredAutoloader !== null
                && $configuredVendorRoot !== null
                && self::pathStartsWith($this->packageInstallRoot, $configuredVendorRoot)
            ) {
                $candidates[] = $configuredAutoloader;
            }
            if (basename($ancestor) === 'vendor') {
                $candidates[] = self::join($ancestor, 'autoload.php');
                break;
            }
        }
        $candidates[] = self::join($this->packageRoot, 'vendor/autoload.php');

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return self::normalize($candidate);
            }
        }

        throw new \RuntimeException('Composer autoloader could not be resolved from the host or package roots.');
    }

    public function packageResourceRoot(): string
    {
        return self::join($this->packageRoot, 'resource');
    }

    public function packageResourcePath(string $relativePath = ''): string
    {
        return self::containedPath($this->packageResourceRoot(), $this->relative($relativePath));
    }

    public function hostResourceRoot(): string
    {
        return self::join($this->hostRoot(), 'resource');
    }

    public function hostResourcePath(string $relativePath = ''): string
    {
        return self::containedPath($this->hostResourceRoot(), $this->relative($relativePath));
    }

    public function themeRoot(string $theme, ?string $hostOverride = null): string
    {
        return $hostOverride === null
            ? $this->packageResourcePath('markup/' . $this->relative($theme))
            : $this->hostResourcePath($hostOverride);
    }

    private function relative(string $path): string
    {
        $path = preg_replace('#[\\\\/]+#', DIRECTORY_SEPARATOR, $path) ?? '';
        if (preg_match('#^(?:[A-Za-z]:)?[\\\\/]#', $path) === 1 || preg_match('/\x00/', $path) === 1) {
            throw new \InvalidArgumentException('Runtime resource paths must be relative.');
        }
        $path = preg_replace('#[\\\\/]+$#', '', $path) ?? '';
        if ($path === '') {
            return '';
        }

        $segments = preg_split('#[\\\\/]#', $path) ?: [];
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new \InvalidArgumentException('Runtime resource paths must remain inside their owning root.');
            }
        }

        return $path;
    }

    private static function join(string $root, string $relativePath): string
    {
        if ($relativePath === '') {
            return self::normalize($root);
        }

        return self::normalize(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\'));
    }

    private static function containedPath(string $root, string $relativePath): string
    {
        $root = self::normalize($root);
        $path = self::join($root, $relativePath);
        $comparisonPath = self::resolveExistingAncestor($path);

        if (
            !self::pathsMatch($comparisonPath, $root)
            && !self::pathStartsWith($comparisonPath, $root)
        ) {
            throw new \InvalidArgumentException('Runtime resource paths must remain inside their owning root.');
        }

        return $path;
    }

    private static function resolveExistingAncestor(string $path): string
    {
        $segments = [];
        $ancestor = $path;
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                return self::normalizeLexical($path);
            }
            array_unshift($segments, basename($ancestor));
            $ancestor = $parent;
        }

        if (is_link($ancestor) && realpath($ancestor) === false) {
            throw new \InvalidArgumentException(
                'Runtime resource paths must remain inside their owning root.'
            );
        }

        $ancestor = self::normalize($ancestor);
        $suffix = '';
        foreach ($segments as $segment) {
            $suffix .= DIRECTORY_SEPARATOR . $segment;
        }

        return self::normalizeLexical($ancestor . $suffix);
    }

    private static function pathsMatch(string $first, string $second): bool
    {
        $first = self::normalizeLexical($first);
        $second = self::normalizeLexical($second);

        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($first, $second) === 0 : $first === $second;
    }

    private static function pathEndsWith(string $path, string $suffix): bool
    {
        $path = self::normalizeLexical($path);
        $suffix = self::normalizeLexical($suffix);

        // This resolver must remain usable before Composer can load DevElation helpers.
        return PHP_OS_FAMILY === 'Windows'
            ? \str_ends_with(\strtolower($path), \strtolower($suffix))
            : \str_ends_with($path, $suffix);
    }

    private static function configuredVendorAutoloader(string $hostRoot): ?string
    {
        $vendorRoot = self::configuredVendorRoot($hostRoot);

        return $vendorRoot === null ? null : self::join($vendorRoot, 'autoload.php');
    }

    private static function configuredVendorRoot(string $hostRoot): ?string
    {
        $composerPath = self::join($hostRoot, 'composer.json');
        if (!is_file($composerPath)) {
            return null;
        }

        $contents = file_get_contents($composerPath);
        if (!is_string($contents)) {
            return null;
        }

        $composer = \json_decode($contents, true);
        $vendorDirectory = \is_array($composer) ? ($composer['config']['vendor-dir'] ?? null) : null;
        if (!is_string($vendorDirectory) || $vendorDirectory === '') {
            return null;
        }

        return self::isAbsolute($vendorDirectory)
            ? $vendorDirectory
            : self::normalizeLexical(
                rtrim($hostRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($vendorDirectory, '/\\')
            );
    }

    private static function configuredVendorHost(array $searchRoots, string $autoloadPath): ?string
    {
        $checked = [];
        foreach ($searchRoots as $searchRoot) {
            $candidate = self::normalizeLexical((string) $searchRoot);
            while (!isset($checked[$candidate])) {
                $checked[$candidate] = true;
                $configuredAutoloader = self::configuredVendorAutoloader($candidate);
                if ($configuredAutoloader !== null && self::pathsMatch($configuredAutoloader, $autoloadPath)) {
                    return self::normalize($candidate);
                }
                if (
                    basename($candidate) === 'vendor'
                    && self::pathsMatch(self::join($candidate, 'autoload.php'), $autoloadPath)
                ) {
                    return self::normalize(dirname($candidate));
                }
                $parent = dirname($candidate);
                if ($parent === $candidate) {
                    break;
                }
                $candidate = $parent;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function ancestorDirectories(string $path): array
    {
        $ancestors = [];
        $ancestor = dirname(self::normalizeLexical($path));

        while (true) {
            $ancestors[] = $ancestor;
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                return $ancestors;
            }
            $ancestor = $parent;
        }
    }

    private static function isAbsolute(string $path): bool
    {
        return \str_starts_with($path, '/')
            || \str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\/\\\\]/', $path) === 1;
    }

    private static function pathStartsWith(string $path, string $root): bool
    {
        $path = self::normalizeLexical($path);
        $prefix = self::normalizeLexical($root) . DIRECTORY_SEPARATOR;
        $length = strlen($prefix);

        return PHP_OS_FAMILY === 'Windows'
            ? strncasecmp($path, $prefix, $length) === 0
            : strncmp($path, $prefix, $length) === 0;
    }

    private static function normalize(string $path): string
    {
        $resolved = realpath($path);
        return self::normalizeLexical($resolved !== false ? $resolved : $path);
    }

    private static function normalizeLexical(string $path): string
    {
        $isUncPath = preg_match('#^[\\\\/]{2}[^\\\\/]#', $path) === 1;
        $path = preg_replace('#[\\\\/]+#', DIRECTORY_SEPARATOR, $path) ?? $path;
        if ($isUncPath) {
            $path = DIRECTORY_SEPARATOR . $path;
        }

        if ($path === DIRECTORY_SEPARATOR || preg_match('/^[A-Za-z]:[\\/\\\\]$/', $path) === 1) {
            return $path;
        }

        return rtrim($path, '/\\');
    }
}
