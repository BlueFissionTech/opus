<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Automata\LLM\Clients\IClient;
use BlueFission\Services\Service;
use BlueFission\Vibrato\Reader;
use BlueFission\Vibrato\Validation\VibeSyntaxValidator;
use InvalidArgumentException;
use Throwable;

class VibratoGenerationService extends Service
{
    private ?IClient $llmClient;
    private VibeSyntaxValidator $validator;

    public function __construct(?IClient $llmClient = null, ?VibeSyntaxValidator $validator = null)
    {
        $this->llmClient = $llmClient;
        $this->validator = $validator ?? new VibeSyntaxValidator();

        parent::__construct();
    }

    public function validateSource(string $source): array
    {
        return $this->validator->validate($source)->toArray();
    }

    public function validateFile(string $path): array
    {
        if (!is_file($path)) {
            return $this->failure("Vibe source file was not found.");
        }

        $source = file_get_contents($path);
        if ($source === false) {
            return $this->failure("Vibe source file could not be read.");
        }

        return $this->validateSource($source);
    }

    public function renderSource(string $source, array $variables = [], array $includePaths = [], array $options = []): array
    {
        $validation = $this->validateSource($source);
        if (!$validation['valid']) {
            return $validation + [
                'output' => '',
                'variables' => [],
            ];
        }

        try {
            $reader = $this->reader($variables, $includePaths);
            $reader->input($source);
            $resolvedVariables = $reader->run([
                'run_backend' => (bool)($options['run_backend'] ?? false),
                'validate_syntax' => false,
            ]);

            return [
                'valid' => true,
                'errors' => [],
                'output' => $reader->output(),
                'variables' => $resolvedVariables,
            ];
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage()) + [
                'output' => '',
                'variables' => [],
            ];
        }
    }

    public function renderFile(string $path, array $variables = [], array $includePaths = [], array $options = []): array
    {
        if (!is_file($path)) {
            return $this->failure("Vibe source file was not found.") + [
                'output' => '',
                'variables' => [],
            ];
        }

        $validation = $this->validateFile($path);
        if (!$validation['valid']) {
            return $validation + [
                'output' => '',
                'variables' => [],
            ];
        }

        try {
            $includePaths[] = dirname($path);

            $reader = $this->reader($variables, array_values(array_unique($includePaths)));
            $reader->inputFile($path);
            $resolvedVariables = $reader->run([
                'run_backend' => (bool)($options['run_backend'] ?? false),
                'validate_syntax' => false,
            ]);

            return [
                'valid' => true,
                'errors' => [],
                'output' => $reader->output(),
                'variables' => $resolvedVariables,
            ];
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage()) + [
                'output' => '',
                'variables' => [],
            ];
        }
    }

    public function writeRenderedFile(string $sourcePath, string $outputPath, array $variables = [], array $includePaths = [], array $options = []): array
    {
        $result = $this->renderFile($sourcePath, $variables, $includePaths, $options);
        if (!$result['valid']) {
            return $result;
        }

        try {
            $target = $this->resolveWorkspacePath($outputPath);
            $directory = dirname($target);

            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                return $this->failure("Rendered output directory could not be created.") + [
                    'output' => $result['output'],
                    'variables' => $result['variables'],
                ];
            }

            if (file_put_contents($target, $result['output']) === false) {
                return $this->failure("Rendered output file could not be written.") + [
                    'output' => $result['output'],
                    'variables' => $result['variables'],
                ];
            }

            $result['path'] = $target;
            return $result;
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage()) + [
                'output' => $result['output'],
                'variables' => $result['variables'],
            ];
        }
    }

    private function reader(array $variables, array $includePaths): Reader
    {
        $reader = new Reader($this->llmClient);

        if ($variables !== []) {
            $reader->setVariables($variables);
        }

        if ($includePaths !== []) {
            $reader->setIncludePaths($includePaths);
        }

        return $reader;
    }

    private function failure(string $message): array
    {
        return [
            'valid' => false,
            'errors' => [
                [
                    'message' => $message,
                    'line' => null,
                    'column' => null,
                ],
            ],
        ];
    }

    private function resolveWorkspacePath(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException("Output path must not be empty.");
        }

        $root = realpath(getcwd()) ?: getcwd();
        $target = $this->isAbsolutePath($path)
            ? $path
            : $root . DIRECTORY_SEPARATOR . $path;

        $normalizedRoot = rtrim($this->normalizePath($root), '/') . '/';
        $normalizedTarget = $this->normalizePath($target);

        if (!str_starts_with($normalizedTarget, $normalizedRoot)) {
            throw new InvalidArgumentException("Output path must stay inside the application workspace.");
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $normalizedTarget);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';

        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            $prefix = substr($path, 0, 3);
            $path = substr($path, 3);
        } elseif (str_starts_with($path, '/')) {
            $prefix = '/';
            $path = ltrim($path, '/');
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return $prefix . implode('/', $segments);
    }
}
