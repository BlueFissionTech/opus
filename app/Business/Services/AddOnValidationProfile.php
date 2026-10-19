<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Net\HTTP;
use BlueFission\Services\Service;
use BlueFission\Str;

/** Resolves tooling ownership without executing manifests or bootstraps. */
final class AddOnValidationProfile extends Service
{
    public const LAYERS = [
        'http' => 'logic/Business/Http',
        'commands' => 'logic/Business/Managers',
        'prompts' => 'logic/Business/Prompts',
        'models' => 'logic/Domain/Models',
        'queries' => 'logic/Domain/Queries',
        'repositories' => 'logic/Domain/Repositories',
        'values' => 'logic/Domain/Values',
        'assets' => 'resource/src',
    ];

    public function inspect(string $root, array $definition, string $profile, ?string $hostRoot): array
    {
        $errors = Arr::make([]);
        $warnings = Arr::make([]);
        $composer = [];
        $inherited = false;
        $problem = static fn (string $code, string $path, string $message): array => [
            'code' => $code, 'path' => $path, 'message' => $message,
        ];
        if (!Arr::make(['package', 'embedded'])->has($profile, true)) {
            $errors->push($problem('validation_profile', '.', 'Validation profile must be package or embedded.'));
        }

        $host = $hostRoot === null || Str::contains($hostRoot, "\0") ? false : realpath($hostRoot);
        if ($profile === 'embedded' && ($host === false || !$this->inside($root, $host))) {
            $errors->push($problem('embedded_host', '.', 'Embedded validation requires a containing host root.'));
            $host = false;
        }
        foreach (['composer.json', 'phpunit.xml', 'tests/bootstrap.php'] as $relative) {
            $path = $root . '/' . $relative;
            $owner = $root;
            $fromHost = false;
            // A broken link or directory is a malformed local entry, not an absent file.
            $localEntry = FileSystem::fileExists($path) || FileSystem::directoryExists($path) || is_link($path);
            if (!$localEntry && $profile === 'embedded' && $host !== false) {
                $path = $host . '/' . $relative;
                $owner = $host;
                $fromHost = true;
            }
            $resolved = realpath($path);
            if ($resolved === false || !FileSystem::fileExists($resolved) || !$this->inside($resolved, $owner)) {
                $errors->push($problem('profile_file_missing', $relative, 'Tooling file must exist inside its owning root.'));
                continue;
            }
            if ($fromHost) {
                $warnings->push($problem('host_tooling_inherited', $relative, 'Embedded add-on inherits this host tooling file.'));
            }
            if ($relative === 'composer.json') {
                $value = HTTP::jsonDecode((string) FileSystem::fileContents($resolved), true);
                if (!Arr::is($value)) {
                    $errors->push($problem('profile_composer_invalid', $relative, 'Composer metadata must be a JSON object.'));
                    continue;
                }
                $composer = $value;
                $inherited = $fromHost;
                if ($fromHost && !$this->hostMapsLogic($composer, $definition, $root, $host)) {
                    $errors->push($problem('host_psr4_mapping', $relative, 'Host Composer must map the exact package namespace to its logic directory.'));
                }
            }
        }

        $definition = Arr::make($definition);
        if (!$definition->hasKey('layers')) {
            $warnings->push($problem('layers_undeclared', 'definition.json', 'Declare required layers; unused domain directories are optional.'));
        } else {
            $layers = $definition->get('layers');
            // PHP's contiguous-list contract is stricter than Arr::isIndexed.
            if (!Arr::is($layers) || !array_is_list($layers)) {
                $errors->push($problem('layers_manifest', 'definition.json', 'Layers must be a list of supported layer names.'));
            } else {
                $seen = Arr::make([]);
                $paths = Arr::make(self::LAYERS);
                foreach ($layers as $layer) {
                    if (!Str::is($layer) || !$paths->hasKey($layer) || $seen->has($layer, true)) {
                        $errors->push($problem('layers_manifest', 'definition.json', 'Layer names must be supported and unique.'));
                        continue;
                    }
                    $seen->push($layer);
                    $path = $paths->get($layer);
                    $resolved = realpath($root . '/' . $path);
                    if ($resolved === false || !FileSystem::directoryExists($resolved) || !$this->inside($resolved, $root)) {
                        $errors->push($problem('layer_missing', $path, 'Declared capability layer is missing.'));
                    }
                }
            }
        }
        return [
            'composer' => $composer,
            'inherited_composer' => $inherited,
            'errors' => $errors->val(),
            'warnings' => $warnings->val(),
        ];
    }

    private function hostMapsLogic(array $composer, array $definition, string $root, string $host): bool
    {
        $namespace = Arr::make($definition)->get('namespace');
        if (!Str::is($namespace)) {
            return false;
        }
        $mapping = Arr::getPath($composer, ['autoload', 'psr-4', $namespace . '\\']);
        $paths = Str::is($mapping) ? [$mapping] : $mapping;
        if (!Arr::is($paths)) {
            return false;
        }
        $expected = realpath($root . '/logic');
        foreach ($paths as $path) {
            if (!Str::is($path) || Str::contains($path, "\0")) {
                continue;
            }
            $resolved = realpath($host . '/' . $path);
            if ($resolved !== false && $expected !== false && $resolved === $expected) {
                return true;
            }
        }
        return false;
    }

    private function inside(string $path, string $root): bool
    {
        $path = Str::make($path)->replace('\\', '/');
        $root = Str::make($root)->replace('\\', '/')->replacePattern('~/+$~', '')->append('/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path->lower();
            $root->lower();
        }
        return $path->startsWith($root->val());
    }
}
