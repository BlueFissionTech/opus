<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentDescriptor;
use BlueFission\Arr;
use BlueFission\Str;

final class AgentCapabilityMapValidator
{
    public const VERSION = 1;
    public const MODES = ['central', 'specialist', 'generated', 'disabled'];
    private const MAP_KEYS = ['version', 'owner', 'agents'];
    private const AGENT_KEYS = [
        'mode',
        'description',
        'profile',
        'tools',
        'imports',
        'exports',
        'permissions',
        'lifecycle',
    ];
    private const LIFECYCLE_KEYS = ['states'];

    public function __construct(private ?DeclarativeArrayParser $parser = null)
    {
        $this->parser ??= new DeclarativeArrayParser();
    }

    public function validateFile(string $path, array $knownTools, ?string $expectedOwner = null): array
    {
        $parsed = Arr::make($this->parser->parseFile($path));
        if (!$parsed->get('valid')) {
            return [
                'valid' => false,
                'map' => null,
                'errors' => (array) $parsed->get('errors'),
            ];
        }

        return $this->validate((array) $parsed->get('value'), $knownTools, $expectedOwner);
    }

    public function validate(array $mapping, array $knownTools, ?string $expectedOwner = null): array
    {
        $mapping = Arr::make($mapping);
        $errors = Arr::make([]);
        $normalizedAgents = Arr::make([]);
        $version = $mapping->get('version');
        $owner = $mapping->get('owner');
        $agents = $mapping->get('agents');
        $knownTools = Arr::make($knownTools)->unique();

        $this->rejectUnknownKeys($mapping, self::MAP_KEYS, 'agent_map_schema', $errors);

        if ($version !== self::VERSION) {
            $errors->push($this->problem('agent_map_version', 'Agent map version must be 1.'));
        }
        if (!Str::is($owner)
            || !Str::make($owner)->matches('/^(?:application|[a-z][a-z0-9]*(?:_[a-z0-9]+)*)$/')
        ) {
            $errors->push($this->problem('agent_map_owner', 'Agent map owner must be application or a lifecycle-safe key.'));
        }
        if ($expectedOwner !== null && $owner !== $expectedOwner) {
            $errors->push($this->problem('agent_map_owner', 'Agent map owner must match the package lifecycle key.'));
        }
        if (!Arr::is($agents) || Arr::make($agents)->isEmpty()) {
            $errors->push($this->problem('agent_map_agents', 'Agent map must declare at least one agent boundary.'));
            $agents = [];
        } elseif (Arr::make($agents)->count() !== 1) {
            $errors->push($this->problem('agent_map_agents', 'Each map must declare exactly one agent boundary.'));
        }

        Arr::make((array) $agents)->each(function ($descriptor, $id) use (
            $errors,
            $knownTools,
            $normalizedAgents,
            $owner
        ): void {
            if (!Str::is($id) || !Str::make($id)->matches('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/')) {
                $errors->push($this->problem('agent_identifier', 'Agent identifiers must use lowercase dotted or dashed names.'));
                return;
            }
            if (!Arr::is($descriptor)) {
                $errors->push($this->problem('agent_descriptor', "Agent {$id} must be a declarative object."));
                return;
            }

            $normalizedAgents->set(
                (string) $id,
                $this->validateDescriptor((string) $id, (string) $owner, $descriptor, $knownTools, $errors)
            );
        });

        $valid = $errors->isEmpty();

        return [
            'valid' => $valid,
            'map' => $valid
                ? new AgentCapabilityMap((int) $version, (string) $owner, $normalizedAgents->toArray())
                : null,
            'errors' => $errors->toArray(),
        ];
    }

    public function knownToolsFromConsole(array $mapping): array
    {
        $resources = Arr::make($mapping)->get('resources');
        if (!Arr::is($resources)) {
            return [];
        }

        $tools = Arr::make([]);
        Arr::make($resources)->each(function ($actions, $resource) use ($tools): void {
            if (!Str::is($resource) || !Arr::is($actions)) {
                return;
            }
            Arr::make($actions)->each(function ($action) use ($tools, $resource): void {
                if (Str::is($action)) {
                    $tools->push(Str::make((string) $resource)->append('.')->append((string) $action)->val());
                }
            });
        });

        return $tools->unique()->sort()->toArray();
    }

