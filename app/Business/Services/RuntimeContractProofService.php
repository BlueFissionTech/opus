<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Net\HTTP;
use BlueFission\Services\Service;
use BlueFission\Str;
use RuntimeException;

class RuntimeContractProofService extends Service
{
    private string $_root;
    private string $_manifestPath;

    public function __construct(?string $root = null)
    {
        parent::__construct();

        $this->_root = $root ?: dirname(__DIR__, 3);
        $this->_manifestPath = $this->_root . DIRECTORY_SEPARATOR . 'examples'
            . DIRECTORY_SEPARATOR . 'jenss' . DIRECTORY_SEPARATOR . 'runtime-contract-proof.json';
    }

    public function manifest(): array
    {
        return $this->readJson($this->_manifestPath);
    }

    public function scripts(): array
    {
        $manifest = Arr::make($this->manifest());
        $scripts = $manifest->get('scripts');

        return Arr::is($scripts) && array_is_list($scripts) ? $scripts : [];
    }

    public function requiredScripts(): array
    {
        return Arr::make($this->scripts())
            ->filter(fn ($script) => $this->isRequiredScript($script))
            ->values()
            ->val();
    }

    public function optionalTargets(): array
    {
        return Arr::make($this->scripts())
            ->filter(fn ($script) => $this->isRenderableTarget($script))
            ->values()
            ->val();
    }

    public function readinessReport(): array
    {
        $manifest = Arr::make($this->manifest());
        $scripts = Arr::make($this->scripts());
        $missing = Arr::make([]);
        $invalid = Arr::make([]);

        $scriptDefinitions = $manifest->get('scripts');
        if (!Arr::is($scriptDefinitions) || !array_is_list($scriptDefinitions)) {
            $invalid->push('manifest scripts must be a list');
        }

        $name = $manifest->get('name');
        if (!Str::is($name) || Str::make($name)->trim()->isEmpty()) {
            $invalid->push('manifest name must be a nonempty string');
            $name = 'opus-runtime-contract-proof';
        } else {
            $name = Str::make($name)->trim()->val();
        }

        $runtime = $manifest->get('runtime');
        if (!Str::is($runtime) || Str::make($runtime)->trim()->isEmpty()) {
            $invalid->push('manifest runtime must be a nonempty string');
            $runtime = 'jenerator';
        } else {
            $runtime = Str::make($runtime)->trim()->val();
        }

        foreach ($scripts as $script) {
            if (!Arr::is($script)) {
                $invalid->push('script entry is not an object');
                continue;
            }

            $script = Arr::make($script);
            $path = $script->get('path');
            if (!Str::is($path) || Str::make($path)->trim()->isEmpty()) {
                $invalid->push('script entry is missing a path');
                continue;
            }
            $path = Str::make($path)->trim()->val();

            $mode = $script->get('mode') ?? 'execute';
            if (
                !Str::is($mode)
                || !Arr::make(['parse', 'execute'])->has($mode, true)
            ) {
                $invalid->push('script entry has an unsupported mode');
            }

            if ($script->hasKey('required') && !is_bool($script->get('required'))) {
                $invalid->push('script entry has a non-boolean required flag');
            }

            if (!$this->hasValidCapabilities($script)) {
                $invalid->push('script entry has invalid capabilities');
            }

            if (!FileSystem::fileExists($this->path($path))) {
                $missing->push($path);
            }
        }

        $fixture = $manifest->get('fixture');
        if (!Str::is($fixture) || Str::make($fixture)->trim()->isEmpty()) {
            $invalid->push('fixture entry is missing a path');
        } else {
            $fixture = Str::make($fixture)->trim()->val();
            if (!FileSystem::fileExists($this->path($fixture))) {
                $missing->push($fixture);
            }
        }

        return [
            'name' => $name,
            'runtime' => $runtime,
            'script_count' => $scripts->count(),
            'required_count' => Arr::make($this->requiredScripts())->count(),
            'optional_count' => Arr::make($this->optionalTargets())->count(),
            'missing' => $missing->val(),
            'invalid' => $invalid->val(),
            'ready' => $scripts->isNotEmpty() && $missing->isEmpty() && $invalid->isEmpty(),
        ];
    }

    public function validationCommand(): string
    {
        return 'php examples/jenss/validate.php';
    }

    private function path(string $relativePath): string
    {
        $path = Str::make($relativePath)
            ->replace('/', DIRECTORY_SEPARATOR)
            ->replace('\\', DIRECTORY_SEPARATOR)
            ->val();

        return $this->_root . DIRECTORY_SEPARATOR . $path;
    }

    private function isRenderableTarget($script): bool
    {
        if (!Arr::is($script)) {
            return false;
        }

        $script = Arr::make($script);
        $path = $script->get('path');
        $mode = $script->get('mode') ?? 'execute';

        return Str::is($path)
            && Str::make($path)->trim()->isNotEmpty()
            && Str::is($mode)
            && Arr::make(['parse', 'execute'])->has($mode, true)
            && $script->hasKey('required')
            && $script->get('required') === false
            && $this->hasValidCapabilities($script);
    }

    private function hasValidCapabilities(Arr $script): bool
    {
        if (!$script->hasKey('capabilities')) {
            return true;
        }

        $capabilities = $script->get('capabilities');
        if (!Arr::is($capabilities) || !array_is_list($capabilities)) {
            return false;
        }

        foreach (Arr::make($capabilities) as $capability) {
            if (!Str::is($capability) || Str::make($capability)->trim()->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    private function readJson(string $path): array
    {
        if (!FileSystem::fileExists($path)) {
            throw new RuntimeException("Runtime contract manifest not found.");
        }

        $contents = FileSystem::fileContents($path);
        if ($contents === null) {
            throw new RuntimeException("Runtime contract manifest could not be read.");
        }

        $decoded = HTTP::jsonDecode($contents, true);
        if (!Arr::is($decoded)) {
            throw new RuntimeException("Runtime contract manifest is not valid JSON.");
        }

        return $decoded;
    }

    private function isRequiredScript(mixed $script): bool
    {
        if (!Arr::is($script)) {
            return false;
        }

        $script = Arr::make($script);

        return !$script->hasKey('required') || (bool) $script->get('required');
    }
}
