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

    private const RUNTIME_CONTRACTS = [
        self::INTAKE_DEFAULTS => [
            'kind' => 'filter',
            'area' => 'application_intake',
            'phase' => 'before_session_creation',
            'owner' => 'ApplicationIntakeService',
            'mutability' => 'replace_value',
            'exception_policy' => 'propagate_before_write',
            'payload_type' => 'object',
            'payload_required' => ['defaults', 'context'],
            'payload_properties' => ['defaults' => 'object', 'context' => 'object'],
            'return_type' => 'object',
            'return_required' => ['defaults'],
            'return_properties' => ['defaults' => 'object'],
        ],
        self::INTAKE_SESSION_TRANSITIONED => [
            'kind' => 'action',
            'area' => 'application_intake',
            'phase' => 'after_persistence',
            'owner' => 'ApplicationIntakeService',
            'mutability' => 'observe_only',
            'exception_policy' => 'propagate_after_commit',
            'payload_type' => 'object',
            'payload_required' => ['transition', 'session'],
            'payload_properties' => ['transition' => 'string', 'session' => 'object'],
            'return_type' => 'void',
            'return_required' => [],
            'return_properties' => [],
        ],
        self::AGENT_COMMAND_CONTEXT => [
            'kind' => 'filter',
            'area' => 'agent_wise_runtime',
            'phase' => 'after_authoritative_context_resolution',
            'owner' => 'AgentCommandContextProvider',
            'mutability' => 'replace_value',
            'exception_policy' => 'propagate_before_execution',
            'payload_type' => 'object',
            'payload_required' => ['agent_id', 'active_addons', 'addon_states', 'capabilities'],
            'payload_properties' => [
                'agent_id' => 'string',
                'active_addons' => 'array',
                'addon_states' => 'object',
                'capabilities' => 'array',
            ],
            'return_type' => 'object',
            'return_required' => [],
            'return_properties' => [],
        ],
        self::AGENT_COMMAND_RUNTIME_READY => [
            'kind' => 'action',
            'area' => 'agent_wise_runtime',
            'phase' => 'after_runtime_construction',
            'owner' => 'LazyAgentCommandProcessor',
            'mutability' => 'observe_only',
            'exception_policy' => 'ignore_observer_failure',
            'payload_type' => 'object',
            'payload_required' => ['status', 'source'],
            'payload_properties' => ['status' => 'string', 'source' => 'string'],
            'return_type' => 'void',
            'return_required' => [],
            'return_properties' => [],
        ],
        self::AGENT_COMMAND_RUNTIME_UNAVAILABLE => [
            'kind' => 'action',
            'area' => 'agent_wise_runtime',
            'phase' => 'after_runtime_construction_failure',
            'owner' => 'LazyAgentCommandProcessor',
            'mutability' => 'observe_only',
            'exception_policy' => 'ignore_observer_failure',
            'payload_type' => 'object',
            'payload_required' => ['status', 'reason', 'retryable'],
            'payload_properties' => [
                'status' => 'string',
                'reason' => 'string',
                'retryable' => 'boolean',
            ],
            'return_type' => 'void',
            'return_required' => [],
            'return_properties' => [],
        ],
        self::CONVERSATION_SETTINGS => [
            'kind' => 'filter',
            'area' => 'scoped_profiles_conversation',
            'phase' => 'after_layer_composition',
            'owner' => 'ConversationalLearningCatalog',
            'mutability' => 'replace_value',
            'exception_policy' => 'propagate_before_use',
            'payload_type' => 'object',
            'payload_required' => ['settings', 'layers'],
            'payload_properties' => ['settings' => 'object', 'layers' => 'object'],
            'return_type' => 'object',
            'return_required' => ['settings'],
            'return_properties' => ['settings' => 'object'],
        ],
        self::CONVERSATION_CONFIGURATION => [
            'kind' => 'filter',
            'area' => 'scoped_profiles_conversation',
            'phase' => 'after_scoped_configuration_composition',
            'owner' => 'ConversationalLearningCatalog',
            'mutability' => 'replace_value',
            'exception_policy' => 'propagate_before_use',
            'payload_type' => 'object',
            'payload_required' => ['configuration', 'profile'],
            'payload_properties' => ['configuration' => 'object', 'profile' => 'object'],
            'return_type' => 'object',
            'return_required' => ['configuration'],
            'return_properties' => ['configuration' => 'object'],
        ],
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
    private ?\stdClass $manifestShape = null;

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
        $decodedShape = HTTP::jsonDecode($contents, false);
        if (!Arr::is($decoded) || !$decodedShape instanceof \stdClass) {
            throw new RuntimeException('Extension-point catalog is not valid JSON.');
        }
        if ($this->hasDuplicateObjectMembers($contents)) {
            throw new RuntimeException('Extension-point catalog contains duplicate object members.');
        }

        $this->manifest = Arr::toArray($decoded, true);
        $this->manifestShape = $decodedShape;

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

        $extensionShapes = $this->manifestListShape('extension_points');
        $boundaryShapes = $this->manifestListShape('boundary_inventory');

        Arr::make($this->extensionPoints())->each(
            fn ($definition, $index) => $this->validateExtensionPoint(
                $definition,
                $extensionShapes[$index] ?? null,
                $catalogued,
                $ownership,
                $invalid
            )
        );

        $areas = Arr::make([]);
        Arr::make($this->boundaryInventory())->each(
            fn ($boundary, $index) => $this->validateBoundary(
                $boundary,
                $boundaryShapes[$index] ?? null,
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
        if (!Arr::is($value)
            || !array_is_list($value)
            || !Arr::is($this->manifestShape?->{$key} ?? null)
        ) {
            $invalid->push($key . ' must be a list');
        }
    }

    private function manifestListShape(string $key): array
    {
        $value = $this->manifestShape?->{$key} ?? null;

        return Arr::is($value) ? $value : [];
    }

    private function validateExtensionPoint(
        $definition,
        $definitionShape,
        Arr $catalogued,
        Arr $ownership,
        Arr $invalid
    ): void
    {
        if (!Arr::is($definition) || !$definitionShape instanceof \stdClass) {
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
        Arr::make(['area', 'phase', 'owner', 'mutability', 'exception_policy', 'ordering'])
            ->each(function (string $field) use ($definition, $invalid, $name): void {
                if (!$this->isNonemptyString($definition->get($field))) {
                    $invalid->push($name . ' is missing ' . $field);
                }
            });
        $this->validateRuntimeContract($definition, $name, $invalid);
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
                $definitionShape->{$shape} ?? null,
                $shape,
                Str::is($kind) ? $kind : '',
                $name,
                $invalid
            )
        );

        $invariants = $definition->get('invariants');
        if (!Arr::is($invariants)
            || !array_is_list($invariants)
            || !Arr::is($definitionShape->invariants ?? null)
        ) {
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
        $boundaryShape,
        Arr $areas,
        Arr $catalogued,
        Arr $covered,
        Arr $ownership,
        Arr $invalid
    ): void {
        if (!Arr::is($boundary) || !$boundaryShape instanceof \stdClass) {
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
        if (!Arr::is($names)
            || !array_is_list($names)
            || !Arr::is($boundaryShape->extension_points ?? null)
        ) {
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

    private function validateRuntimeContract(Arr $definition, string $name, Arr $invalid): void
    {
        if (!Arr::make(self::NAMES)->has($name, true)) {
            return;
        }

        $contract = Arr::make(self::RUNTIME_CONTRACTS)->get($name);
        if (!Arr::is($contract)) {
            $invalid->push($name . ' is missing its runtime contract');
            return;
        }

        $contract = Arr::make($contract);
        Arr::make(['kind', 'area', 'phase', 'owner', 'mutability', 'exception_policy'])
            ->each(function (string $field) use ($contract, $definition, $invalid, $name): void {
                $actual = $definition->get($field);
                $expected = $contract->get($field);
                if ($actual === $expected) {
                    return;
                }
                if ($field === 'kind' && Str::is($actual) && Str::is($expected)) {
                    $invalid->push(
                        $name . ' has kind ' . $actual . ' but runtime kind is ' . $expected
                    );
                    return;
                }
                $invalid->push($name . ' does not match runtime ' . $field);
            });

        Arr::make([
            'payload' => [
                'type' => 'payload_type',
                'required' => 'payload_required',
                'properties' => 'payload_properties',
            ],
            'returns' => [
                'type' => 'return_type',
                'required' => 'return_required',
                'properties' => 'return_properties',
            ],
        ])->each(function (array $contractFields, string $shape) use (
                $contract,
                $definition,
                $invalid,
                $name
            ): void {
                $schema = $definition->get($shape);
                if (Arr::is($schema)
                    && Arr::make($schema)->get('type') !== $contract->get($contractFields['type'])
                ) {
                    $invalid->push($name . ' does not match runtime ' . $shape . ' type');
                }

                $schema = Arr::is($schema) ? Arr::make($schema) : null;
                $actualRequired = $schema !== null && $schema->hasKey('required')
                    ? $schema->get('required')
                    : [];
                $expectedRequired = $contract->get($contractFields['required'], []);
                if (!$this->sameStringSet($actualRequired, $expectedRequired)) {
                    $invalid->push(
                        $name . ' does not match runtime ' . $shape . ' required keys'
                    );
                }

                $actualProperties = $schema !== null && $schema->hasKey('properties')
                    ? $schema->get('properties')
                    : [];
                $expectedProperties = $contract->get($contractFields['properties'], []);
                if (!$this->sameStringMap($actualProperties, $expectedProperties)) {
                    $invalid->push(
                        $name . ' does not match runtime ' . $shape . ' properties'
                    );
                }
            });
    }

    private function validateSchema(
        $schema,
        $schemaShape,
        string $shape,
        string $kind,
        string $name,
        Arr $invalid
    ): void
    {
        if (!Arr::is($schema) || !$schemaShape instanceof \stdClass) {
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
            $rawValues = $schemaShape->{$field} ?? null;
            $hasExpectedContainer = $field === 'required'
                ? Arr::is($rawValues)
                : $rawValues instanceof \stdClass;
            if (!Arr::is($values)
                || !$hasExpectedContainer
                || ($field === 'required' && !array_is_list($values))
            ) {
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

    private function sameStringSet($actual, $expected): bool
    {
        if (!Arr::is($actual) || !array_is_list($actual)
            || !Arr::is($expected) || !array_is_list($expected)
        ) {
            return false;
        }

        $actual = Arr::make($actual);
        $expected = Arr::make($expected);

        return $actual->count() === $expected->count()
            && $actual->unique()->count() === $actual->count()
            && $expected->unique()->count() === $expected->count()
            && $actual->filter(
                fn ($value): bool => Str::is($value) && $expected->has($value, true)
            )->count() === $actual->count();
    }

    private function sameStringMap($actual, $expected): bool
    {
        if (!Arr::is($actual) || !Arr::is($expected)) {
            return false;
        }

        $actual = Arr::make($actual);
        $expected = Arr::make($expected);

        return $actual->count() === $expected->count()
            && $actual->filter(
                fn ($value, $key): bool => Str::is($key)
                    && Str::is($value)
                    && $expected->get($key) === $value
            )->count() === $actual->count();
    }

    private function hasDuplicateObjectMembers(string $json): bool
    {
        $offset = 0;

        return $this->scanJsonValue($json, $offset);
    }

    private function scanJsonValue(string $json, int &$offset): bool
    {
        $this->skipJsonWhitespace($json, $offset);
        $token = $json[$offset] ?? '';

        if ($token === '{') {
            return $this->scanJsonObject($json, $offset);
        }
        if ($token === '[') {
            return $this->scanJsonArray($json, $offset);
        }
        if ($token === '"') {
            $this->scanJsonString($json, $offset);
            return false;
        }

        $length = strlen($json);
        while ($offset < $length
            && !Str::make(",]} \t\r\n")->contains($json[$offset])
        ) {
            $offset++;
        }

        return false;
    }

    private function scanJsonObject(string $json, int &$offset): bool
    {
        $keys = Arr::make([]);
        $offset++;
        $this->skipJsonWhitespace($json, $offset);

        if (($json[$offset] ?? '') === '}') {
            $offset++;
            return false;
        }

        while ($offset < strlen($json)) {
            $rawKey = $this->scanJsonString($json, $offset);
            $key = HTTP::jsonDecode($rawKey, false);
            if (!Str::is($key) || $keys->has($key, true)) {
                return true;
            }
            $keys->push($key);

            $this->skipJsonWhitespace($json, $offset);
            $offset++;
            if ($this->scanJsonValue($json, $offset)) {
                return true;
            }
            $this->skipJsonWhitespace($json, $offset);

            if (($json[$offset] ?? '') === '}') {
                $offset++;
                return false;
            }
            $offset++;
            $this->skipJsonWhitespace($json, $offset);
        }

        return false;
    }

    private function scanJsonArray(string $json, int &$offset): bool
    {
        $offset++;
        $this->skipJsonWhitespace($json, $offset);

        if (($json[$offset] ?? '') === ']') {
            $offset++;
            return false;
        }

        while ($offset < strlen($json)) {
            if ($this->scanJsonValue($json, $offset)) {
                return true;
            }
            $this->skipJsonWhitespace($json, $offset);

            if (($json[$offset] ?? '') === ']') {
                $offset++;
                return false;
            }
            $offset++;
            $this->skipJsonWhitespace($json, $offset);
        }

        return false;
    }

    private function scanJsonString(string $json, int &$offset): string
    {
        $start = $offset;
        $offset++;
        $length = strlen($json);

        while ($offset < $length) {
            if ($json[$offset] === '\\') {
                $offset += 2;
                continue;
            }
            if ($json[$offset] === '"') {
                $offset++;
                break;
            }
            $offset++;
        }

        return substr($json, $start, $offset - $start);
    }

    private function skipJsonWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length
            && Str::make(" \t\r\n")->contains($json[$offset])
        ) {
            $offset++;
        }
    }
}
