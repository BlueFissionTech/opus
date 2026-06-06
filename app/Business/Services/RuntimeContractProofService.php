<?php

namespace App\Business\Services;

use BlueFission\Services\Service;
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
        $manifest = $this->manifest();
        $scripts = $manifest['scripts'] ?? [];

        return is_array($scripts) ? $scripts : [];
    }

    public function requiredScripts(): array
    {
        return array_values(array_filter(
            $this->scripts(),
            fn ($script) => is_array($script) && ($script['required'] ?? true)
        ));
    }

    public function optionalTargets(): array
    {
        return array_values(array_filter(
            $this->scripts(),
            fn ($script) => is_array($script) && !($script['required'] ?? true)
        ));
    }

    public function readinessReport(): array
    {
        $manifest = $this->manifest();
        $scripts = $this->scripts();
        $missing = [];
        $invalid = [];

        foreach ($scripts as $script) {
            if (!is_array($script)) {
                $invalid[] = 'script entry is not an object';
                continue;
            }

            $path = (string) ($script['path'] ?? '');
            if ($path === '') {
                $invalid[] = 'script entry is missing a path';
                continue;
            }

            if (!is_file($this->path($path))) {
                $missing[] = $path;
            }
        }

        $fixture = (string) ($manifest['fixture'] ?? '');
        if ($fixture !== '' && !is_file($this->path($fixture))) {
            $missing[] = $fixture;
        }

        return [
            'name' => (string) ($manifest['name'] ?? 'opus-runtime-contract-proof'),
            'runtime' => (string) ($manifest['runtime'] ?? 'jenerator'),
            'script_count' => count($scripts),
            'required_count' => count($this->requiredScripts()),
            'optional_count' => count($this->optionalTargets()),
            'missing' => $missing,
            'invalid' => $invalid,
            'ready' => $missing === [] && $invalid === [],
        ];
    }

    public function validationCommand(): string
    {
        return 'php examples/jenss/validate.php';
    }

    private function path(string $relativePath): string
    {
        return $this->_root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Runtime contract manifest not found.");
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException("Runtime contract manifest could not be read.");
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Runtime contract manifest is not valid JSON.");
        }

        return $decoded;
    }
}
