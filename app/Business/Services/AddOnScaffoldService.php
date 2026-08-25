<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Services\Service;
use BlueFission\Str;
use DirectoryIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class AddOnScaffoldService extends Service
{
    private AddOnContractValidator $validator;
    private string $workspace;

    public function __construct(?string $workspace = null, ?AddOnContractValidator $validator = null)
    {
        parent::__construct();

        $workspace = $workspace ?? (defined('APP_ROOT') ? (string) constant('APP_ROOT') : dirname(__DIR__, 3));
        $resolved = realpath($workspace);
        if (!Str::is($resolved) || !FileSystem::directoryExists((string) $resolved)) {
            throw new InvalidArgumentException('Application workspace could not be resolved.');
        }

        $this->workspace = (string) $resolved;
        $this->validator = $validator ?? new AddOnContractValidator();
    }

    public function generate(string $name, string $target, ?string $namespace = null): array
    {
        try {
            $name = Str::make($name)->trim()->lower()->val();
            if (!Str::make($name)->matches('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/')) {
                return $this->failure('name_invalid', 'Add-on name must be a lowercase lifecycle-safe key.');
            }
            if ($name === 'application') {
                return $this->failure('name_reserved', 'The application capability owner is reserved.');
            }

            $className = $this->className($name);
            $namespace = $namespace === null ? "AddOns\\{$className}" : Str::make($namespace)->trim('\\')->val();
            if (!Str::make($namespace)->matches('/^AddOns\\\\[A-Z][A-Za-z0-9]*$/')) {
                return $this->failure('namespace_invalid', 'Namespace must use AddOns\\PackageName.');
            }

            $destination = $this->destination($target);
            if ($this->isInstalledDestination($destination)
                && FileSystem::fileBasename($destination) !== $name
            ) {
                return $this->failure(
                    'destination_name_mismatch',
                    'An installed add-on directory must match its lifecycle name.'
                );
            }
            if (FileSystem::fileExists($destination) || FileSystem::directoryExists($destination)) {
                return $this->failure('destination_exists', 'Destination already exists.');
            }

            $staging = $this->workspace . DIRECTORY_SEPARATOR . '.opus-addon-' . bin2hex(random_bytes(8));
            $files = $this->fileMap($name, $className, $namespace);
            $directories = $this->directories();
            foreach ($directories as $directory) {
                $files[$directory . '/.gitkeep'] = '';
            }

            try {
                $this->ensureDirectory($staging);
                foreach ($directories as $directory) {
                    $this->ensureDirectory($staging . DIRECTORY_SEPARATOR . $this->nativePath($directory));
                }
                foreach ($files as $relative => $contents) {
                    $path = $staging . DIRECTORY_SEPARATOR . $this->nativePath((string) $relative);
                    $this->ensureDirectory(dirname($path));
                    $this->write($path, (string) $contents);
                }

                $validation = Arr::make($this->validator->validate($staging));
                if (!$validation->get('valid')) {
                    return [
                        'created' => false,
                        'path' => null,
                        'files' => [],
                        'errors' => $validation->get('errors'),
                        'warnings' => $validation->get('warnings'),
                    ];
                }

                if (!$this->publish($staging, $destination)) {
                    return $this->failure('destination_exists', 'Destination already exists.');
                }

                return [
                    'created' => true,
                    'path' => $destination,
                    'files' => Arr::make($files)->keys()->val(),
                    'errors' => [],
                    'warnings' => $validation->get('warnings'),
                ];
            } finally {
                if (FileSystem::directoryExists($staging)) {
                    $this->removeDirectory($staging);
                }
            }
        } catch (Throwable $exception) {
            return $this->failure('generation_failed', $exception->getMessage());
        }
    }

    private function fileMap(string $name, string $className, string $namespace): array
    {
        $composer = [
            'name' => 'bluefission/opus-addon-' . Str::replace($name, '_', '-'),
            'description' => "{$className} capability add-on for Opus.",
            'type' => 'opus-addon',
            'license' => 'proprietary',
            'require' => [
                'php' => '^8.2',
                'bluefission/bluecore' => '^0.1.1@alpha',
                'bluefission/develation' => '^1.3.41',
            ],
            'require-dev' => ['phpunit/phpunit' => '^11.5'],
            'autoload' => ['psr-4' => [$namespace . '\\' => 'logic/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
            'extra' => ['installer-name' => $name, 'opus-addon' => true],
            'scripts' => ['test' => 'phpunit --do-not-cache-result'],
            'minimum-stability' => 'alpha',
            'prefer-stable' => true,
        ];
        $definition = [
            'name' => $name,
            'description' => "{$className} capability add-on.",
            'version' => '0.1.0-alpha',
            'namespace' => $namespace,
            'libraries' => [],
            'primary_file' => 'main.php',
            'registration' => [
                'class' => $namespace . '\\Registration\\AddOnRegistration',
                'factory' => 'main.php',
            ],
            'agent_mapping' => 'mapping/agents.php',
            'themes' => [
                'default' => [
                    'directory' => 'resource/markup',
                    'entrypoint' => 'default.vibe',
                ],
            ],
        ];

        $registration = Str::make(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{NAMESPACE}}\Registration;

final class AddOnRegistration
{
    public function contributions(): array
    {
        return [
            'api' => require dirname(__DIR__, 2) . '/mapping/api.php',
            'app' => require dirname(__DIR__, 2) . '/mapping/app.php',
            'console' => require dirname(__DIR__, 2) . '/mapping/console.php',
            'default' => require dirname(__DIR__, 2) . '/mapping/default.php',
            'agents' => require dirname(__DIR__, 2) . '/mapping/agents.php',
            'menus' => require dirname(__DIR__, 2) . '/mapping/menus.php',
        ];
    }
}
PHP
        )->replace('{{NAMESPACE}}', $namespace)->val();
        $main = Str::make(<<<'PHP'
<?php

declare(strict_types=1);

use {{NAMESPACE}}\Registration\AddOnRegistration;

function {{NAME}}_install(): void
{
}

function {{NAME}}_uninstall(): void
{
}

return static fn (): AddOnRegistration => new AddOnRegistration();
PHP
        )
            ->replace('{{NAME}}', $name)
            ->replace('{{NAMESPACE}}', $namespace)
            ->val();

        return [
            'composer.json' => $this->json($composer),
            'definition.json' => $this->json($definition),
            'main.php' => $main . "\n",
            'README.md' => "# {$className}\n\n{$className} is an Opus add-on.\n",
            'logic/Registration/AddOnRegistration.php' => $registration . "\n",
            'mapping/api.php' => $this->mapping(),
            'mapping/app.php' => $this->mapping(),
            'mapping/console.php' => $this->consoleMapping(),
            'mapping/default.php' => $this->mapping(),
            'mapping/agents.php' => $this->agents($name),
            'mapping/menus.php' => $this->menus(),
            'resource/markup/default.vibe' => "<section>\n  <h1>{\$title}</h1>\n</section>\n",
            'phpunit.xml' => $this->phpunitConfiguration(),
            'tests/bootstrap.php' => $this->testBootstrap(),
        ];
    }

    private function mapping(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n";
    }

    private function consoleMapping(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'resources' => [],\n];\n";
    }

    private function agents(string $name): string
    {
        return Str::make(<<<'PHP'
<?php

declare(strict_types=1);

return [
    'version' => 1,
    'owner' => '{{NAME}}',
    'agents' => [
        'addon.{{NAME}}' => [
            'mode' => 'generated',
            'description' => 'Provides package-owned specialist capabilities.',
            'profile' => '{{NAME}}.specialist',
            'tools' => [],
            'imports' => [],
            'exports' => [],
            'permissions' => [],
            'lifecycle' => [
                'states' => ['active'],
            ],
        ],
    ],
];
PHP
        )->replace('{{NAME}}', $name)->append("\n")->val();
    }

    private function menus(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    'items' => [],
    'register' => static function (?object $navigation = null): array {
        if ($navigation === null) {
            return [];
        }

        return [];
    },
];
PHP;
    }

    private function phpunitConfiguration(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.5/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         cacheDirectory=".phpunit.cache"
         colors="true">
    <testsuites>
        <testsuite name="Add-on">
            <directory suffix="Test.php">tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML;
    }

    private function testBootstrap(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

$autoloaders = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
        require $autoloader;
        return;
    }
}

