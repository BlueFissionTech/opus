<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Automata\LLM\Clients\IClient;
use BlueFission\Data\FileSystem;
use BlueFission\Services\Service;
use BlueFission\Str;
use BlueFission\Vibrato\Reader;
use BlueFission\Vibrato\Validation\VibeSyntaxValidator;
use InvalidArgumentException;
use Throwable;

class VibeGenerationService extends Service
{
    private ?IClient $llmClient;
    private VibeSyntaxValidator $validator;
    private string $workspace;

    public function __construct(
        ?IClient $llmClient = null,
        ?VibeSyntaxValidator $validator = null,
        ?string $workspace = null
    )
    {
        $this->llmClient = $llmClient;
        $this->validator = $validator ?? new VibeSyntaxValidator();
        $workspace = $workspace ?? (
            defined('APP_ROOT')
                ? (string) constant('APP_ROOT')
                : dirname(__DIR__, 3)
        );
        $resolvedWorkspace = realpath($workspace);
        if (!Str::is($resolvedWorkspace) || $resolvedWorkspace === '') {
            throw new InvalidArgumentException("Application workspace could not be resolved.");
        }
        $this->workspace = $resolvedWorkspace;

        parent::__construct();
    }

    public function validateSource(string $source): array
    {
        return $this->validator->validate($source)->toArray();
    }

    public function validateFile(string $path): array
    {
        if (!FileSystem::fileExists($path)) {
            return $this->failure("Vibe source file was not found.");
        }

        $source = FileSystem::fileContents($path);
        if ($source === null) {
            return $this->failure("Vibe source file could not be read.");
        }

        return $this->validateSource($source);
    }

    public function renderSource(string $source, array $variables = [], array $includePaths = [], array $options = []): array
    {
        $validation = Arr::make($this->validateSource($source));
        if (!$validation->get('valid')) {
            return $this->withRenderingContext($validation->val());
        }

        try {
            $reader = $this->reader($variables, $includePaths);
            $reader->input($source);
            $options = Arr::make($options);
            $resolvedVariables = $reader->run([
                'run_backend' => (bool) ($options->get('run_backend') ?? false),
                'validate_syntax' => false,
            ]);

            return [
                'valid' => true,
                'errors' => [],
                'output' => $reader->output(),
                'variables' => $resolvedVariables,
            ];
        } catch (Throwable $exception) {
            return $this->withRenderingContext($this->failure($exception->getMessage()));
        }
    }

    public function renderFile(string $path, array $variables = [], array $includePaths = [], array $options = []): array
    {
        if (!FileSystem::fileExists($path)) {
            return $this->withRenderingContext($this->failure("Vibe source file was not found."));
        }

        $validation = Arr::make($this->validateFile($path));
        if (!$validation->get('valid')) {
            return $this->withRenderingContext($validation->val());
        }

        try {
            $includePaths = Arr::make($includePaths)
                ->push(dirname($path))
                ->unique()
                ->values()
                ->val();

            $reader = $this->reader($variables, $includePaths);
            $reader->inputFile($path);
            $options = Arr::make($options);
            $resolvedVariables = $reader->run([
                'run_backend' => (bool) ($options->get('run_backend') ?? false),
                'validate_syntax' => false,
            ]);

            return [
                'valid' => true,
                'errors' => [],
                'output' => $reader->output(),
                'variables' => $resolvedVariables,
            ];
        } catch (Throwable $exception) {
            return $this->withRenderingContext($this->failure($exception->getMessage()));
        }
    }

    public function writeRenderedFile(string $sourcePath, string $outputPath, array $variables = [], array $includePaths = [], array $options = []): array
    {
        $result = Arr::make($this->renderFile($sourcePath, $variables, $includePaths, $options));
        if (!$result->get('valid')) {
            return $result->val();
        }

        try {
            $target = $this->resolveWorkspacePath($outputPath);
            $directory = dirname($target);

            if (!$this->ensureDirectory($directory)) {
                return $this->withRenderingContext(
                    $this->failure("Rendered output directory could not be created."),
                    (string) $result->get('output'),
                    Arr::make($result->get('variables'))->val()
                );
            }

            $file = new FileSystem([
                'root' => $directory,
                'mode' => 'w',
                'filter' => 'file',
                'doNotConfirm' => true,
            ]);
            $file->open((string) FileSystem::fileBasename($target))
                ->contents($result->get('output'))
                ->write()
                ->close();

            if (FileSystem::fileContents($target) !== $result->get('output')) {
                return $this->withRenderingContext(
                    $this->failure("Rendered output file could not be written."),
                    (string) $result->get('output'),
                    Arr::make($result->get('variables'))->val()
                );
            }

            $result->set('path', $target);

            return $result->val();
        } catch (Throwable $exception) {
            return $this->withRenderingContext(
                $this->failure($exception->getMessage()),
                (string) $result->get('output'),
                Arr::make($result->get('variables'))->val()
            );
        }
    }

