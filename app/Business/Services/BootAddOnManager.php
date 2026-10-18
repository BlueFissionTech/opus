<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Business\Presentation\PackageTheme;
use BlueFission\Arr;
use BlueFission\BlueCore\Business\Managers\AddOnManager;
use BlueFission\BlueCore\Domain\AddOn\AddOn;
use BlueFission\BlueCore\Engine;
use BlueFission\Data\FileSystem;
use BlueFission\Net\HTTP;
use BlueFission\Str;
use ReflectionClass;
use RuntimeException;
use Throwable;
use WeakMap;

/** Consumes canonical registration factories on the engine's boot event. */
class BootAddOnManager extends AddOnManager
{
    private static ?WeakMap $boots = null;

    protected function loadAddOn(AddOn $addOn)
    {
        $root = (string) $addOn->path;
        $definition = $this->definition($root);
        if (!Arr::hasKey($definition, 'registration')) {
            // Historical imperative entrypoints retain their existing contract.
            parent::loadAddOn($addOn);
            return;
        }

        $engine = Engine::instance();
        if (!$engine instanceof Engine) {
            throw new RuntimeException('addon_boot_engine_unavailable');
        }
        $records = $this->records($engine);
        $name = (string) $addOn->name;
        $identity = $this->identity($addOn);
        if ($records->hasKey($name)) {
            if ($records->get($name)->get('identity') !== $identity) {
                throw new RuntimeException('addon_boot_identity_conflict');
            }
            return;
        }

        $record = Arr::make(['identity' => $identity, 'state' => 'pending', 'contributions' => []]);
        $records->set($name, $record);
        $engine->behavior('OnAppLoaded', function () use ($engine, $record, $root, $name): void {
            $state = $record->get('state');
            if ($state === 'failed') {
                throw new RuntimeException('addon_boot_registration_failed');
            }
            if ($state !== 'pending') {
                return;
            }
            // Mark before executing package code, including reentrant dispatch.
            $record->set('state', 'registering');
            try {
                if (!$this->stillActive($record->get('identity'))) {
                    $record->set('state', 'skipped');
                    return;
                }
                $this->registerContributions($engine, $record, $root, $name);
                $record->set('state', 'registered');
            } catch (Throwable) {
                $record->set('state', 'failed');
                $record->set('contributions', []);
                // Package exceptions may contain credentials or private payloads.
                throw new RuntimeException('addon_boot_registration_failed');
            }
        });
    }

    /** Data catalogs remain subject to their owning consumer's permission checks. */
    public function bootContributions(string $name): array
    {
        $record = $this->records(Engine::instance())->get($name);
        return $record instanceof Arr && $record->get('state') === 'registered'
            ? $record->get('contributions') : [];
    }

    public function bootState(string $name): string
    {
        $record = $this->records(Engine::instance())->get($name);
        return $record instanceof Arr ? (string) $record->get('state') : 'unloaded';
    }

    private function registerContributions(Engine $engine, Arr $record, string $root, string $name): void
    {
        if (!(new AddOnContractValidator())->validate($root)['valid']) {
            throw new RuntimeException('addon_contract_invalid');
        }
        $definition = Arr::make($this->definition($root));
        $identity = Arr::make($record->get('identity'));
        foreach (['name', 'namespace', 'primary_file', 'version'] as $field) {
            if ($definition->get($field) !== $identity->get($field)) {
                throw new RuntimeException('addon_identity_mismatch');
            }
        }
        $registration = Arr::make($definition->get('registration'));
        $class = $registration->get('class');
        $relative = Str::make(Str::sub($class, Str::len($definition->get('namespace') . '\\')))
            ->replace('\\', '/')->append('.php')->val();
        $classFile = $root . '/logic/' . $relative;
        if (class_exists($class, false)
            && realpath((string) (new ReflectionClass($class))->getFileName()) !== realpath($classFile)
        ) {
            throw new RuntimeException('addon_registration_class_conflict');
        }
        require_once $classFile;
        $factory = require $root . '/' . $registration->get('factory');
        $provider = $factory();
        if (!$provider instanceof $class || !is_callable([$provider, 'contributions'])) {
            throw new RuntimeException('addon_registration_invalid');
        }
        $contributions = $provider->contributions();
        if (!Arr::is($contributions)) {
            throw new RuntimeException('addon_contributions_invalid');
        }
        foreach ($definition->get('themes') as $themeName => $theme) {
            $engine->addTheme(new PackageTheme($name . '/' . $themeName, $root . '/' . $theme['directory']));
        }
        $record->set('contributions', $contributions);
    }

    private function definition(string $root): array
    {
        $path = $root . '/definition.json';
        if (!FileSystem::fileExists($path)) {
            return [];
        }
        $definition = HTTP::jsonDecode((string) FileSystem::fileContents($path), true);
        if (!Arr::is($definition)) {
            throw new RuntimeException('addon_definition_invalid');
        }
        return $definition;
    }

    private function stillActive(array $identity): bool
    {
        foreach ($this->_model->getActivatedAddOns() as $row) {
            $addon = new AddOn();
            $addon->assign($row);
            if ($this->identity($addon) === $identity) {
                return true;
            }
        }
        return false;
    }

    private function identity(AddOn $addon): array
    {
        return [
            'id' => (string) $addon->addon_id,
            'name' => (string) $addon->name,
            'namespace' => (string) $addon->namespace,
            'path' => (string) $addon->path,
            'primary_file' => (string) $addon->primary_file,
            'version' => (string) $addon->version,
        ];
    }

    private function records(Engine $engine): Arr
    {
        self::$boots ??= new WeakMap();
        return self::$boots[$engine] ??= Arr::make([]);
    }
}
