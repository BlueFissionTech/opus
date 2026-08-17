<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Net\HTTP;
use BlueFission\Services\Service;
use BlueFission\Str;
use BlueFission\Vibrato\Validation\VibeSyntaxValidator;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AddOnContractValidator extends Service
{
    private VibeSyntaxValidator $vibeValidator;

    private const REQUIRED_FILES = [
        'composer.json',
        'definition.json',
        'main.php',
        'README.md',
        'mapping/api.php',
        'mapping/app.php',
        'mapping/console.php',
        'mapping/default.php',
        'mapping/menus.php',
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

        $this->validateInstalledIdentity($root, $definition, $errors);
        $this->validateRegistration($root, $namespace, $definition, $errors);
        $this->validateThemes($root, $definition, $errors);
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
        if (!$this->isComposerPackageName($composer->get('name'))) {
            $errors->push($this->problem(
                'composer_name',
                'composer.json',
                'Composer package name must use a valid lowercase vendor/package slug.'
            ));
        }

        $name = $definition->get('name');
        if (!Str::is($name) || !Str::make($name)->matches('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/')) {
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

        $libraries = $definition->get('libraries');
        if (!Arr::is($libraries)) {
            $errors->push($this->problem(
                'libraries_manifest',
                'definition.json',
                'Libraries must be an array of Composer package names.'
            ));
        } else {
            Arr::make($libraries)->each(function ($library) use ($errors): void {
                if (!$this->isComposerPackageName($library)) {
                    $errors->push($this->problem(
                        'libraries_manifest',
                        'definition.json',
                        'Every library must use a valid lowercase Composer package name.'
                    ));
                }
            });
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

    private function validateInstalledIdentity(string $root, array $definition, Arr $errors): void
    {
        if (FileSystem::fileBasename(dirname($root)) !== 'addons') {
            return;
        }

        $name = Arr::make($definition)->get('name');
        if (!Str::is($name) || FileSystem::fileBasename($root) !== $name) {
            $errors->push($this->problem(
                'installed_identity',
                'definition.json',
                'Installed directory name must match the add-on lifecycle key.'
            ));
        }
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
                $directory = Str::make(dirname(Str::sub($relative, Str::len('logic/'))))
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

            if (Str::startsWith($relative, 'mapping/') && !$this->isSupportedMapping($tokens)) {
                $errors->push($this->problem(
                    'mapping_contract',
                    $relative,
                    'Mapping file must return a declarative array or contain only supported Mapping registrations.'
                ));
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

        $decoded = HTTP::jsonDecode($contents, true);

        if (!Arr::is($decoded)) {
            $errors->push($this->problem('json_invalid', $relative, 'JSON root must be a valid object.'));

            return [];
        }

        return $decoded;
    }

    private function declaredNamespace(array $tokens): string
    {
        $collect = false;
        $namespace = Str::make('');

        $namespaceTokens = Arr::make([T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR]);
        foreach ($tokens as $token) {
            if (Arr::is($token) && $token[0] === T_NAMESPACE) {
                $collect = true;
                continue;
            }
            if (!$collect) {
                continue;
            }
            if ($token === ';' || $token === '{') {
                break;
            }
            if (Arr::is($token) && $namespaceTokens->has($token[0], true)) {
                $namespace->append($token[1]);
            }
        }

        return $namespace->val();
    }

    private function validateRegistration(string $root, string $namespace, array $definition, Arr $errors): void
    {
        $definition = Arr::make($definition);
        $registration = Arr::make(
            Arr::is($definition->get('registration')) ? $definition->get('registration') : []
        );
        $class = $registration->get('class');
        $factory = $registration->get('factory');

        if (!Str::is($class)
            || $namespace === ''
            || !Str::startsWith((string) $class, $namespace . '\\')
        ) {
            $errors->push($this->problem(
                'registration_class',
                'definition.json',
                'Registration class must be declared beneath the package namespace.'
            ));
            return;
        }

        $classPath = 'logic/' . Str::make(Str::sub((string) $class, Str::len($namespace . '\\')))
            ->replace('\\', '/')
            ->append('.php')
            ->val();
        $classFile = $this->path($root, $classPath);
        if (!FileSystem::fileExists($classFile)) {
            $errors->push($this->problem('registration_class', $classPath, 'Registration class file is missing.'));
        } else {
            $source = FileSystem::fileContents($classFile);
            if (Str::is($source)) {
                try {
                    $tokens = token_get_all($source, TOKEN_PARSE);
                    $declared = Str::make($this->declaredNamespace($tokens))
                        ->append('\\')
                        ->append($this->declaredClass($tokens))
                        ->val();
                    if ($declared !== $class) {
                        $errors->push($this->problem(
                            'registration_class',
                            $classPath,
                            'Registration file must declare the configured class.'
                        ));
                    }
                } catch (ParseError) {
                    // The PHP syntax pass reports the malformed class file.
                }
            }
        }

        if (!Str::is($factory) || $factory !== $definition->get('primary_file')) {
            $errors->push($this->problem(
                'registration_factory',
                'definition.json',
                'Registration factory must reference the declared primary file.'
            ));
            return;
        }

        $factoryPath = $this->path($root, (string) $factory);
        $source = FileSystem::fileContents($factoryPath);
        if (!Str::is($source)) {
            return;
        }

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError) {
            return;
        }

        if (!$this->returnsCallableFactory($tokens)) {
            $errors->push($this->problem(
                'registration_factory',
                (string) $factory,
                'Registration factory must return a callable.'
            ));
        }
    }

    private function validateThemes(string $root, array $definition, Arr $errors): void
    {
        $themes = Arr::make(
            Arr::is(Arr::make($definition)->get('themes'))
                ? Arr::make($definition)->get('themes')
                : []
        );
        if ($themes->isEmpty()) {
            $errors->push($this->problem(
                'theme_manifest',
                'definition.json',
                'At least one Vibe theme entrypoint must be declared.'
            ));
            return;
        }

        $themes->each(function ($descriptor, $name) use ($root, $errors): void {
            $descriptor = Arr::make(Arr::is($descriptor) ? $descriptor : []);
            $directory = Str::make((string) $descriptor->get('directory'))->trim('/\\')->val();
            $entrypoint = Str::make((string) $descriptor->get('entrypoint'))->trim('/\\')->val();
            $relative = Str::make($directory)
                ->append($directory === '' ? '' : '/')
                ->append($entrypoint)
                ->replace('\\', '/')
                ->val();

            if ($entrypoint === ''
                || !Str::make($entrypoint)->endsWith('.vibe')
                || Str::make($relative)->split('/')->has('..', true)
                || !Str::startsWith($relative, 'resource/markup/')
                || !FileSystem::fileExists($this->path($root, $relative))
            ) {
                $errors->push($this->problem(
                    'theme_entrypoint',
                    'definition.json',
                    "Theme {$name} must declare an existing .vibe entrypoint under resource/markup."
                ));
            }
        });
    }

    private function declaredClass(array $tokens): string
    {
        $tokens = $this->significantTokens($tokens);
        while (!$tokens->isEmpty()) {
            if (!$this->tokenIs($tokens->shift(), T_CLASS)) {
                continue;
            }

            $name = $tokens->shift();

            return $this->tokenIs($name, T_STRING)
                ? (string) Arr::make($name)->get(1)
                : '';
        }

        return '';
    }

    private function returnsCallableFactory(array $tokens): bool
    {
        $tokens = $this->significantTokens($tokens);
        $depth = 0;
        while (!$tokens->isEmpty()) {
            $token = $tokens->shift();
            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                continue;
            }
            if ($depth !== 0 || !$this->tokenIs($token, T_RETURN)) {
                continue;
            }
            if ($this->tokenIs($tokens->get(0), T_STATIC)) {
                $tokens->shift();
            }

            return $this->tokenIsOneOf($tokens->shift(), [T_FN, T_FUNCTION]);
        }

        return false;
    }

    private function isSupportedMapping(array $tokens): bool
    {
        return $this->isDeclarativeMapping($tokens)
            || $this->isExecutableMapping($tokens);
    }

    private function isDeclarativeMapping(array $tokens): bool
    {
        $tokens = $this->significantTokens($tokens);

        if (!$this->tokenIs($tokens->shift(), T_OPEN_TAG)) {
            return false;
        }
        if ($this->tokenIs($tokens->get(0), T_DECLARE)
            && !$this->consumeStrictTypesDeclaration($tokens)
        ) {
            return false;
        }
        if (!$this->tokenIs($tokens->shift(), T_RETURN)) {
            return false;
        }

        $opening = $tokens->shift();
        if ($opening === '[') {
            $delimiters = Arr::make([']']);
        } elseif ($this->tokenIs($opening, T_ARRAY) && $tokens->shift() === '(') {
            $delimiters = Arr::make([')']);
        } else {
            return false;
        }

        $closing = Arr::make([')' => true, ']' => true, '}' => true]);
        $pairs = Arr::make(['(' => ')', '[' => ']', '{' => '}']);
        $callableBodyDepth = 0;
        $waitingForCallableBody = false;
        $arrowClosureLevel = null;
        $closedCallableExpression = false;
        $previousToken = null;
        while (!$delimiters->isEmpty()) {
            if ($tokens->isEmpty()) {
                return false;
            }

            $token = $tokens->shift();
            if ($closedCallableExpression && $token === '(') {
                return false;
            }
            if ($callableBodyDepth === 0
                && !$waitingForCallableBody
                && $arrowClosureLevel === null
                && $token === '('
                && $this->tokenCanBeInvoked($previousToken)
            ) {
                return false;
            }
            if ($closedCallableExpression && $token !== ')') {
                $closedCallableExpression = false;
            }
            if (Arr::is($token)) {
                $type = Arr::make($token)->get(0);
                if ($type === T_FUNCTION) {
                    $waitingForCallableBody = true;
                } elseif ($type === T_FN) {
                    $arrowClosureLevel = $delimiters->count();
                } elseif ($callableBodyDepth === 0
                    && !$waitingForCallableBody
                    && $arrowClosureLevel === null
                    && !$this->isSafeDeclarativeToken($token, $previousToken, $tokens)
                ) {
                    return false;
                }
                $previousToken = $token;
                continue;
            }
            if ($token === ',' && $arrowClosureLevel === $delimiters->count()) {
                $arrowClosureLevel = null;
            }
            if ($callableBodyDepth === 0
                && !$waitingForCallableBody
                && $arrowClosureLevel === null
                && !Arr::make(['(', ')', '[', ']', ','])->has($token, true)
            ) {
                return false;
            }
            if ($pairs->hasKey($token)) {
                $delimiters->push($pairs->get($token));
                if ($token === '{') {
                    if ($waitingForCallableBody) {
                        $waitingForCallableBody = false;
                        $callableBodyDepth = 1;
                    } elseif ($callableBodyDepth > 0) {
                        $callableBodyDepth++;
                    }
                }
                $previousToken = $token;
                continue;
            }
            if ($closing->hasKey($token)) {
                if ($arrowClosureLevel === $delimiters->count()) {
                    $arrowClosureLevel = null;
                    $closedCallableExpression = true;
                }
                if ($token === '}' && $callableBodyDepth > 0) {
                    $callableBodyDepth--;
                    if ($callableBodyDepth === 0) {
                        $closedCallableExpression = true;
                    }
                }
                if ($token !== $delimiters->pop()) {
                    return false;
                }
            }
            $previousToken = $token;
        }

        if ($waitingForCallableBody
            || $callableBodyDepth !== 0
            || $arrowClosureLevel !== null
            || $tokens->shift() !== ';'
        ) {
            return false;
        }
        if ($this->tokenIs($tokens->get(0), T_CLOSE_TAG)) {
            $tokens->shift();
        }

        return $tokens->isEmpty();
    }

    private function isExecutableMapping(array $tokens): bool
    {
        $tokens = $this->significantTokens($tokens);
        if (!$this->tokenIs($tokens->shift(), T_OPEN_TAG)) {
            return false;
        }
        if ($this->tokenIs($tokens->get(0), T_DECLARE)
            && !$this->consumeStrictTypesDeclaration($tokens)
        ) {
            return false;
        }
        if ($this->tokenIs($tokens->get(0), T_USE)
            && !$this->consumeMappingImport($tokens)
        ) {
            return false;
        }

        $statements = 0;
        while (!$tokens->isEmpty() && !$this->tokenIs($tokens->get(0), T_CLOSE_TAG)) {
            if (!$this->consumeMappingStatement($tokens)) {
                return false;
            }
            $statements++;
        }
        if ($this->tokenIs($tokens->get(0), T_CLOSE_TAG)) {
            $tokens->shift();
        }

        return $statements > 0 && $tokens->isEmpty();
    }

    private function consumeMappingImport(Arr $tokens): bool
    {
        if (!$this->tokenIs($tokens->shift(), T_USE)) {
            return false;
        }

        $name = $tokens->shift();

        return $this->tokenIsOneOf($name, [T_NAME_QUALIFIED, T_STRING])
            && Arr::make($name)->get(1) === 'BlueFission\\Services\\Mapping'
            && $tokens->shift() === ';';
    }

    private function consumeMappingStatement(Arr $tokens): bool
    {
        $class = $tokens->shift();
        if (!$this->tokenIsOneOf($class, [T_STRING, T_NAME_QUALIFIED])
            || !Arr::make(['Mapping', 'BlueFission\\Services\\Mapping'])->has(
                Arr::make($class)->get(1),
                true
            )
            || !$this->tokenIs($tokens->shift(), T_DOUBLE_COLON)
        ) {
            return false;
        }

        $method = $tokens->shift();
        if (!$this->tokenIs($method, T_STRING)
            || !Arr::make(['add', 'crud'])->has(Arr::make($method)->get(1), true)
            || $tokens->shift() !== '('
        ) {
            return false;
        }

        $delimiters = Arr::make([')']);
        $pairs = Arr::make(['(' => ')', '[' => ']', '{' => '}']);
        $closings = Arr::make([')' => true, ']' => true, '}' => true]);
        $allowed = Arr::make([
            T_ARRAY,
            T_CLASS,
            T_CONSTANT_ENCAPSED_STRING,
            T_DNUMBER,
            T_DOUBLE_ARROW,
            T_DOUBLE_COLON,
            T_LNUMBER,
            T_NAME_QUALIFIED,
            T_NS_SEPARATOR,
            T_OBJECT_OPERATOR,
            T_STRING,
        ]);

        while (!$delimiters->isEmpty()) {
            if ($tokens->isEmpty()) {
                return false;
            }
            $token = $tokens->shift();
            if (Arr::is($token)) {
                $type = Arr::make($token)->get(0);
                if (!$allowed->has($type, true)
                    || ($this->isCallableNameToken($token) && $tokens->get(0) === '(')
                ) {
                    return false;
                }
                continue;
            }
            if ($pairs->hasKey($token)) {
                $delimiters->push($pairs->get($token));
                continue;
            }
            if ($closings->hasKey($token) && $token !== $delimiters->pop()) {
                return false;
            }
        }

        while ($this->tokenIs($tokens->get(0), T_OBJECT_OPERATOR)) {
            $tokens->shift();
            if (!$this->tokenIs($tokens->shift(), T_STRING)
                || $tokens->shift() !== '('
            ) {
                return false;
            }
            $delimiters = Arr::make([')']);
            while (!$delimiters->isEmpty()) {
                if ($tokens->isEmpty()) {
                    return false;
                }
                $token = $tokens->shift();
                if (Arr::is($token)) {
                    $type = Arr::make($token)->get(0);
                    if (!$allowed->has($type, true)
                        || ($this->isCallableNameToken($token) && $tokens->get(0) === '(')
                    ) {
                        return false;
                    }
                    continue;
                }
                if ($pairs->hasKey($token)) {
                    $delimiters->push($pairs->get($token));
                    continue;
                }
                if ($closings->hasKey($token) && $token !== $delimiters->pop()) {
                    return false;
                }
            }
        }

        return $tokens->shift() === ';';
    }

    private function significantTokens(array $tokens): Arr
    {
        return Arr::make($tokens)
            ->filter(fn ($token): bool => !$this->tokenIsOneOf(
                $token,
                [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]
            ))
            ->values();
    }

    private function consumeStrictTypesDeclaration(Arr $tokens): bool
    {
        if (!$this->tokenIs($tokens->shift(), T_DECLARE)
            || $tokens->shift() !== '('
        ) {
            return false;
        }

        $name = $tokens->shift();
        $value = null;
        if (!$this->tokenIs($name, T_STRING)
            || Arr::make($name)->get(1) !== 'strict_types'
            || $tokens->shift() !== '='
        ) {
            return false;
        }

        $value = $tokens->shift();

        return $this->tokenIs($value, T_LNUMBER)
            && Arr::make($value)->get(1) === '1'
            && $tokens->shift() === ')'
            && $tokens->shift() === ';';
    }

    private function tokenIs($token, int $type): bool
    {
        return Arr::is($token) && Arr::make($token)->get(0) === $type;
    }

    private function tokenIsOneOf($token, array $types): bool
    {
        return Arr::is($token)
            && Arr::make($types)->has(Arr::make($token)->get(0), true);
    }

    private function isCallableNameToken($token): bool
    {
        return $this->tokenIsOneOf($token, [
            T_NAME_FULLY_QUALIFIED,
            T_NAME_QUALIFIED,
            T_NAME_RELATIVE,
            T_STRING,
        ]);
    }

    private function tokenCanBeInvoked($token): bool
    {
        return $this->tokenIs($token, T_CONSTANT_ENCAPSED_STRING)
            || Arr::make([']', ')', '}'])->has($token, true);
    }

    private function isSafeDeclarativeToken($token, $previousToken, Arr $tokens): bool
    {
        if ($this->tokenIsOneOf($token, [
            T_ARRAY,
            T_CLASS_C,
            T_CONSTANT_ENCAPSED_STRING,
            T_DIR,
            T_DNUMBER,
            T_DOUBLE_ARROW,
            T_FILE,
            T_FUNC_C,
            T_LINE,
            T_LNUMBER,
            T_METHOD_C,
            T_NS_C,
            T_TRAIT_C,
        ])) {
            return true;
        }
        if ($this->tokenIs($token, T_STATIC)) {
            return $this->tokenIsOneOf($tokens->get(0), [T_FN, T_FUNCTION]);
        }
        if ($this->tokenIs($token, T_STRING)) {
            $value = Str::make((string) Arr::make($token)->get(1))->lower()->val();
            if (Arr::make(['false', 'null', 'true'])->has($value, true)) {
                return true;
            }
        }
        if ($this->isCallableNameToken($token)) {
            return $this->tokenIs($tokens->get(0), T_DOUBLE_COLON)
                && $this->tokenIs($tokens->get(1), T_CLASS);
        }
        if ($this->tokenIs($token, T_DOUBLE_COLON)) {
            return $this->tokenIs($tokens->get(0), T_CLASS);
        }

        return $this->tokenIs($token, T_CLASS)
            && $this->tokenIs($previousToken, T_DOUBLE_COLON);
    }

    private function isComposerPackageName($name): bool
    {
        return Str::is($name)
            && Str::make($name)->matches(
                '/^[a-z0-9](?:[_.-]?[a-z0-9]+)*\/[a-z0-9](?:[_.-]?[a-z0-9]+)*$/'
            );
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
        return Arr::make([
            'code' => $code,
            'path' => $path,
            'message' => $message,
        ])->val();
    }

    private function path(string $root, string $relative): string
    {
        return $root . DIRECTORY_SEPARATOR . Str::make($relative)
            ->replace('/', DIRECTORY_SEPARATOR)
            ->val();
    }

    private function relative(string $root, string $path): string
    {
        return Str::make(Str::sub($path, Str::len($root)))
            ->trim('/\\')
            ->replace('\\', '/')
            ->val();
    }
}