throw new RuntimeException('Composer autoloader could not be resolved.');
PHP;
    }

    private function directories(): array
    {
        return [
            'datasources/generator',
            'datasources/structure',
            'logic/Business/Http',
            'logic/Business/Managers',
            'logic/Business/Prompts',
            'logic/Domain/Models',
            'logic/Domain/Queries',
            'logic/Domain/Repositories',
            'logic/Domain/Values',
            'resource/src',
        ];
    }

    private function destination(string $target): string
    {
        if ($target === '' || Str::make(Str::replace($target, '\\', '/'))->split('/')->has('..', true)) {
            throw new InvalidArgumentException('Destination must not be empty or contain parent traversal.');
        }

        $candidate = $this->isAbsolute($target)
            ? $target
            : $this->workspace . DIRECTORY_SEPARATOR . $target;
        $parent = realpath(dirname($candidate));
        if (!Str::is($parent)) {
            throw new InvalidArgumentException('Destination parent directory must exist.');
        }

        $root = $this->normalize($this->workspace) . '/';
        $normalizedParent = $this->normalize($parent) . '/';
        if (!Str::startsWith($normalizedParent, $root)) {
            throw new InvalidArgumentException('Destination must stay inside the application workspace.');
        }

        return $parent . DIRECTORY_SEPARATOR . FileSystem::fileBasename($candidate);
    }

    private function ensureDirectory(string $directory): void
    {
        if (FileSystem::directoryExists($directory)) {
            return;
        }
        if (!mkdir($directory, 0777, true) && !FileSystem::directoryExists($directory)) {
            throw new RuntimeException("Directory could not be created: {$directory}");
        }
    }

    private function write(string $path, string $contents): void
    {
        $filesystem = new FileSystem([
            'root' => dirname($path),
            'mode' => 'w',
            'filter' => 'file',
            'doNotConfirm' => true,
        ]);
        $filesystem->open((string) FileSystem::fileBasename($path))
            ->contents($contents)
            ->write()
            ->close();

        if (!FileSystem::fileExists($path)) {
            throw new RuntimeException("File could not be written: {$path}");
        }
    }

    private function publish(string $staging, string $destination): bool
    {
        $lockPath = $this->publicationLockPath($destination);
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Generated add-on publication lock could not be opened.');
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return false;
            }
            if (FileSystem::fileExists($destination) || FileSystem::directoryExists($destination)) {
                return false;
            }

            $definition = $staging . DIRECTORY_SEPARATOR . 'definition.json';
            if (!FileSystem::fileExists($definition)) {
                throw new RuntimeException('Generated add-on definition is missing.');
            }

            set_error_handler(static fn (): bool => true);
            try {
                $reserved = mkdir($destination, 0777);
            } finally {
                restore_error_handler();
            }
            if (!$reserved) {
                if (FileSystem::fileExists($destination) || FileSystem::directoryExists($destination)) {
                    return false;
                }

                throw new RuntimeException('Generated add-on destination could not be reserved.');
            }

            try {
                foreach (new DirectoryIterator($staging) as $item) {
                    if ($item->isDot() || $item->getFilename() === 'definition.json') {
                        continue;
                    }
                    if (!rename(
                        $item->getPathname(),
                        $destination . DIRECTORY_SEPARATOR . $item->getFilename()
                    )) {
                        throw new RuntimeException('Generated add-on contents could not be published.');
                    }
                }

                // BlueCore treats definition.json as the discovery marker.
                if (!rename($definition, $destination . DIRECTORY_SEPARATOR . 'definition.json')) {
                    throw new RuntimeException('Generated add-on definition could not be published.');
                }
            } catch (Throwable $exception) {
                $this->removeDirectory($destination);

                throw $exception;
            }

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function publicationLockPath(string $destination): string
    {
        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'opus-addon-publish-'
            . Str::make($this->normalize($destination))->encrypt('sha1')->val()
            . '.lock';
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    private function className(string $name): string
    {
        return Arr::make(Str::make($name)->split('_')->val())
            ->map(fn (string $part): string => Str::make($part)->capitalize()->val())
            ->join('')
            ->val();
    }

    private function nativePath(string $path): string
    {
        return Str::replace($path, '/', DIRECTORY_SEPARATOR);
    }

    private function normalize(string $path): string
    {
        return Str::make(Str::replace($path, '\\', '/'))->trim('/')->val();
    }

    private function isInstalledDestination(string $destination): bool
    {
        $addons = $this->normalize($this->workspace . DIRECTORY_SEPARATOR . 'addons');

        return $this->normalize(dirname($destination)) === $addons;
    }

    private function json(array $value): string
    {
        return Str::make(Arr::make($value)->toJson())
            ->append(PHP_EOL)
            ->val();
    }

    private function isAbsolute(string $path): bool
    {
        return Str::startsWith($path, '/')
            || Str::startsWith($path, '\\\\')
            || Str::make($path)->matches('/^[A-Za-z]:[\\/\\\\]/');
    }

    private function failure(string $code, string $message): array
    {
        return [
            'created' => false,
            'path' => null,
            'files' => [],
            'errors' => [['code' => $code, 'path' => '.', 'message' => $message]],
            'warnings' => [],
        ];
    }
}
