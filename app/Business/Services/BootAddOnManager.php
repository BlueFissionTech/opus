<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Business\Presentation\PackageTheme;
use App\Domain\AddOn\AddOnBootException;
use BlueFission\Arr;
use BlueFission\BlueCore\Business\Managers\AddOnManager;
use BlueFission\BlueCore\Domain\AddOn\AddOn;
use BlueFission\BlueCore\Engine;
use BlueFission\Data\FileSystem;
use BlueFission\DevElation;
use BlueFission\Net\HTTP;
use BlueFission\Str;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Throwable;
use WeakMap;

/** Consumes canonical registration factories on the engine's boot event. */
class BootAddOnManager extends AddOnManager
{
    private static ?WeakMap $boots = null;
    private string $validationProfile = 'package';
    private ?string $validationHostRoot = null;
    private array $optionalAddOns = [];

    /** Host-owned policy, captured per package when queued; required by default. */
    public function useOptionalAddOns(array $names): static
    {
        if (!array_is_list($names)) {
            throw new InvalidArgumentException('addon_optional_policy_invalid');
        }
        foreach ($names as $name) {
            if (!Str::is($name) || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                throw new InvalidArgumentException('addon_optional_policy_invalid');
            }
        }
        $this->optionalAddOns = $names;
        return $this;
    }

    /** Configure from trusted host composition before queuing add-ons. */
    public function useValidationProfile(string $profile, ?string $hostRoot = null): static
    {
        if (!Arr::make(['package', 'embedded'])->has($profile, true)) {
            throw new RuntimeException('addon_validation_profile_invalid');
        }
        $this->validationProfile = $profile;
        $this->validationHostRoot = $hostRoot;
        return $this;
    }

    protected function loadAddOn(AddOn $addOn): array
    {
        $root = (string) $addOn->path;
        $definition = $this->definition($root);
        if (!Arr::hasKey($definition, 'registration')) {
            // Historical imperative entrypoints retain their existing contract.
            return parent::loadAddOn($addOn);
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
            return [];
        }

        $record = Arr::make([
            'identity' => $identity,
            'state' => 'pending',
            'phase' => 'queued',
            'reason' => null,
            'effects' => 'none',
            'contributions' => [],
            'validation_profile' => $this->validationProfile,
            'validation_host' => $this->validationHostRoot,
            'required' => !Arr::has($this->optionalAddOns, $name, true),
        ]);
        $records->set($name, $record);
        $engine->behavior('OnAppLoaded', function () use ($engine, $record, $root, $name): void {
            $state = $record->get('state');
            if ($state === 'failed') {
                if ($this->isolated($record)) {
                    return;
                }
                throw new AddOnBootException($this->report($record));
            }
            if ($state !== 'pending') {
                return;
            }
            // Mark before executing package code, including reentrant dispatch.
            $record->set('state', 'registering');
            try {
                $this->stage($record, 'availability', 'addon_availability_check_failed');
                if (!$this->stillActive($record->get('identity'))) {
                    $record->set('state', 'skipped');
                    $record->set('reason', 'addon_not_active_or_identity_changed');
                    return;
                }
                $this->registerContributions($engine, $record, $root, $name);
                $record->set('state', 'registered');
                $record->set('phase', 'complete');
                $record->set('reason', null);
                $record->set('effects', 'registered');
            } catch (Throwable) {
                $record->set('state', 'failed');
                $record->set('contributions', []);
                if ($this->isolated($record)) {
                    return;
                }
                // Package exceptions may contain credentials or private payloads.
                throw new AddOnBootException($this->report($record));
            } finally {
                $this->publishBootResult($record);
            }
        });

        return [];
    }

    private function publishBootResult(Arr $record): void
    {
        try {
            DevElation::do(ExtensionPointCatalog::ADDON_BOOT_RESULT, [$this->report($record)]);
        } catch (Throwable) {
            // Observers cannot change the settled boot outcome.
        }
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

    /** Host-local diagnostics; exposing them requires the host's own access policy. */
    public function bootReport(string $name): ?array
    {
        $record = $this->records(Engine::instance())->get($name);
        return $record instanceof Arr ? $this->report($record) : null;
    }

    private function report(Arr $record): array
    {
        $identity = Arr::make($record->get('identity'));
        return [
            'version' => 1,
            'identity' => [
                'id' => $identity->get('id'),
                'name' => $identity->get('name'),
                'namespace' => $identity->get('namespace'),
                'version' => $identity->get('version'),
            ],
            'state' => $record->get('state'),
            'phase' => $record->get('phase'),
            'reason' => Arr::has(['failed', 'skipped'], $record->get('state'), true)
                ? $record->get('reason') : null,
            'effects' => $record->get('effects'),
            'retryable' => false,
            'required' => $record->get('required'),
            'isolated' => $this->isolated($record),
        ];
    }

    private function isolated(Arr $record): bool
    {
        return $record->get('state') === 'failed'
            && $record->get('required') === false
            && $record->get('effects') === 'none';
    }

    private function stage(Arr $record, string $phase, string $reason): void
    {
        $record->set('phase', $phase);
        $record->set('reason', $reason);
    }

    private function registerContributions(Engine $engine, Arr $record, string $root, string $name): void
    {
        $this->stage($record, 'validation', 'addon_contract_invalid');
        if (!(new AddOnContractValidator())->validate(
            $root,
            $record->get('validation_profile'),
            $record->get('validation_host')
        )['valid']) {
            throw new RuntimeException('addon_contract_invalid');
        }
        $definition = Arr::make($this->definition($root));
        $identity = Arr::make($record->get('identity'));
        $this->stage($record, 'identity', 'addon_identity_mismatch');
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
        $this->stage($record, 'registration_class', 'addon_registration_class_conflict');
        if (class_exists($class, false)
            && realpath((string) (new ReflectionClass($class))->getFileName()) !== realpath($classFile)
        ) {
            throw new RuntimeException('addon_registration_class_conflict');
        }
        $this->stage($record, 'registration_class', 'addon_registration_class_failed');
        $record->set('effects', 'unknown');
        require_once $classFile;
        $this->stage($record, 'factory', 'addon_registration_factory_failed');
        $factory = require $root . '/' . $registration->get('factory');
        $provider = $factory();
        if (!$provider instanceof $class || !is_callable([$provider, 'contributions'])) {
            $record->set('reason', 'addon_registration_invalid');
            throw new RuntimeException('addon_registration_invalid');
        }
        $this->stage($record, 'contributions', 'addon_contributions_failed');
        $contributions = $provider->contributions();
        if (!Arr::is($contributions)) {
            $record->set('reason', 'addon_contributions_invalid');
            throw new RuntimeException('addon_contributions_invalid');
        }
        $this->stage($record, 'themes', 'addon_theme_registration_failed');
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
