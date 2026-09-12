<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Net\HTTP;
use BlueFission\Services\Service;
use BlueFission\Str;
use RuntimeException;

final class ExtensionPointCatalog extends Service
{
    public const INTAKE_DEFAULTS = 'opus.intake.defaults';
    public const INTAKE_SESSION_TRANSITIONED = 'opus.intake.session.transitioned';
    public const AGENT_COMMAND_CONTEXT = 'opus.agent.command_context';
    public const AGENT_COMMAND_RUNTIME_READY = 'opus.agent.command_runtime.ready';
    public const AGENT_COMMAND_RUNTIME_UNAVAILABLE = 'opus.agent.command_runtime.unavailable';
    public const CONVERSATION_SETTINGS = 'opus.conversation.settings';
    public const CONVERSATION_CONFIGURATION = 'opus.conversation.configuration';

    public const NAMES = [
        self::INTAKE_DEFAULTS,
        self::INTAKE_SESSION_TRANSITIONED,
        self::AGENT_COMMAND_CONTEXT,
        self::AGENT_COMMAND_RUNTIME_READY,
        self::AGENT_COMMAND_RUNTIME_UNAVAILABLE,
        self::CONVERSATION_SETTINGS,
        self::CONVERSATION_CONFIGURATION,
    ];

    private const KINDS_BY_NAME = [
        self::INTAKE_DEFAULTS => 'filter',
        self::INTAKE_SESSION_TRANSITIONED => 'action',
        self::AGENT_COMMAND_CONTEXT => 'filter',
        self::AGENT_COMMAND_RUNTIME_READY => 'action',
        self::AGENT_COMMAND_RUNTIME_UNAVAILABLE => 'action',
        self::CONVERSATION_SETTINGS => 'filter',
        self::CONVERSATION_CONFIGURATION => 'filter',
    ];

    private const INVENTORY_STATUSES = ['hooked', 'intentionally_closed', 'stronger_abstraction'];
    private const EXECUTION_ORDER = 'priority_ascending_then_registration_order';
    private const VALUE_SCHEMA_TYPES = [
        'object',
        'array',
        'string',
        'integer',
        'number',
        'boolean',
        'null',
        'mixed',
    ];
    private const MUTABILITY_BY_KIND = [
        'filter' => ['replace_value'],
        'action' => ['observe_only'],
    ];
    private const EXCEPTION_POLICY_BY_KIND = [
        'filter' => [
            'propagate_before_write',
            'propagate_before_execution',
            'propagate_before_use',
        ],
        'action' => ['propagate_after_commit', 'ignore_observer_failure'],
    ];

    private string $manifestPath;
    private ?array $manifest = null;

    public function __construct(?string $root = null)
    {
        parent::__construct();
        $root ??= dirname(__DIR__, 3);
        $this->manifestPath = $root . DIRECTORY_SEPARATOR . 'docs'
            . DIRECTORY_SEPARATOR . 'extension-points.json';
    }

    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        if (!FileSystem::fileExists($this->manifestPath)) {
            throw new RuntimeException('Extension-point catalog not found.');
        }

        $contents = FileSystem::fileContents($this->manifestPath);
        if (!Str::is($contents)) {
            throw new RuntimeException('Extension-point catalog could not be read.');
        }

        $decoded = HTTP::jsonDecode($contents, true);
        if (!Arr::is($decoded)) {
            throw new RuntimeException('Extension-point catalog is not valid JSON.');
        }

        $this->manifest = Arr::toArray($decoded, true);