    public function validateRelationships(AgentCapabilityMap $application, array $addOns = []): array
    {
        $agents = Arr::make($application->agents());
        Arr::make($addOns)->each(function ($map) use ($agents): void {
            if ($map instanceof AgentCapabilityMap) {
                $agents->merge($map->agents());
            }
        });
        $errors = Arr::make([]);

        $agents->each(function ($agent) use ($agents, $errors): void {
            if (!$agent instanceof AgentDescriptor) {
                return;
            }
            Arr::make($agent->imports())->each(function ($tools, $targetId) use ($agent, $agents, $errors): void {
                $target = $agents->get((string) $targetId);
                if (!$target instanceof AgentDescriptor) {
                    $errors->push($this->problem(
                        'agent_import_target',
                        "Agent {$agent->id()} imports from an unknown agent boundary."
                    ));
                    return;
                }
                if ($agent->id() === 'opus.central'
                    && Arr::make(['specialist', 'generated'])->has($target->mode(), true)
                ) {
                    $errors->push($this->problem(
                        'agent_central_leakage',
                        "Agent {$target->id()} must be reached through delegation."
                    ));
                }

                $owned = Arr::make($target->tools());
                $reciprocal = Arr::make((array) Arr::make($target->exports())->get($agent->id()));
                Arr::make((array) $tools)->each(function (string $tool) use (
                    $agent,
                    $target,
                    $owned,
                    $reciprocal,
                    $errors
                ): void {
                    if (!$owned->has($tool, true)) {
                        $errors->push($this->problem(
                            'agent_import_unknown',
                            "Agent {$agent->id()} imports a tool not owned by {$target->id()}."
                        ));
                    } elseif (!$reciprocal->has($tool, true)) {
                        $errors->push($this->problem(
                            'agent_import_reciprocal',
                            "Agent {$agent->id()} requires an exact reciprocal grant from {$target->id()}."
                        ));
                    }
                });
            });
        });

        return [
            'valid' => $errors->isEmpty(),
            'errors' => $errors->toArray(),
        ];
    }

