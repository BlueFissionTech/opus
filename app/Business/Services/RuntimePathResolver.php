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

    public function __construct(
        ?string $packageRoot = null,
        ?string $hostRoot = null,
        ?string $activeAutoloader = null
    ) {
        $packageRoot = $packageRoot ?? dirname(__DIR__, 3);
        $this->packageInstallRoot = self::normalizeLexical($packageRoot);
        $this->packageRoot = self::normalize($packageRoot);
        $this->hostRoot = $hostRoot !== null && $hostRoot !== '' ? self::normalize($hostRoot) : null;
        $this->activeAutoloader = $activeAutoloader !== null && $activeAutoloader !== ''
            ? self::normalize($activeAutoloader)
            : null;
    }

    public static function discover(
        ?string $packageRoot = null,
        ?string $hostRoot = null,
        ?string $activeAutoloader = null
    ): self {
        return new self($packageRoot, $hostRoot, $activeAutoloader);
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

        return self::normalize(dirname(dirname($this->autoloadPath())));
    }

    public function autoloadPath(): string
    {
        $candidates = [];
        if ($this->activeAutoloader !== null) {
            $candidates[] = $this->activeAutoloader;
        }
        if ($this->hostRoot !== null) {
            $candidates[] = self::join($this->hostRoot, 'vendor/autoload.php');
        }
        if (basename($this->packageInstallRoot) === 'core') {
            $candidates[] = self::join(dirname($this->packageInstallRoot), 'vendor/autoload.php');
        }

        $ancestor = dirname($this->packageInstallRoot);
        while ($ancestor !== dirname($ancestor)) {
            if (basename($ancestor) === 'vendor') {
                $candidates[] = self::join($ancestor, 'autoload.php');
                break;
            }
            $ancestor = dirname($ancestor);
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
        $path = preg_replace('#[\\\\/]+#', DIRECTORY_SEPARATOR, $path) ?? $path;

        return rtrim($path, '/\\');
    }
}
