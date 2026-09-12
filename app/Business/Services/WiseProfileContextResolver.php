<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\WiseProfile;
use BlueFission\Arr;
use BlueFission\Str;
use BlueFission\Val;
use InvalidArgumentException;

final class WiseProfileContextResolver
{
    private const CENTRAL_AGENT = 'opus.central';

    /** @return array{actor: WiseProfile, target: WiseProfile} */
    public function resolve(string $agentId, array $context): array
    {
        $context = Arr::make($context);
        $tenantId = null;
        if ($context->hasKey('tenant_id')) {
            $contextTenant = $context->get('tenant_id');
            if (!Val::isNull($contextTenant) && !Str::is($contextTenant)) {
                throw new InvalidArgumentException('Command tenant must be a string or null.');
            }

            $tenantId = Val::isNull($contextTenant) ? null : (string) $contextTenant;
        }
        $actor = $this->profile(
            $context->get('wise_profile'),
            $this->agentProfile($agentId, $tenantId),
            $tenantId,
            $context->hasKey('wise_profile'),
            true
        );
        $target = $this->profile(
            $context->get('wise_profile_target'),
            $actor,
            $tenantId,
            $context->hasKey('wise_profile_target')
        );

        return ['actor' => $actor, 'target' => $target];
    }

    private function agentProfile(string $agentId, ?string $tenantId): WiseProfile
    {
        $central = $agentId === self::CENTRAL_AGENT;

        return new WiseProfile(
            $central ? WiseProfile::CENTRAL_AGENT : WiseProfile::ADDON_AGENT,
            $agentId,
            $tenantId,
            [$central ? 'agent.central' : 'agent.specialist']
        );
    }

    private function profile(
        mixed $value,
        WiseProfile $fallback,
        ?string $tenantId,
        bool $strict = false,
        bool $requireContextTenant = false
    ): WiseProfile
    {
        if (!Arr::is($value)) {
            if ($strict) {
                throw new InvalidArgumentException('Wise profile context must be an array.');
            }

            return $fallback;
        }

        $profile = Arr::make($value);
        $type = $profile->get('type');
        $principalId = $profile->get('principal_id');
        if (!Str::is($type) || !Str::is($principalId)) {
            if ($strict) {
                throw new InvalidArgumentException('Wise profile context is incomplete.');
            }

            return $fallback;
        }

        $profileTenantId = $tenantId;
        if ($profile->hasKey('tenant_id')) {
            $profileTenant = $profile->get('tenant_id');
            if (!Val::isNull($profileTenant) && !Str::is($profileTenant)) {
                if ($strict) {
                    throw new InvalidArgumentException('Wise profile tenant must be a string or null.');
                }

                return $fallback;
            }

            $profileTenantId = Val::isNull($profileTenant) ? null : (string) $profileTenant;
        }
        if ($requireContextTenant && $profileTenantId !== $tenantId) {
            if ($strict) {
                throw new InvalidArgumentException('Wise profile tenant does not match the command context.');
            }

            return $fallback;
        }

        try {
            $roles = Arr::make((array) $profile->get('roles'));
            if ($roles->isEmpty()) {
                $roles = Arr::make([$this->defaultRole((string) $type)]);
            }

            return new WiseProfile(
                (string) $type,
                (string) $principalId,
                $profileTenantId,
                $roles->toArray()
            );
        } catch (InvalidArgumentException $exception) {
            if ($strict) {
                throw $exception;
            }

            return $fallback;
        }
    }

    private function defaultRole(string $type): string
    {
        return match ($type) {
            WiseProfile::CENTRAL_AGENT => 'agent.central',
            WiseProfile::ADDON_AGENT => 'agent.specialist',
            default => 'user',
        };
    }
}