    private function validateDescriptor(
        string $id,
        string $owner,
        array $descriptor,
        Arr $knownTools,
        Arr $errors
    ): array
    {
        $descriptor = Arr::make($descriptor);
        $mode = $descriptor->get('mode');
        $tools = $this->stringList($descriptor->get('tools'), 'agent_tools', $errors);
        $permissions = $this->stringList($descriptor->get('permissions'), 'agent_permissions', $errors);
        $imports = $this->grantMap($descriptor->get('imports'), 'agent_imports', $errors);
        $exports = $this->grantMap($descriptor->get('exports'), 'agent_exports', $errors);
        $lifecycle = Arr::make(Arr::is($descriptor->get('lifecycle')) ? $descriptor->get('lifecycle') : []);
        $states = $this->stringList($lifecycle->get('states'), 'agent_lifecycle', $errors);

        $this->rejectUnknownKeys($descriptor, self::AGENT_KEYS, 'agent_descriptor_schema', $errors);
        $this->rejectUnknownKeys($lifecycle, self::LIFECYCLE_KEYS, 'agent_lifecycle_schema', $errors);

        if (!Str::is($mode) || !Arr::make(self::MODES)->has($mode, true)) {
            $errors->push($this->problem('agent_mode', "Agent {$id} has an invalid mode."));
        }
        if (!Str::is($descriptor->get('description'))
            || Str::make((string) $descriptor->get('description'))->trim()->isEmpty()
        ) {
            $errors->push($this->problem('agent_description', "Agent {$id} requires a description."));
        }
        if ($mode !== 'disabled'
            && (!Str::is($descriptor->get('profile'))
                || Str::make((string) $descriptor->get('profile'))->trim()->isEmpty())
        ) {
            $errors->push($this->problem('agent_profile', "Agent {$id} requires a profile reference."));
        }
        if ($states->isEmpty()) {
            $errors->push($this->problem('agent_lifecycle', "Agent {$id} requires explicit lifecycle states."));
        }

        $tools->each(function (string $tool) use ($knownTools, $errors, $id): void {
            if (!Str::make($tool)->matches('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$/')) {
                $errors->push($this->problem('agent_tool_identifier', "Agent {$id} contains an invalid tool identifier."));
            } elseif (!$knownTools->has($tool, true)) {
                $errors->push($this->problem('agent_tool_unknown', "Agent {$id} references unknown local tool {$tool}."));
            }
        });

        $exports->each(function ($grants, $target) use ($tools, $errors, $id, $mode): void {
            if ($target === $id) {
                $errors->push($this->problem('agent_grant_self', "Agent {$id} cannot grant itself cross-scope tools."));
            }
            Arr::make((array) $grants)->each(function (string $tool) use ($tools, $errors, $id): void {
                if (!$tools->has($tool, true)) {
                    $errors->push($this->problem('agent_export_unknown', "Agent {$id} may export only package-owned tools."));
                }
            });
            if ($target === 'opus.central' && Arr::make(['specialist', 'generated'])->has($mode, true)) {
                $errors->push($this->problem(
                    'agent_central_leakage',
                    "Agent {$id} must be reached by delegation instead of exporting tools to the central agent."
                ));
            }
        });

        if ($mode === 'disabled'
            && ($tools->isNotEmpty() || $imports->isNotEmpty() || $exports->isNotEmpty() || $permissions->isNotEmpty())
        ) {
            $errors->push($this->problem('agent_disabled_grant', "Disabled agent {$id} cannot declare grants."));
        }
        if ($owner === 'application' && $id !== 'opus.central') {
            $errors->push($this->problem('agent_root_identifier', 'The application map may define only opus.central.'));
        } elseif ($owner !== 'application' && $id !== 'addon.' . $owner) {
            $errors->push($this->problem(
                'agent_addon_identifier',
                "Package {$owner} must define the exact addon.{$owner} agent boundary."
            ));
        }

        $descriptor->set('tools', $tools->toArray());
        $descriptor->set('permissions', $permissions->toArray());
        $descriptor->set('imports', $imports->toArray());
        $descriptor->set('exports', $exports->toArray());
        $lifecycle->set('states', $states->toArray());
        $descriptor->set('lifecycle', $lifecycle->toArray());

        return $descriptor->toArray();
    }

    private function rejectUnknownKeys(Arr $value, array $allowed, string $code, Arr $errors): void
    {
        $unknown = $value->keys()
            ->filter(fn ($key): bool => !Arr::make($allowed)->has($key, true));
        if ($unknown->count() > 0) {
            $errors->push($this->problem(
                $code,
                'Unsupported keys are not allowed: ' . $unknown->join(', ')->val() . '.'
            ));
        }
    }

    private function stringList($value, string $code, Arr $errors): Arr
    {
        if (!Arr::is($value)) {
            $errors->push($this->problem($code, 'Expected a list of strings.'));
            return Arr::make([]);
        }

        $list = Arr::make($value);
        $normalized = $list
            ->filter(fn ($item): bool => Str::is($item) && Str::make((string) $item)->trim()->isNotEmpty())
            ->map(fn ($item): string => Str::make((string) $item)->trim()->lower()->val());
        if ($normalized->count() !== $list->count() || $normalized->unique()->count() !== $normalized->count()) {
            $errors->push($this->problem($code, 'Lists must contain unique, nonempty strings.'));
        }

        return $normalized->unique();
    }

    private function grantMap($value, string $code, Arr $errors): Arr
    {
        if (!Arr::is($value)) {
            $errors->push($this->problem($code, 'Expected an agent-to-tool grant map.'));
            return Arr::make([]);
        }

        $grants = Arr::make([]);
        Arr::make($value)->each(function ($tools, $agent) use ($grants, $errors, $code): void {
            if (!Str::is($agent)
                || !Str::make((string) $agent)->matches('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/')
            ) {
                $errors->push($this->problem($code, 'Grant targets must be exact agent identifiers.'));
                return;
            }
            $grants->set((string) $agent, $this->stringList($tools, $code, $errors)->toArray());
        });

        return $grants;
    }

    private function problem(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
