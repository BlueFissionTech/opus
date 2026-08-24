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
    private AgentCapabilityMapValidator $agentMapValidator;
    private DeclarativeArrayParser $declarativeParser;

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

    public function __construct(
        ?VibeSyntaxValidator $vibeValidator = null,
        ?AgentCapabilityMapValidator $agentMapValidator = null,
        ?DeclarativeArrayParser $declarativeParser = null
    )
    {
        parent::__construct();

        $this->vibeValidator = $vibeValidator ?? new VibeSyntaxValidator();
        $this->declarativeParser = $declarativeParser ?? new DeclarativeArrayParser();
        $this->agentMapValidator = $agentMapValidator
            ?? new AgentCapabilityMapValidator($this->declarativeParser);
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
        $this->validateAgentMap($root, $definition, $errors, $warnings);
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

    private function validateAgentMap(string $root, array $definition, Arr $errors, Arr $warnings): void
    {
        $definition = Arr::make($definition);
        $agentPath = $this->path($root, 'mapping/agents.php');
        $declared = $definition->get('agent_mapping');

        if (!Str::is($declared) && !FileSystem::fileExists($agentPath)) {
            $warnings->push($this->problem(
                'agent_mapping_missing',
                'mapping/agents.php',
                'No agent map is declared; agent command access remains disabled.'
            ));
            return;
        }
        if ($declared !== 'mapping/agents.php') {
            $errors->push($this->problem(
                'agent_mapping_manifest',
                'definition.json',
                'Agent mapping must reference mapping/agents.php.'
            ));
            return;
        }

        $consolePath = $this->path($root, 'mapping/console.php');
        if (!FileSystem::fileExists($agentPath)) {
            $errors->push($this->problem(
                'agent_mapping_file',
                'mapping/agents.php',
                'The declared agent mapping file is missing.'
            ));
            return;
        }
        if (!FileSystem::fileExists($consolePath)) {
            return;
        }

        $knownTools = $this->knownToolsFromConsoleFile($consolePath);
        $validation = Arr::make($this->agentMapValidator->validateFile(
            $agentPath,
            $knownTools,
            Str::is($definition->get('name')) ? (string) $definition->get('name') : null
        ));
        Arr::make((array) $validation->get('errors'))->each(function ($problem) use ($errors): void {
            $problem = Arr::make((array) $problem);
            $errors->push($this->problem(
                (string) $problem->get('code'),
                'mapping/agents.php',
                (string) $problem->get('message')
            ));
        });
    }

    public function knownToolsFromConsoleFile(string $path): array
    {
        $console = Arr::make($this->declarativeParser->parseFile($path));
        if ($console->get('valid')) {
            return $this->agentMapValidator->knownToolsFromConsole((array) $console->get('value'));
        }

        $source = FileSystem::fileContents($path);
        if (!Str::is($source)) {
            return [];
        }

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError) {
            return [];
        }
        if (!$this->isExecutableMapping($tokens)) {
            return [];
        }

        return $this->knownToolsFromExecutableMapping($tokens);
    }

    private function knownToolsFromExecutableMapping(array $tokens): array
    {
        $tokens = $this->significantTokens($tokens)->toArray();
        $tools = Arr::make([]);
        $count = Arr::make($tokens)->count();

        for ($index = 0; $index < $count - 3; $index++) {
            $class = Arr::make(Arr::is($tokens[$index]) ? $tokens[$index] : []);
            if (!Arr::make([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])
                ->has($class->get(0), true)
                || !$this->tokenIs($tokens[$index + 1], T_DOUBLE_COLON)
                || !$this->tokenIs($tokens[$index + 2], T_STRING)
                || $tokens[$index + 3] !== '('
            ) {
                continue;
            }

            $method = (string) Arr::make($tokens[$index + 2])->get(1);
            if (!Arr::make(['add', 'crud'])->has($method, true)) {
                continue;
            }

            [$arguments, $closing] = $this->mappingArguments($tokens, $index + 3);
            if ($closing === null) {
                return [];
            }

            if ($method === 'add') {
                $name = $this->literalString((array) Arr::make($arguments)->get(2));
                $path = $this->literalString((array) Arr::make($arguments)->get(0));
                $tool = Str::is($name) ? $this->normalizeTool((string) $name) : null;
                if ($tool === null && Str::is($path)) {
                    $tool = $this->normalizeTool((string) $path);
                }
                if ($tool !== null) {
                    $tools->push($tool);
                }
            } else {
                $root = $this->literalString((array) Arr::make($arguments)->get(0));
                $package = $this->literalString((array) Arr::make($arguments)->get(1));
                if (Str::is($root) && Str::is($package)) {
                    $resource = Str::make((string) $root)
                        ->append('/')
                        ->append((string) $package)
                        ->trim('/\\')
                        ->replace('/', '_')
                        ->replace('\\', '_')
                        ->replace('-', '_')
                        ->replace('.', '_')
                        ->lower()
                        ->val();
                    Arr::make(['list', 'get', 'save', 'update', 'delete'])
                        ->each(function (string $action) use ($resource, $tools): void {
                            $tool = $this->normalizeTool($resource . '.' . $action);
                            if ($tool !== null) {
                                $tools->push($tool);
                            }
                        });
                }
            }

            $index = $closing;
        }

        return $tools->unique()->sort()->toArray();
    }

    private function mappingArguments(array $tokens, int $opening): array
    {
        $arguments = Arr::make([]);
        $current = Arr::make([]);
        $pairs = Arr::make(['(' => ')', '[' => ']', '{' => '}']);
        $closing = Arr::make([')' => true, ']' => true, '}' => true]);
        $delimiters = Arr::make([')']);
        $count = Arr::make($tokens)->count();

        for ($index = $opening + 1; $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token === ',' && $delimiters->count() === 1) {
                $arguments->push($current->toArray());
                $current = Arr::make([]);
                continue;
            }
            if (Str::is($token) && $pairs->hasKey($token)) {
                $delimiters->push($pairs->get($token));
                $current->push($token);
                continue;
            }
            if (Str::is($token) && $closing->hasKey($token)) {
                if ($token !== $delimiters->pop()) {
                    return [[], null];
                }
                if ($delimiters->isEmpty()) {
                    $arguments->push($current->toArray());
                    return [$arguments->toArray(), $index];
                }
                $current->push($token);
                continue;
            }
            $current->push($token);
        }

        return [[], null];
    }

    private function literalString(array $tokens): ?string
    {
        $tokens = Arr::make($tokens);
        if ($tokens->count() !== 1 || !$this->tokenIs($tokens->get(0), T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }

        $literal = (string) Arr::make($tokens->get(0))->get(1);
        $quote = Str::sub($literal, 0, 1);
        $value = Str::sub($literal, 1, -1);

        return $quote === "'"
            ? Str::make($value)->replace('\\\\', '\\')->replace("\\'", "'")->val()
            : stripcslashes($value);
    }

    private function normalizeTool(string $candidate): ?string
    {
        $tool = Str::make($candidate)
            ->trim()
            ->trim('/\\')
            ->replace('/', '.')
            ->replace('\\', '.')
            ->lower()
            ->val();

        return Str::make($tool)->matches('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$/')
            ? $tool
            : null;
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
                    $declared = $this->registrationClassDeclaration($tokens);
                    if ($declared->get('name') !== $class
                        || !$declared->get('default_constructible')
                    ) {
                        $errors->push($this->problem(
                            'registration_class',
                            $classPath,
                            'Registration file must declare a concrete, publicly default-constructible configured class.'
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

        if (!$this->returnsCallableFactory($tokens, (string) $class)) {
            $errors->push($this->problem(
                'registration_factory',
                (string) $factory,
                'Registration factory must return a callable that constructs the configured class.'
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

    private function registrationClassDeclaration(array $tokens): Arr
    {
        $tokens = $this->significantTokens($tokens);
        $structuralDepth = 0;
        $namespaceDepth = 0;
        $namespacePending = false;
        $namespace = Str::make('');
        $pendingNamespace = Str::make('');
        $namespaceTokens = Arr::make([T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR]);
        $interpolationDepth = 0;
        $parenthesisDepth = 0;
        $alternativeScopeDepth = 0;
        $alternativeScopePending = false;
        $abstractClassPending = false;
        $alternativeOpenTokens = Arr::make([
            T_DECLARE,
            T_FOR,
            T_FOREACH,
            T_IF,
            T_SWITCH,
            T_WHILE,
        ]);
        $alternativeEndTokens = Arr::make([
            T_ENDDECLARE,
            T_ENDFOR,
            T_ENDFOREACH,
            T_ENDIF,
            T_ENDSWITCH,
            T_ENDWHILE,
        ]);
        $previousToken = null;
        while (!$tokens->isEmpty()) {
            $token = $tokens->shift();
            if (Arr::is($token)) {
                $type = Arr::make($token)->get(0);
                if ($type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $interpolationDepth++;
                    $previousToken = $token;
                    continue;
                }
                if ($type === T_NAMESPACE) {
                    $namespacePending = true;
                    $pendingNamespace = Str::make('');
                    $previousToken = $token;
                    continue;
                }
                if ($namespacePending && $namespaceTokens->has($type, true)) {
                    $pendingNamespace->append((string) Arr::make($token)->get(1));
                    $previousToken = $token;
                    continue;
                }
                if ($alternativeOpenTokens->has($type, true)) {
                    $alternativeScopePending = true;
                    $previousToken = $token;
                    continue;
                }
                if ($alternativeEndTokens->has($type, true)) {
                    $alternativeScopeDepth--;
                    $previousToken = $token;
                    continue;
                }
                if ($type === T_ABSTRACT
                    && $structuralDepth === $namespaceDepth
                    && $alternativeScopeDepth === 0
                    && !$alternativeScopePending
                ) {
                    $abstractClassPending = true;
                    $previousToken = $token;
                    continue;
                }
                if ($type !== T_CLASS) {
                    $previousToken = $token;
                    continue;
                }

                $validScope = $structuralDepth === $namespaceDepth
                    && $alternativeScopeDepth === 0
                    && !$alternativeScopePending
                    && !$this->tokenIs($previousToken, T_NEW);
                if (!$validScope || $abstractClassPending) {
                    $abstractClassPending = false;
                    $previousToken = $token;
                    continue;
                }

                $name = $tokens->shift();

                if (!$this->tokenIs($name, T_STRING)) {
                    return Arr::make([]);
                }

                return Arr::make([
                    'name' => Str::make($namespace->val())
                        ->append($namespace->isEmpty() ? '' : '\\')
                        ->append((string) Arr::make($name)->get(1))
                        ->val(),
                    'default_constructible' => $this->classHasPublicDefaultConstructor($tokens),
                ]);
            }
            if ($token === '}' && $interpolationDepth > 0) {
                $interpolationDepth--;
                $previousToken = $token;
                continue;
            }
            if ($namespacePending && ($token === ';' || $token === '{')) {
                $namespace = $pendingNamespace;
                if ($token === '{') {
                    $structuralDepth++;
                }
                $namespaceDepth = $structuralDepth;
                $namespacePending = false;
                $previousToken = $token;
                continue;
            }
            if ($token === '(') {
                $parenthesisDepth++;
            } elseif ($token === ')') {
                $parenthesisDepth--;
            }
            if ($alternativeScopePending && $parenthesisDepth === 0) {
                if ($token === ':') {
                    $alternativeScopeDepth++;
                    $alternativeScopePending = false;
                    $previousToken = $token;
                    continue;
                }
                if ($token === '{' || $token === ';') {
                    $alternativeScopePending = false;
                }
            }
            if ($token === '{') {
                $structuralDepth++;
            } elseif ($token === '}') {
                $structuralDepth--;
            }
            $previousToken = $token;
        }

        return Arr::make([]);
    }

    private function classHasPublicDefaultConstructor(Arr $tokens): bool
    {
        $inheritsConstructor = false;
        while (!$tokens->isEmpty()) {
            $token = $tokens->shift();
            if ($this->tokenIs($token, T_EXTENDS)) {
                $inheritsConstructor = true;
            }
            if ($token === '{') {
                break;
            }
        }
        if ($tokens->isEmpty()) {
            return false;
        }

        $bodyDepth = 1;
        $visibility = T_PUBLIC;
        while (!$tokens->isEmpty() && $bodyDepth > 0) {
            $token = $tokens->shift();
            if ($token === '{') {
                $bodyDepth++;
                continue;
            }
            if ($token === '}') {
                $bodyDepth--;
                if ($bodyDepth === 1) {
                    $visibility = T_PUBLIC;
                }
                continue;
            }
            if ($bodyDepth !== 1) {
                continue;
            }
            if ($token === ';') {
                $visibility = T_PUBLIC;
                continue;
            }
            if ($this->tokenIsOneOf($token, [T_PRIVATE, T_PROTECTED, T_PUBLIC])) {
                $visibility = (int) Arr::make($token)->get(0);
                continue;
            }
            if (!$this->tokenIs($token, T_FUNCTION)) {
                continue;
            }

            $name = $tokens->shift();
            if ($name === '&'
                || $this->tokenIsOneOf($name, [
                    T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
                    T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
                ])
            ) {
                $name = $tokens->shift();
            }
            if (!$this->tokenIs($name, T_STRING)
                || Str::make((string) Arr::make($name)->get(1))->lower()->val() !== '__construct'
            ) {
                continue;
            }

            return $visibility === T_PUBLIC
                && $this->constructorAllowsNoArguments($tokens);
        }

        return !$inheritsConstructor;
    }

    private function constructorAllowsNoArguments(Arr $tokens): bool
    {
        while (!$tokens->isEmpty() && $tokens->shift() !== '(') {
        }
        if ($tokens->isEmpty()) {
            return false;
        }

        $depth = 1;
        $hasParameter = false;
        $optional = false;
        while (!$tokens->isEmpty()) {
            $token = $tokens->shift();
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
                continue;
            }
            if ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
                if ($depth === 0) {
                    return !$hasParameter || $optional;
                }
                continue;
            }
            if ($depth !== 1) {
                continue;
            }
            if ($token === ',') {
                if ($hasParameter && !$optional) {
                    return false;
                }
                $hasParameter = false;
                $optional = false;
                continue;
            }
            if ($token === '=' || $this->tokenIs($token, T_ELLIPSIS)) {
                $optional = true;
            } elseif ($this->tokenIs($token, T_VARIABLE)) {
                $hasParameter = true;
            }
        }

        return false;
    }

    private function returnsCallableFactory(array $tokens, string $class): bool
    {
        $tokens = $this->significantTokens($tokens);
        $importedClassName = $this->factoryClassImportName($tokens, $class);
        if (!$this->tokenIs($tokens->shift(), T_OPEN_TAG)) {
            return false;
        }
        if ($this->tokenIs($tokens->get(0), T_DECLARE)
            && !$this->consumeStrictTypesDeclaration($tokens)
        ) {
            return false;
        }
        while ($this->tokenIs($tokens->get(0), T_USE)) {
            if (!$this->consumeFactoryImport($tokens)) {
                return false;
            }
        }
        while ($this->tokenIs($tokens->get(0), T_FUNCTION)) {
            if (!$this->consumeDeferredNamedFunction($tokens)) {
                return false;
            }
        }
        if (!$this->tokenIs($tokens->shift(), T_RETURN)) {
            return false;
        }
        if ($this->tokenIs($tokens->get(0), T_STATIC)) {
            $tokens->shift();
        }

        $callable = $tokens->shift();
        if ($this->tokenIs($callable, T_FN)) {
            return $this->consumeArrowFactory($tokens, $class, $importedClassName);
        }
        if ($this->tokenIs($callable, T_FUNCTION)) {
            return $this->consumeTraditionalFactory($tokens, $class, $importedClassName);
        }

        return false;
    }

    private function consumeFactoryImport(Arr $tokens): bool
    {
        if (!$this->tokenIs($tokens->shift(), T_USE)) {
            return false;
        }
        if ($this->tokenIs($tokens->get(0), T_FUNCTION)
            || $this->tokenIs($tokens->get(0), T_CONST)
        ) {
            $tokens->shift();
        }
        if (!$this->isCallableNameToken($tokens->shift())) {
            return false;
        }
        if ($this->tokenIs($tokens->get(0), T_AS)) {
            $tokens->shift();
            if (!$this->tokenIs($tokens->shift(), T_STRING)) {
                return false;
            }
        }

        return $tokens->shift() === ';';
    }

    private function consumeDeferredNamedFunction(Arr $tokens): bool
    {
        if (!$this->tokenIs($tokens->shift(), T_FUNCTION)
            || !$this->tokenIs($tokens->shift(), T_STRING)
        ) {
            return false;
        }

        $signatureDepth = 0;
        while (!$tokens->isEmpty()) {
            $token = $tokens->shift();
            if ($token === '(' || $token === '[') {
                $signatureDepth++;
                continue;
            }
            if ($token === ')' || $token === ']') {
                $signatureDepth--;
                if ($signatureDepth < 0) {
                    return false;
                }
                continue;
            }
            if ($token === '{' && $signatureDepth === 0) {
                break;
            }
            if ($token === ';' && $signatureDepth === 0) {
                return false;
            }
        }

        $bodyDepth = 1;
        $interpolationDepth = 0;
        while (!$tokens->isEmpty() && $bodyDepth > 0) {
            $token = $tokens->shift();
            if (Arr::is($token)) {
                $type = Arr::make($token)->get(0);
                if ($type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $interpolationDepth++;
                }
                continue;
            }
            if ($token === '}' && $interpolationDepth > 0) {
                $interpolationDepth--;
                continue;
            }
            if ($token === '{') {
                $bodyDepth++;
            } elseif ($token === '}') {
                $bodyDepth--;
            }
        }

        return $signatureDepth === 0
            && $bodyDepth === 0
            && $interpolationDepth === 0;
    }

    private function factoryClassImportName(Arr $tokens, string $class): ?string
    {
        $structuralDepth = 0;
        $interpolationDepth = 0;
        foreach ($tokens as $index => $token) {
            if (Arr::is($token)) {
                $type = Arr::make($token)->get(0);
                if ($type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $interpolationDepth++;
                    continue;
                }
            }
            if ($token === '}' && $interpolationDepth > 0) {
                $interpolationDepth--;
                continue;
            }
            if ($token === '{') {
                $structuralDepth++;
                continue;
            }
            if ($token === '}') {
                $structuralDepth--;
                continue;
            }
            if ($structuralDepth !== 0) {
                continue;
            }
            if ($this->tokenIs($token, T_RETURN)) {
                break;
            }
            if ($this->tokenIs($token, T_USE)
                && $this->factoryClassTokenIs($tokens->get($index + 1), $class, null)
            ) {
                $next = $tokens->get($index + 2);
                if ($next === ';') {
                    return (string) Str::make($class)->split('\\')->pop();
                }
                if ($this->tokenIs($next, T_AS)
                    && $this->tokenIs($tokens->get($index + 3), T_STRING)
                    && $tokens->get($index + 4) === ';'
                ) {
                    return (string) Arr::make($tokens->get($index + 3))->get(1);
                }
            }
        }

        return null;
    }

    private function consumeArrowFactory(Arr $tokens, string $class, ?string $importedClassName): bool
    {
        return $tokens->shift() === '('
            && $tokens->shift() === ')'
            && $tokens->shift() === ':'
            && $this->factoryClassTokenIs($tokens->shift(), $class, $importedClassName)
            && $this->tokenIs($tokens->shift(), T_DOUBLE_ARROW)
            && $this->tokenIs($tokens->shift(), T_NEW)
            && $this->factoryClassTokenIs($tokens->shift(), $class, $importedClassName)
            && $tokens->shift() === '('
            && $tokens->shift() === ')'
            && $tokens->shift() === ';'
            && $this->factoryRemainderIsEmpty($tokens);
    }

    private function consumeTraditionalFactory(Arr $tokens, string $class, ?string $importedClassName): bool
    {
        return $tokens->shift() === '('
            && $tokens->shift() === ')'
            && $tokens->shift() === ':'
            && $this->factoryClassTokenIs($tokens->shift(), $class, $importedClassName)
            && $tokens->shift() === '{'
            && $this->tokenIs($tokens->shift(), T_RETURN)
            && $this->tokenIs($tokens->shift(), T_NEW)
            && $this->factoryClassTokenIs($tokens->shift(), $class, $importedClassName)
            && $tokens->shift() === '('
            && $tokens->shift() === ')'
            && $tokens->shift() === ';'
            && $tokens->shift() === '}'
            && $tokens->shift() === ';'
            && $this->factoryRemainderIsEmpty($tokens);
    }

    private function factoryClassTokenIs($token, string $class, ?string $importedClassName): bool
    {
        if (!$this->isCallableNameToken($token)) {
            return false;
        }

        $name = Str::make((string) Arr::make($token)->get(1))->trim('\\')->val();
        return $name === $class || (Str::is($importedClassName) && $name === $importedClassName);
    }

    private function factoryRemainderIsEmpty(Arr $tokens): bool
    {
        if ($this->tokenIs($tokens->get(0), T_CLOSE_TAG)) {
            $tokens->shift();
        }

        return $tokens->isEmpty();
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
            $closingToken = ']';
        } elseif ($this->tokenIs($opening, T_ARRAY) && $tokens->shift() === '(') {
            $closingToken = ')';
        } else {
            return false;
        }

        if (!$this->consumeSafeValueSequence($tokens, $closingToken)
            || $tokens->shift() !== ';'
        ) {
            return false;
        }
        if ($this->tokenIs($tokens->get(0), T_CLOSE_TAG)) {
            $tokens->shift();
        }

        return $tokens->isEmpty();
    }

    private function consumeSafeValueSequence(Arr $tokens, string $closingToken): bool
    {
        $delimiters = Arr::make([$closingToken]);
        $closing = Arr::make([')' => true, ']' => true, '}' => true]);
        $pairs = Arr::make(['(' => ')', '[' => ']', '{' => '}']);
        $callableBodyDepth = 0;
        $pendingCallableBodies = 0;
        $arrowClosureLevels = Arr::make([]);
        $yieldLevels = Arr::make([]);
        $interpolationDepth = 0;
        $closedCallableExpression = false;
        $previousToken = null;
        while (!$delimiters->isEmpty()) {
            if ($tokens->isEmpty()) {
                return false;
            }

            $token = $tokens->shift();
            if ($closedCallableExpression && ($token === '(' || $token === '[')) {
                return false;
            }
            if ($callableBodyDepth === 0
                && $pendingCallableBodies === 0
                && $arrowClosureLevels->isEmpty()
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
                if (($type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES)
                    && ($callableBodyDepth > 0 || $arrowClosureLevels->isNotEmpty())
                ) {
                    $interpolationDepth++;
                } elseif ($type === T_FUNCTION) {
                    $pendingCallableBodies++;
                } elseif ($type === T_FN && $callableBodyDepth === 0) {
                    $arrowClosureLevels->push([
                        'level' => $delimiters->count(),
                        'started' => false,
                    ]);
                } elseif ($type === T_YIELD && $arrowClosureLevels->isNotEmpty()) {
                    $yieldLevels->push($delimiters->count());
                } elseif ($type === T_DOUBLE_ARROW && $arrowClosureLevels->isNotEmpty()) {
                    $index = $arrowClosureLevels->count() - 1;
                    $arrow = Arr::make($arrowClosureLevels->get($index));
                    if (!$arrow->get('started')
                        && $arrow->get('level') === $delimiters->count()
                    ) {
                        $arrow->set('started', true);
                        $arrowClosureLevels->set($index, $arrow->val());
                        $previousToken = $token;
                        continue;
                    }
                    if ($yieldLevels->isNotEmpty()
                        && $yieldLevels->get($yieldLevels->count() - 1) === $delimiters->count()
                    ) {
                        $yieldLevels->pop();
                        $previousToken = $token;
                        continue;
                    }
                    while ($arrow->get('started')
                        && $arrowClosureLevels->isNotEmpty()
                        && Arr::make($arrowClosureLevels->get($arrowClosureLevels->count() - 1))
                            ->get('level') === $delimiters->count()
                    ) {
                        $arrowClosureLevels->pop();
                    }
                }
                if ($callableBodyDepth === 0
                    && $pendingCallableBodies === 0
                    && $arrowClosureLevels->isEmpty()
                    && !$this->isSafeDeclarativeToken($token, $previousToken, $tokens)
                ) {
                    return false;
                }
                $previousToken = $token;
                continue;
            }
            if ($token === '}' && $interpolationDepth > 0) {
                $interpolationDepth--;
                $previousToken = $token;
                continue;
            }
            if ($token === ','
                && $arrowClosureLevels->isNotEmpty()
                && Arr::make($arrowClosureLevels->get($arrowClosureLevels->count() - 1))
                    ->get('level') === $delimiters->count()
            ) {
                do {
                    $arrowClosureLevels->pop();
                } while ($arrowClosureLevels->isNotEmpty()
                    && Arr::make($arrowClosureLevels->get($arrowClosureLevels->count() - 1))
                        ->get('level') === $delimiters->count()
                );
            }
            if (($token === ',' || $closing->hasKey($token))
                && $yieldLevels->isNotEmpty()
                && $yieldLevels->get($yieldLevels->count() - 1) === $delimiters->count()
            ) {
                $yieldLevels->pop();
            }
            if ($callableBodyDepth === 0
                && $pendingCallableBodies === 0
                && $arrowClosureLevels->isEmpty()
                && !Arr::make(['(', ')', '[', ']', ','])->has($token, true)
            ) {
                return false;
            }
            if ($pairs->hasKey($token)) {
                $delimiters->push($pairs->get($token));
                if ($token === '{') {
                    if ($pendingCallableBodies > 0) {
                        $pendingCallableBodies--;
                        $callableBodyDepth++;
                    } elseif ($callableBodyDepth > 0) {
                        $callableBodyDepth++;
                    }
                }
                $previousToken = $token;
                continue;
            }
            if ($closing->hasKey($token)) {
                $closedArrow = false;
                while ($arrowClosureLevels->isNotEmpty()
                    && Arr::make($arrowClosureLevels->get($arrowClosureLevels->count() - 1))
                        ->get('level') === $delimiters->count()
                ) {
                    $arrowClosureLevels->pop();
                    $closedArrow = true;
                }
                if ($closedArrow) {
                    $closedCallableExpression = $arrowClosureLevels->isEmpty();
                }
                if ($token === '}' && $callableBodyDepth > 0) {
                    $callableBodyDepth--;
                    if ($callableBodyDepth === 0 && $arrowClosureLevels->isEmpty()) {
                        $closedCallableExpression = true;
                    }
                }
                if ($token !== $delimiters->pop()) {
                    return false;
                }
            }
            $previousToken = $token;
        }

        return $pendingCallableBodies === 0
            && $callableBodyDepth === 0
            && $arrowClosureLevels->isEmpty()
            && $yieldLevels->isEmpty()
            && $interpolationDepth === 0;
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
        $hasMappingImport = false;
        if ($this->tokenIs($tokens->get(0), T_USE)) {
            if (!$this->consumeMappingImport($tokens)) {
                return false;
            }
            $hasMappingImport = true;
        }

        $statements = 0;
        while (!$tokens->isEmpty() && !$this->tokenIs($tokens->get(0), T_CLOSE_TAG)) {
            if (!$this->consumeMappingStatement($tokens, $hasMappingImport)) {
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

    private function consumeMappingStatement(Arr $tokens, bool $hasMappingImport): bool
    {
        $class = $tokens->shift();
        if (!$this->tokenIsOneOf($class, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])) {
            return false;
        }
        $className = Str::make((string) Arr::make($class)->get(1))->trim('\\')->val();
        if (($className === 'Mapping' && !$hasMappingImport)
            || !Arr::make(['Mapping', 'BlueFission\\Services\\Mapping'])->has($className, true)
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

        if (!$this->consumeSafeMappingArguments($tokens)) {
            return false;
        }

        while ($this->tokenIs($tokens->get(0), T_OBJECT_OPERATOR)) {
            $tokens->shift();
            if (!$this->tokenIs($tokens->shift(), T_STRING)
                || $tokens->shift() !== '('
            ) {
                return false;
            }
            if (!$this->consumeSafeMappingArguments($tokens)) {
                return false;
            }
        }

        return $tokens->shift() === ';';
    }

    private function consumeSafeMappingArguments(Arr $tokens): bool
    {
        return $this->consumeSafeValueSequence($tokens, ')');
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
            if (Arr::make(['false', 'null', 'true'])->has($value, true)
                && $tokens->get(0) !== '('
            ) {
                return true;
            }
        }
        if ($this->tokenTextIs($token, 'class')
            && $this->tokenIs($previousToken, T_DOUBLE_COLON)
            && $tokens->get(0) !== '('
        ) {
            return true;
        }
        if ($this->isCallableNameToken($token)) {
            return $this->tokenIs($tokens->get(0), T_DOUBLE_COLON)
                && $this->tokenTextIs($tokens->get(1), 'class');
        }
        if ($this->tokenIs($token, T_DOUBLE_COLON)) {
            return $this->tokenTextIs($tokens->get(0), 'class');
        }

        return false;
    }

    private function tokenTextIs($token, string $text): bool
    {
        return Arr::is($token)
            && Str::make((string) Arr::make($token)->get(1))->lower()->val() === $text;
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
