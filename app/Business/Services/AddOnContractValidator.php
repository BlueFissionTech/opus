<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Services\Service;
use BlueFission\Str;
use BlueFission\Vibrato\Validation\VibeSyntaxValidator;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class AddOnContractValidator extends Service
{
    private VibeSyntaxValidator $vibeValidator;

    private const REQUIRED_FILES = [
        'composer.json',
        'definition.json',
        'main.php',
        'README.md',
        'logic/Registration/AddOnRegistration.php',
        'mapping/api.php',
        'mapping/app.php',
        'mapping/console.php',
        'mapping/default.php',
        'mapping/menus.php',
        'resource/markup/default.vibe',
        'phpunit.xml',
        'tests/bootstrap.php',
    ];

    private const REQUIRED_DIRECTORIES = [
        'datasources/generator',
        'datasources/structure',
        'logic/Business/Http',
        'logic/Business/Managers',
        'logic/Business/Prompts',
        'logic/Domain/Models',
        'logic/Domain/Queries',
        'logic/Domain/Repositories',
        'logic/Domain/Values',
        'resource/markup',
        'resource/src',
        'tests',
    ];

    public function __construct(?VibeSyntaxValidator $vibeValidator = null)
    {
        parent::__construct();

        $this->vibeValidator = $vibeValidator ?? new VibeSyntaxValidator();
    }

    public function validate(string $root): array
    {
        $errors = Arr::make([]);
        $warnings = Arr::make([]);
        $resolvedRoot = realpath($root);

        if (!Str::is($resolvedRoot) || !FileSystem::directoryExists((string) $resolvedRoot)) {
            $errors->push($this->problem('root_missing', '.', 'Add-on root directory was not found.'));

            return $this->report($errors, $warnings);
        }

        $root = (string) $resolvedRoot;
        foreach (Arr::make(self::REQUIRED_FILES) as $path) {
            if (!FileSystem::fileExists($this->path($root, (string) $path))) {
                $errors->push($this->problem('file_missing', (string) $path, 'Required file is missing.'));
            }
        }
        foreach (Arr::make(self::REQUIRED_DIRECTORIES) as $path) {
            if (!FileSystem::directoryExists($this->path($root, (string) $path))) {
                $errors->push($this->problem('directory_missing', (string) $path, 'Required directory is missing.'));
            }
        }

        $composer = $this->readJson($root, 'composer.json', $errors);
        $definition = $this->readJson($root, 'definition.json', $errors);
        $namespace = $this->validateMetadata($composer, $definition, $errors);

        $this->validatePhpFiles($root, $namespace, $errors);
        $this->validateVibeFiles($root, $errors);

        return $this->report($errors, $warnings);
    }

    private function validateMetadata(array $composer, array $definition, Arr $errors): string
    {
        $composer = Arr::make($composer);
        $definition = Arr::make($definition);
        $namespace = $definition->get('namespace');

        if ($composer->get('type') !== 'opus-addon') {
            $errors->push($this->problem('composer_type', 'composer.json', 'Composer type must be opus-addon.'));
        }

        $name = $definition->get('name');
        if (!Str::is($name) || !Str::make($name)->matches('/^[a-z][a-z0-9_]*$/')) {
            $errors->push($this->problem('definition_name', 'definition.json', 'Definition name must be a lowercase lifecycle-safe key.'));
        }
        if (!Str::is($namespace) || !Str::make($namespace)->matches('/^AddOns\\\\[A-Z][A-Za-z0-9]*$/')) {
            $errors->push($this->problem('definition_namespace', 'definition.json', 'Namespace must use AddOns\\PackageName.'));
            $namespace = '';
        }
        if ($definition->get('primary_file') !== 'main.php') {
            $errors->push($this->problem('primary_file', 'definition.json', 'Primary file must be main.php.'));
        }
        if (!Str::is($definition->get('version')) || Str::make($definition->get('version'))->trim()->isEmpty()) {
            $errors->push($this->problem('definition_version', 'definition.json', 'Definition version must be a nonempty string.'));
        }

        $extra = Arr::make(Arr::is($composer->get('extra')) ? $composer->get('extra') : []);
        if ($extra->get('installer-name') !== $name) {
            $errors->push($this->problem('installer_name', 'composer.json', 'Installer name must match definition name.'));
        }

        $autoload = Arr::make(Arr::is($composer->get('autoload')) ? $composer->get('autoload') : []);
        $psr4 = Arr::make(Arr::is($autoload->get('psr-4')) ? $autoload->get('psr-4') : []);
        $autoloadNamespace = $namespace === '' ? '' : $namespace . '\\';
        if ($autoloadNamespace === '' || $psr4->get($autoloadNamespace) !== 'logic/') {
            $errors->push($this->problem('psr4_mapping', 'composer.json', 'Package namespace must map to logic/.'));
        }

        return $namespace;
    }

    private function validatePhpFiles(string $root, string $namespace, Arr $errors): void
    {
        foreach ($this->files($root, 'php') as $file) {
            $relative = $this->relative($root, $file);
            $source = FileSystem::fileContents($file);
            if (!Str::is($source)) {
                $errors->push($this->problem('php_unreadable', $relative, 'PHP file could not be read.'));
                continue;
            }

            try {
                $tokens = token_get_all($source, TOKEN_PARSE);
            } catch (ParseError $exception) {
                $errors->push($this->problem('php_syntax', $relative, $exception->getMessage()));
                continue;
            }

            if (Str::startsWith($relative, 'logic/') && $namespace !== '') {
                $declared = $this->declaredNamespace($tokens);
                $directory = Str::make(dirname(Str::sub($relative, strlen('logic/'))))
                    ->replace('.', '')
                    ->replace('/', '\\')
                    ->replace('\\\\', '\\')
                    ->trim('\\')
                    ->val();
                $expected = $directory === '' ? $namespace : $namespace . '\\' . $directory;
                if ($declared !== $expected) {
                    $errors->push($this->problem('namespace_mismatch', $relative, "PHP namespace must be {$expected}."));
                }
            }

            if (Str::startsWith($relative, 'mapping/') && !$this->hasTopLevelArrayReturn($tokens)) {
                $errors->push($this->problem('mapping_contract', $relative, 'Mapping file must return a declarative array.'));
            }
        }
    }

    private function validateVibeFiles(string $root, Arr $errors): void
    {
        $markup = $this->path($root, 'resource/markup');
        if (!FileSystem::directoryExists($markup)) {
            return;
        }

        foreach ($this->allFiles($markup) as $file) {
            if (!Str::make($file)->endsWith('.vibe')) {
                $errors->push($this->problem('template_extension', $this->relative($root, $file), 'Server-rendered templates must use the .vibe extension.'));
                continue;
            }

            $source = FileSystem::fileContents($file);
            if (!Str::is($source)) {
                $errors->push($this->problem('template_unreadable', $this->relative($root, $file), 'Vibe template could not be read.'));
                continue;
            }

            $validation = Arr::make($this->vibeValidator->validate($source)->toArray());
            if (!$validation->get('valid')) {
                $errors->push($this->problem('template_syntax', $this->relative($root, $file), 'Vibe template syntax is invalid.'));
            }
        }
    }

    private function readJson(string $root, string $relative, Arr $errors): array
    {
        $path = $this->path($root, $relative);
        if (!FileSystem::fileExists($path)) {
            return [];
        }

        $contents = FileSystem::fileContents($path);
        if (!Str::is($contents)) {
            $errors->push($this->problem('json_unreadable', $relative, 'JSON file could not be read.'));

            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $errors->push($this->problem('json_invalid', $relative, $exception->getMessage()));

            return [];
        }

        if (!Arr::is($decoded)) {
            $errors->push($this->problem('json_shape', $relative, 'JSON root must be an object.'));

            return [];
        }

        return $decoded;
    }

    private function declaredNamespace(array $tokens): string
    {
        $collect = false;
        $namespace = Str::make('');

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $collect = true;
                continue;
            }
            if (!$collect) {
                continue;
            }
            if ($token === ';' || $token === '{') {
                break;
            }
            if (is_array($token) && Arr::make([T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR])->has($token[0], true)) {
                $namespace->append($token[1]);
            }
        }

        return $namespace->val();
    }

    private function hasTopLevelArrayReturn(array $tokens): bool
    {
        $depth = 0;
        $returnFound = false;

        foreach ($tokens as $token) {
            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                continue;
            }
            if ($returnFound) {
                if (is_array($token) && Arr::make([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])->has($token[0], true)) {
                    continue;
                }

                return $token === '[' || (is_array($token) && $token[0] === T_ARRAY);
            }
            if ($depth === 0 && is_array($token) && $token[0] === T_RETURN) {
                $returnFound = true;
            }
        }

        return false;
    }

    private function files(string $root, string $extension): array
    {
        return Arr::make($this->allFiles($root))
            ->filter(fn (string $file): bool => Str::make($file)->endsWith('.' . $extension))
            ->values()
            ->val();
    }

    private function allFiles(string $root): array
    {
        if (!FileSystem::directoryExists($root)) {
            return [];
        }

        $files = Arr::make([]);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files->push($item->getPathname());
            }
        }

        return $files->val();
    }

    private function report(Arr $errors, Arr $warnings): array
    {
        return [
            'valid' => $errors->isEmpty(),
            'errors' => $errors->val(),
            'warnings' => $warnings->val(),
            'error_count' => $errors->count(),
            'warning_count' => $warnings->count(),
        ];
    }

    private function problem(string $code, string $path, string $message): array
    {
        return compact('code', 'path', 'message');
    }

    private function path(string $root, string $relative): string
    {
        return $root . DIRECTORY_SEPARATOR . Str::make($relative)
            ->replace('/', DIRECTORY_SEPARATOR)
            ->val();
    }

    private function relative(string $root, string $path): string
    {
        return Str::make(substr($path, strlen($root)))
            ->trim('/\\')
            ->replace('\\', '/')
            ->val();
    }
}
