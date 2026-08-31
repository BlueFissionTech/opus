<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\ProfileAccessDecision;
use App\Domain\Agents\WiseProfile;
use BlueFission\Arr;
use BlueFission\Str;

final class WiseProfilePolicyResolver
{
    private Arr $resources;
    private Arr $roles;

    public function __construct(array $configuration)
    {
        $configuration = Arr::make($configuration);
        $this->resources = Arr::make((array) $configuration->get('resources'));
        $this->roles = Arr::make((array) $configuration->get('roles'));
    }

    public function isProfileTool(string $tool): bool
    {
        return $this->reservedToolFamily($tool);
    }

    public function authorizeTool(
        WiseProfile $actor,
        WiseProfile $target,
        string $tool,
        array $tenantPolicy = [],
        array $principalPolicy = [],
        array $capabilities = []
    ): ProfileAccessDecision {
        $descriptors = $this->toolResources($tool);
        if ($descriptors->isEmpty()) {
            if ($this->reservedToolFamily($tool)) {
                return ProfileAccessDecision::deny('profile_action_unknown', ['tool' => $tool]);
            }

            return ProfileAccessDecision::allow('not_profile_resource');
        }

        $resources = Arr::make([]);
        foreach ($descriptors as $resource => $operation) {
            $resources->push((string) $resource);
            $decision = $this->authorize(
                $actor,
                $target,
                (string) $resource,
                (string) $operation,
                $tenantPolicy,
                $principalPolicy,
                $capabilities
            );
            if (!$decision->allowed()) {
                return $decision;
            }
        }

        return ProfileAccessDecision::allow('profile_access_granted', [
            'resources' => $resources->toArray(),
            'operation' => (string) $descriptors->get($resources->get(0)),
            'actor_profile' => $actor->key(),
            'target_profile' => $target->key(),
        ]);
    }

    public function authorize(
        WiseProfile $actor,
        WiseProfile $target,
        string $resource,
        string $operation,
        array $tenantPolicy = [],
        array $principalPolicy = [],
        array $capabilities = []
    ): ProfileAccessDecision {
        $metadata = [
            'resource' => $resource,
            'operation' => $operation,
            'actor_profile' => $actor->key(),
            'target_profile' => $target->key(),
        ];
        if (!$this->resources->hasKey($resource)) {
            return ProfileAccessDecision::deny('profile_resource_unknown', $metadata);
        }
        if ($actor->tenantId() !== $target->tenantId()) {
            return ProfileAccessDecision::deny('profile_tenant_mismatch', $metadata);
        }

        $tenantPolicy = Arr::make($tenantPolicy);
        $principalPolicy = Arr::make($principalPolicy);
        $denies = Arr::make((array) $tenantPolicy->get('denies'))
            ->merge((array) $principalPolicy->get('denies'));
        if ($this->matches($denies, $resource, $operation)) {
            return ProfileAccessDecision::deny('profile_policy_denied', $metadata);
        }

        $own = $actor->samePrincipal($target);
        $scope = $own ? 'own' : 'delegated';
        $allowedByRole = false;
        Arr::make($actor->roles())->each(function (string $role) use (
            &$allowedByRole,
            $scope,
            $resource,
            $operation
        ): void {
            $rolePolicy = Arr::make((array) $this->roles->get($role));
            $patterns = Arr::make((array) $rolePolicy->get($scope));
            $allowedByRole = $allowedByRole || $this->matches($patterns, $resource, $operation);
        });

        $grants = Arr::make((array) $tenantPolicy->get('grants'))
            ->merge((array) $principalPolicy->get('grants'));
        if (!$allowedByRole && !$this->matches($grants, $resource, $operation)) {
            return ProfileAccessDecision::deny('profile_role_denied', $metadata);
        }
        if (!$own && !$this->delegated($capabilities, $resource, $operation)) {
            return ProfileAccessDecision::deny('profile_delegation_required', $metadata);
        }

        return ProfileAccessDecision::allow($own ? 'profile_owner_grant' : 'profile_delegation_grant', $metadata);
    }

    public function resources(): array
    {
        return $this->resources->toArray();
    }

    private function toolResources(string $tool): Arr
    {
        $parts = Str::make($tool)->trim()->lower()->split('.');
        $family = $parts->get(0);
        $action = $parts->get(1);
        $resolved = Arr::make([]);
        if (!Str::is($family) || !Str::is($action)) {
            return $resolved;
        }

        $this->resources->each(function ($descriptor, $resource) use ($family, $action, $resolved): void {
            $descriptor = Arr::make((array) $descriptor);
            if (!$this->descriptorHasToolFamily($descriptor, $family)) {
                return;
            }
            Arr::make((array) $descriptor->get('operations'))->each(
                function ($actions, $operation) use ($action, $resource, $resolved): void {
                    if (Arr::make((array) $actions)->has($action, true)) {
                        $resolved->set((string) $resource, (string) $operation);
                    }
                }
            );
        });

        return $resolved;
    }

    private function reservedToolFamily(string $tool): bool
    {
        $family = Str::make($tool)->trim()->lower()->split('.')->get(0);
        if (!Str::is($family)) {
            return false;
        }

        $reserved = false;
        $this->resources->each(function ($descriptor) use ($family, &$reserved): void {
            $reserved = $reserved || $this->descriptorHasToolFamily(
                Arr::make((array) $descriptor),
                $family
            );
        });

        return $reserved;
    }

    private function descriptorHasToolFamily(Arr $descriptor, string $family): bool
    {
        return Arr::make([(string) $descriptor->get('tool')])
            ->merge((array) $descriptor->get('aliases'))
            ->has($family, true);
    }

    private function matches(Arr $patterns, string $resource, string $operation): bool
    {
        return $patterns->has('*.*', true)
            || $patterns->has($resource . '.*', true)
            || $patterns->has('*.' . $operation, true)
            || $patterns->has($resource . '.' . $operation, true);
    }

    private function delegated(array $capabilities, string $resource, string $operation): bool
    {
        $capabilities = Arr::make($capabilities);

        return $capabilities->has('profile.delegate.*.*', true)
            || $capabilities->has('profile.delegate.' . $resource . '.*', true)
            || $capabilities->has('profile.delegate.*.' . $operation, true)
            || $capabilities->has('profile.delegate.' . $resource . '.' . $operation, true);
    }
}