    private function reader(array $variables, array $includePaths): Reader
    {
        $reader = new Reader($this->llmClient);
        $variables = Arr::make($variables);
        $includePaths = Arr::make($includePaths);

        if ($variables->isNotEmpty()) {
            $reader->setVariables($variables->val());
        }

        if ($includePaths->isNotEmpty()) {
            $reader->setIncludePaths($includePaths->val());
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

    private function withRenderingContext(array $result, string $output = '', array $variables = []): array
    {
        $result = Arr::make($result);
        $result->set('output', $output);
        $result->set('variables', $variables);

        return $result->val();
    }

    private function ensureDirectory(string $directory): bool
    {
        if (FileSystem::directoryExists($directory)) {
            return true;
        }

        $parent = dirname($directory);
        if ($parent === $directory || !$this->ensureDirectory($parent)) {
            return false;
        }

        $filesystem = new FileSystem([
            'root' => $parent,
            'mode' => 'w',
            'filter' => 'file',
            'doNotConfirm' => true,
        ]);
        $filesystem->mkdir((string) FileSystem::fileBasename($directory));

        return FileSystem::directoryExists($directory);
    }

    private function resolveWorkspacePath(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException("Output path must not be empty.");
        }

        $root = $this->workspace;
        $target = $this->isAbsolutePath($path)
            ? $path
            : $root . DIRECTORY_SEPARATOR . $path;

        $normalizedRoot = Str::make($this->normalizePath($root));
        if (!$normalizedRoot->endsWith('/')) {
            $normalizedRoot->append('/');
        }

        $normalizedTarget = $this->normalizePath($target);
        $this->assertInsideWorkspace($normalizedTarget, $normalizedRoot->val());

        $resolvedTarget = $this->resolveExistingPath($normalizedTarget);
        $this->assertInsideWorkspace($resolvedTarget, $normalizedRoot->val());

        return Str::replace($resolvedTarget, '/', DIRECTORY_SEPARATOR);
    }

    private function assertInsideWorkspace(string $path, string $root): void
    {
        $comparisonPath = Str::make($path);
        $comparisonRoot = Str::make($root);
        if (PHP_OS_FAMILY === 'Windows') {
            $comparisonPath->lower();
            $comparisonRoot->lower();
        }

        if (!$comparisonPath->startsWith($comparisonRoot->val())) {
            throw new InvalidArgumentException("Output path must stay inside the application workspace.");
        }
    }

    private function resolveExistingPath(string $path): string
    {
        $candidate = Str::replace($path, '/', DIRECTORY_SEPARATOR);
        $remaining = Arr::make([]);

        while (
            !FileSystem::fileExists($candidate)
            && !FileSystem::directoryExists($candidate)
            && !is_link($candidate)
        ) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                throw new InvalidArgumentException("Output path could not be resolved.");
            }

            $remaining->unshift((string) FileSystem::fileBasename($candidate));
            $candidate = $parent;
        }

        $resolved = realpath($candidate);
        if (!Str::is($resolved) || $resolved === '') {
            throw new InvalidArgumentException("Output path could not be resolved.");
        }

        $resolved = $this->normalizePath($resolved);
        if ($remaining->isEmpty()) {
            return $resolved;
        }

        return Str::make($resolved)
            ->append('/')
            ->append($remaining->join('/')->val())
            ->val();
    }

    private function isAbsolutePath(string $path): bool
    {
        $path = Str::make($path);

        return $path->startsWith(DIRECTORY_SEPARATOR)
            || $path->matches('/^[A-Za-z]:[\/\\\\]/');
    }

    private function normalizePath(string $path): string
    {
        $path = Str::replace($path, '\\', '/');
        $prefix = '';

        if (Str::make($path)->matches('/^[A-Za-z]:\//')) {
            $prefix = Str::sub($path, 0, 3);
            $path = Str::sub($path, 3);
        } elseif (Str::startsWith($path, '/')) {
            $prefix = '/';
            $path = Str::make($path)->trim('/')->val();
        }

        $segments = Arr::make([]);
        foreach (Str::make($path)->split('/')->val() as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                $segments->pop();
                continue;
            }

            $segments->push($segment);
        }

        return $prefix . $segments->join('/')->val();
    }
}
