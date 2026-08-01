<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
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

        return Arr::is($scripts) ? $scripts : [];
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
            ->filter(fn ($script) => Arr::is($script) && !$this->isRequiredScript($script))
            ->values()
            ->val();
    }

    public function readinessReport(): array
    {
        $manifest = Arr::make($this->manifest());
        $scripts = Arr::make($this->scripts());
        $missing = Arr::make([]);
        $invalid = Arr::make([]);

        foreach ($scripts as $script) {
            if (!Arr::is($script)) {
                $invalid->push('script entry is not an object');
                continue;
            }

            $script = Arr::make($script);
            $path = (string) ($script->get('path') ?? '');
            if ($path === '') {
                $invalid->push('script entry is missing a path');
                continue;
            }

            if (!FileSystem::fileExists($this->path($path))) {
                $missing->push($path);
            }
        }

        $fixture = (string) ($manifest->get('fixture') ?? '');
        if ($fixture !== '' && !FileSystem::fileExists($this->path($fixture))) {
            $missing->push($fixture);
        }

        return [
            'name' => (string) ($manifest->get('name') ?? 'opus-runtime-contract-proof'),
            'runtime' => (string) ($manifest->get('runtime') ?? 'jenerator'),
            'script_count' => $scripts->count(),
            'required_count' => Arr::make($this->requiredScripts())->count(),
            'optional_count' => Arr::make($this->optionalTargets())->count(),
            'missing' => $missing->val(),
            'invalid' => $invalid->val(),
            'ready' => $missing->isEmpty() && $invalid->isEmpty(),
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

    private function readJson(string $path): array
    {
        if (!FileSystem::fileExists($path)) {
            throw new RuntimeException("Runtime contract manifest not found.");
        }

        $contents = FileSystem::fileContents($path);
        if ($contents === null) {
            throw new RuntimeException("Runtime contract manifest could not be read.");
        }

        $decoded = json_decode($contents, true);
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
