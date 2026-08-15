<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Services\Service;
use BlueFission\Str;
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
            if (!Str::make($name)->matches('/^[a-z][a-z0-9_]*$/')) {
                return $this->failure('name_invalid', 'Add-on name must be a lowercase lifecycle-safe key.');
            }

            $className = $this->className($name);
            $namespace = $namespace === null ? "AddOns\\{$className}" : Str::make($namespace)->trim('\\')->val();
            if (!Str::make($namespace)->matches('/^AddOns\\\\[A-Z][A-Za-z0-9]*$/')) {
                return $this->failure('namespace_invalid', 'Namespace must use AddOns\\PackageName.');
            }

            $destination = $this->destination($target);
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

                if (!rename($staging, $destination)) {
                    throw new RuntimeException('Generated add-on could not be published.');
                }

                return [
                    'created' => true,
                    'path' => $destination,
                    'files' => array_keys($files),
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
        ];

        $registration = str_replace(
            ['{{NAMESPACE}}'],
            [$namespace],
            <<<'PHP'
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
            'menus' => require dirname(__DIR__, 2) . '/mapping/menus.php',
        ];
    }
}
PHP
        );
        $main = str_replace(
            ['{{NAME}}', '{{NAMESPACE}}'],
            [$name, $namespace],
            <<<'PHP'
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
        );

        return [
            'composer.json' => json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            'definition.json' => json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            'main.php' => $main . "\n",
            'README.md' => "# {$className}\n\n{$className} is an Opus add-on.\n",
            'logic/Registration/AddOnRegistration.php' => $registration . "\n",
            'mapping/api.php' => $this->mapping(),
            'mapping/app.php' => $this->mapping(),
            'mapping/console.php' => $this->mapping(),
            'mapping/default.php' => $this->mapping(),
            'mapping/menus.php' => $this->menus(),
            'resource/markup/default.vibe' => "<section>\n  <h1>{\$title}</h1>\n</section>\n",
            'phpunit.xml' => $this->phpunitConfiguration(),
            'tests/bootstrap.php' => "<?php\n\ndeclare(strict_types=1);\n\nrequire dirname(__DIR__) . '/vendor/autoload.php';\n",
        ];
    }

    private function mapping(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n";
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
