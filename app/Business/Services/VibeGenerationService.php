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
use RuntimeException;
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

        $source = FileSystem::fileContents($path);
        if ($source === null) {
            return $this->withRenderingContext($this->failure("Vibe source file could not be read."));
        }

        $includePaths = Arr::make($includePaths)
            ->push(dirname($path))
            ->unique()
            ->values()
            ->val();

        return $this->renderSource($source, $variables, $includePaths, $options);
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

            if (!$this->replaceFile($target, (string) $result->get('output'))) {
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

    private function replaceFile(string $target, string $contents): bool
    {
        try {
            $verifiedTarget = $this->resolveWorkspacePath($target);
            if ($this->normalizePath($verifiedTarget) !== $this->normalizePath($target)) {
                return false;
            }

            [$temporary, $handle] = $this->createTemporaryFile(dirname($verifiedTarget));
            if (!$this->temporaryPathIsSafe($temporary, $handle, $verifiedTarget)) {
                return false;
            }

            try {
                return $this->publishTemporaryFile(
                    $temporary,
                    $handle,
                    $verifiedTarget,
                    $contents
                );
            } finally {
                fclose($handle);
                if (
                    $temporary !== ''
                    && (FileSystem::fileExists($temporary) || is_link($temporary))
                ) {
                    unlink($temporary);
                }
            }
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function createTemporaryFile(string $directory): array
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $path = $directory . DIRECTORY_SEPARATOR
                . '.opus-' . bin2hex(random_bytes(16));
            $handle = @fopen($path, 'x+b');
            if (is_resource($handle)) {
                return [$path, $handle];
            }
        }

        throw new RuntimeException("Rendered output temporary file could not be created.");
    }

    private function publishTemporaryFile(
        string &$temporary,
        $handle,
        string $target,
        string $contents
    ): bool
    {
        if (!$this->writeTemporaryFile($handle, $contents)) {
            return false;
        }

        if (!$this->temporaryPathIsSafe($temporary, $handle, $target)) {
            return false;
        }

        if (!rename($temporary, $target)) {
            return false;
        }
        $temporary = '';

        return $this->pathMatchesHandle($target, $handle)
            && FileSystem::fileContents($target) === $contents;
    }

    private function writeTemporaryFile($handle, string $contents): bool
    {
        if (!ftruncate($handle, 0) || !rewind($handle)) {
            return false;
        }

        // Keep byte offsets native at this low-level stream boundary.
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                return false;
            }
            $offset += $written;
        }

        if (!fflush($handle)) {
            return false;
        }
        if (function_exists('fchmod') && !fchmod($handle, 0644)) {
            return false;
        }

        return !function_exists('fsync') || fsync($handle);
    }

    private function temporaryPathIsSafe(
        string $temporary,
        $handle,
        string $target
    ): bool
    {
        if (!$this->pathMatchesHandle($temporary, $handle)) {
            return false;
        }

        $resolvedTemporary = realpath($temporary);
        if (!Str::is($resolvedTemporary) || $resolvedTemporary === '') {
            return false;
        }

        $normalizedRoot = Str::make($this->normalizePath($this->workspace));
        if (!$normalizedRoot->endsWith('/')) {
            $normalizedRoot->append('/');
        }
        $this->assertInsideWorkspace(
            $this->normalizePath($resolvedTemporary),
            $normalizedRoot->val()
        );

        return $this->normalizePath($this->resolveWorkspacePath($target))
            === $this->normalizePath($target);
    }

    private function pathMatchesHandle(string $path, $handle): bool
    {
        if (is_link($path)) {
            return false;
        }

        $pathStatus = lstat($path);
        $handleStatus = fstat($handle);

        return Arr::is($pathStatus)
            && Arr::is($handleStatus)
            && $pathStatus['dev'] === $handleStatus['dev']
            && $pathStatus['ino'] === $handleStatus['ino'];
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

        $pathSegments = Str::make(Str::replace($path, '\\', '/'))->split('/');
        if ($pathSegments->has('..', true)) {
            throw new InvalidArgumentException("Output path must not contain parent directory traversal.");
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
        $resolvedTarget = $this->resolveExistingPath($normalizedTarget);
        $this->assertInsideWorkspace($resolvedTarget, $normalizedRoot->val());

        return Str::replace($resolvedTarget, '/', DIRECTORY_SEPARATOR);
    }

    private function assertInsideWorkspace(string $path, string $root): void
    {
        if (!Str::startsWith($path, $root)) {
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
            || $path->startsWith('\\\\')
            || $path->startsWith('/')
            || $path->matches('/^[A-Za-z]:[\/\\\\]/');
    }

    private function normalizePath(string $path): string
    {
        $path = Str::replace($path, '\\', '/');
        $prefix = '';

        if (Str::startsWith($path, '//')) {
            $prefix = '//';
            $path = Str::make($path)->trim('/')->val();
        } elseif (Str::make($path)->matches('/^[A-Za-z]:\//')) {
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