        return $this->manifest;
    }

    public function extensionPoints(): array
    {
        return $this->listFromManifest('extension_points');
    }

    public function boundaryInventory(): array
    {
        return $this->listFromManifest('boundary_inventory');
    }

    public function readinessReport(): array
    {
        $manifest = Arr::make($this->manifest());
        $invalid = Arr::make([]);
        $catalogued = Arr::make([]);
        $covered = Arr::make([]);
        $ownership = Arr::make([]);

        if ($manifest->get('schema_version') !== 1) {
            $invalid->push('schema_version must be 1');
        }
        $catalogVersion = $manifest->get('catalog_version');
        if (!$this->isNonemptyString($catalogVersion)
            || !Str::make($catalogVersion)->matches('/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/')
        ) {
            $invalid->push('catalog_version must be a nonempty string');
            $catalogVersion = '';
        }
        if ($manifest->get('namespace') !== 'opus') {
            $invalid->push('namespace must be opus');
        }
        $this->validateList($manifest, 'extension_points', $invalid);
        $this->validateList($manifest, 'boundary_inventory', $invalid);

        Arr::make($this->extensionPoints())->each(
            fn ($definition) => $this->validateExtensionPoint(
                $definition,
                $catalogued,
                $ownership,
                $invalid
            )
        );

        $areas = Arr::make([]);
        Arr::make($this->boundaryInventory())->each(
            fn ($boundary) => $this->validateBoundary(
                $boundary,
                $areas,
                $catalogued,
                $covered,
                $ownership,
                $invalid
            )
        );

        $expected = Arr::make(self::NAMES);
        $missing = $expected->filter(fn (string $name): bool => !$catalogued->has($name, true))->values();
        $unexpected = $catalogued->filter(fn (string $name): bool => !$expected->has($name, true))->values();
        $uncovered = $catalogued->filter(fn (string $name): bool => !$covered->has($name, true))->values();

        return [
            'catalog_version' => $catalogVersion,
            'extension_point_count' => $catalogued->count(),
            'boundary_count' => $areas->count(),
            'missing' => $missing->val(),
            'unexpected' => $unexpected->val(),
            'uncovered' => $uncovered->val(),
            'invalid' => $invalid->val(),
            'ready' => $catalogued->isNotEmpty()
                && $missing->isEmpty()
                && $unexpected->isEmpty()
                && $uncovered->isEmpty()
                && $invalid->isEmpty(),
        ];
    }

    private function listFromManifest(string $key): array
    {
        $value = Arr::make($this->manifest())->get($key);

        return Arr::is($value) && array_is_list($value) ? Arr::toArray($value, true) : [];
    }

    private function validateList(Arr $manifest, string $key, Arr $invalid): void
    {
        $value = $manifest->get($key);
        if (!Arr::is($value) || !array_is_list($value)) {
            $invalid->push($key . ' must be a list');
        }
    }

    private function validateExtensionPoint(
        $definition,
        Arr $catalogued,
        Arr $ownership,
        Arr $invalid
    ): void
    {
        if (!Arr::is($definition)) {
            $invalid->push('extension-point entry must be an object');
            return;
        }

        $definition = Arr::make($definition);
        $name = $definition->get('name');
        if (!Str::is($name)
            || !Str::make($name)->matches('/^opus\.[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/')
        ) {
            $invalid->push('extension-point name is invalid');
            return;
        }
        if ($catalogued->has($name, true)) {
            $invalid->push('extension-point name is duplicated: ' . $name);
            return;
        }
        $catalogued->push($name);
        $ownership->set($name, $definition->get('area'));

        $kind = $definition->get('kind');
        $kindIsSupported = Arr::make(['filter', 'action'])->has($kind, true);
        if (!$kindIsSupported) {
            $invalid->push($name . ' has an invalid kind');
        }
        $expectedKind = Arr::make(self::KINDS_BY_NAME)->get($name);
        if ($kindIsSupported && Str::is($expectedKind) && $kind !== $expectedKind) {
            $invalid->push($name . ' has kind ' . $kind . ' but runtime kind is ' . $expectedKind);
        }
        Arr::make(['area', 'phase', 'owner', 'mutability', 'exception_policy', 'ordering'])
            ->each(function (string $field) use ($definition, $invalid, $name): void {
                if (!$this->isNonemptyString($definition->get($field))) {
                    $invalid->push($name . ' is missing ' . $field);
                }
            });
        if ($definition->get('ordering') !== self::EXECUTION_ORDER) {
            $invalid->push($name . ' has an unsupported execution order');
        }
        if ($kindIsSupported) {
            $this->validatePolicyValue(
                $definition,
                'mutability',
                self::MUTABILITY_BY_KIND[$kind],
                $kind,
                $name,
                $invalid
            );
            $this->validatePolicyValue(
                $definition,
                'exception_policy',
                self::EXCEPTION_POLICY_BY_KIND[$kind],
                $kind,
                $name,
                $invalid
            );
        }
        Arr::make(['payload', 'returns'])->each(
            fn (string $shape) => $this->validateSchema(
                $definition->get($shape),
                $shape,
                Str::is($kind) ? $kind : '',
                $name,
                $invalid
            )
        );

        $invariants = $definition->get('invariants');
        if (!Arr::is($invariants) || !array_is_list($invariants)) {
            $invalid->push($name . ' invariants must be a list');
        } else {
            Arr::make($invariants)->each(function ($invariant) use ($invalid, $name): void {
                if (!$this->isNonemptyString($invariant)) {
                    $invalid->push($name . ' invariants must contain nonempty strings');
                }
            });
        }
    }

    private function validateBoundary(
        $boundary,
        Arr $areas,
        Arr $catalogued,
        Arr $covered,
        Arr $ownership,
        Arr $invalid
    ): void {
        if (!Arr::is($boundary)) {
            $invalid->push('boundary inventory entry must be an object');
            return;
        }

        $boundary = Arr::make($boundary);
        $area = $boundary->get('area');
        if (!$this->isNonemptyString($area)) {
            $invalid->push('boundary inventory area must be a nonempty string');
            return;
        }
        if ($areas->has($area, true)) {
            $invalid->push('boundary inventory area is duplicated: ' . $area);
            return;
        }
        $areas->push($area);

        $status = $boundary->get('status');
        $statusIsSupported = Arr::make(self::INVENTORY_STATUSES)->has($status, true);
        if (!$statusIsSupported) {
            $invalid->push($area . ' has an invalid inventory status');
        }
        foreach (['owner', 'rationale'] as $field) {
            if (!$this->isNonemptyString($boundary->get($field))) {
                $invalid->push($area . ' is missing ' . $field);
            }
        }

        $names = $boundary->get('extension_points');
        if (!Arr::is($names) || !array_is_list($names)) {
            $invalid->push($area . ' extension_points must be a list');
            return;
        }
        if ($statusIsSupported && $status === 'hooked' && Arr::make($names)->isEmpty()) {
            $invalid->push($area . ' is hooked but has no extension points');
        }
        if ($statusIsSupported && $status !== 'hooked' && Arr::make($names)->isNotEmpty()) {
            $invalid->push($area . ' is not hooked but lists extension points');
        }
        Arr::make($names)->each(function ($name) use (
            $area,
            $catalogued,
            $covered,
            $ownership,
            $invalid
        ): void {
            if (!Str::is($name) || !$catalogued->has($name, true)) {
                $invalid->push($area . ' references an unknown extension point');
                return;
            }
            if ($covered->has($name, true)) {
                $invalid->push($name . ' is assigned to multiple boundary areas');
                return;
            }
            if ($ownership->get($name) !== $area) {
                $invalid->push($name . ' does not belong to boundary area ' . $area);
                return;
            }
            $covered->push($name);
        });
    }

    private function validateSchema(
        $schema,
        string $shape,
        string $kind,
        string $name,
        Arr $invalid
    ): void
    {
        if (!Arr::is($schema)) {
            $invalid->push($name . ' has an invalid ' . $shape . ' schema');
            return;
        }

        $schema = Arr::make($schema);
        $type = $schema->get('type');
        if (!$this->isNonemptyString($type)) {
            $invalid->push($name . ' has an invalid ' . $shape . ' schema');
        }
        if ($this->isNonemptyString($type)) {
            if ($shape === 'returns' && $kind === 'action' && $type !== 'void') {
                $invalid->push($name . ' has an unsupported returns type for action');
            } elseif (($shape !== 'returns' || $kind !== 'action')
                && !Arr::make(self::VALUE_SCHEMA_TYPES)->has($type, true)
            ) {
                $invalid->push($name . ' has an unsupported ' . $shape . ' type');
            }
        }

        foreach (['required', 'properties'] as $field) {
            if (!$schema->hasKey($field)) {
                continue;
            }

            $values = $schema->get($field);
            if (!Arr::is($values) || ($field === 'required' && !array_is_list($values))) {
                $invalid->push($name . ' has invalid ' . $shape . ' ' . $field);
                continue;
            }
            Arr::make($values)->each(function ($value, $key) use (
                $field,
                $invalid,
                $name,
                $shape
            ): void {
                if (!$this->isNonemptyString($value)
                    || ($field === 'properties' && !$this->isNonemptyString($key))) {
                    $invalid->push($name . ' has invalid ' . $shape . ' ' . $field);
                    return;
                }
                if ($field === 'properties'
                    && !Arr::make(self::VALUE_SCHEMA_TYPES)->has($value, true)
                ) {
                    $invalid->push(
                        $name . ' has unsupported ' . $shape . ' property type: ' . $key
                    );
                }
            });
        }
    }

    private function validatePolicyValue(
        Arr $definition,
        string $field,
        array $supported,
        string $kind,
        string $name,
        Arr $invalid
    ): void {
        $value = $definition->get($field);
        if (!$this->isNonemptyString($value)) {
            return;
        }
        if (!Arr::make($supported)->has($value, true)) {
            $invalid->push($name . ' has unsupported ' . $field . ' for ' . $kind);
        }
    }

    private function isNonemptyString($value): bool
    {
        return Str::is($value) && Str::make($value)->trim()->isNotEmpty();
    }
}
