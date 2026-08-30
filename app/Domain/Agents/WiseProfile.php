<?php

declare(strict_types=1);

namespace App\Domain\Agents;

use BlueFission\Arr;
use BlueFission\Str;
use InvalidArgumentException;

final class WiseProfile
{
    public const CENTRAL_AGENT = 'central_agent';
    public const ADDON_AGENT = 'addon_agent';
    public const USER = 'user';
    public const TYPES = [self::CENTRAL_AGENT, self::ADDON_AGENT, self::USER];

    private Str $type;
    private Str $principalId;
    private ?Str $tenantId;
    private Arr $roles;

    public function __construct(string $type, string $principalId, ?string $tenantId = null, array $roles = [])
    {
        $type = Str::make($type)->trim()->lower()->val();
        $principalId = Str::make($principalId)->trim()->val();
        $tenantId = Str::make((string) $tenantId)->trim()->val();

        if (!Arr::make(self::TYPES)->has($type, true)) {
            throw new InvalidArgumentException('Wise profile type is invalid.');
        }
        if (Str::isEmpty($principalId)) {
            throw new InvalidArgumentException('Wise profile principal is required.');
        }

        $this->type = Str::make($type);
        $this->principalId = Str::make($principalId);
        $this->tenantId = Str::isNotEmpty($tenantId) ? Str::make($tenantId) : null;
        $this->roles = Arr::make([]);
        Arr::make($roles)->each(function ($role): void {
            if (!Str::is($role)) {
                return;
            }

            $role = Str::make((string) $role)->trim()->lower()->val();
            if (Str::isNotEmpty($role)) {
                $this->roles->push($role);
            }
        });
        $this->roles = $this->roles->unique()->sort();
    }

    public function type(): string
    {
        return $this->type->val();
    }

    public function principalId(): string
    {
        return $this->principalId->val();
    }

    public function tenantId(): ?string
    {
        return $this->tenantId?->val();
    }

    public function roles(): array
    {
        return $this->roles->toArray();
    }

    public function key(): string
    {
        $scope = $this->tenantId() === null
            ? 'application'
            : 'tenant:' . $this->tenantId();

        return Str::make($scope)
            ->prepend('scope:')
            ->append(':')
            ->append($this->type())
            ->append(':')
            ->append($this->principalId())
            ->val();
    }

    public function samePrincipal(self $profile): bool
    {
        return $this->type() === $profile->type()
            && $this->principalId() === $profile->principalId()
            && $this->tenantId() === $profile->tenantId();
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'type' => $this->type(),
            'principal_id' => $this->principalId(),
            'tenant_id' => $this->tenantId(),
            'roles' => $this->roles(),
        ];
    }
}
