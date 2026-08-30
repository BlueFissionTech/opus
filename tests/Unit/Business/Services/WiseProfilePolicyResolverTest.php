<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\WiseProfileContextResolver;
use App\Business\Services\WiseProfilePolicyResolver;
use App\Domain\Agents\WiseProfile;
use BlueFission\Arr;
use PHPUnit\Framework\TestCase;

final class WiseProfilePolicyResolverTest extends TestCase
{
    private WiseProfilePolicyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new WiseProfilePolicyResolver(
            (array) require dirname(__DIR__, 4) . '/mapping/wise_profiles.php'
        );
    }

    public function testCatalogReservesEveryPrivateProfileResource(): void
    {
        $resources = $this->resolver->resources();

        $this->assertSame(
            ['todos', 'notes', 'functions', 'calendars', 'goals', 'steps'],
            Arr::make($resources)->keys()->toArray()
        );
        $this->assertSame('upstream_required', $resources['functions']['adapter']);
        $this->assertSame('combined', $resources['goals']['adapter']);
        $this->assertSame('combined', $resources['steps']['adapter']);
    }

    public function testOwnersCanUseTheirOwnPrivateResources(): void
    {
        $user = new WiseProfile(WiseProfile::USER, 'user-a', 'tenant-a', ['user']);

        $decision = $this->resolver->authorizeTool($user, $user, 'todo.list');

        $this->assertTrue($decision->allowed());
        $this->assertSame('profile_access_granted', $decision->reason());
        $this->assertSame($user->key(), $decision->metadata()['target_profile']);
    }

    public function testCrossProfileAccessRequiresABoundedDelegation(): void
    {
        $central = new WiseProfile(
            WiseProfile::CENTRAL_AGENT,
            'opus.central',
            'tenant-a',
            ['agent.central']
        );
        $specialist = new WiseProfile(
            WiseProfile::ADDON_AGENT,
            'addon.reports',
            'tenant-a',
            ['agent.specialist']
        );

        $denied = $this->resolver->authorize($central, $specialist, 'notes', 'read');
        $allowed = $this->resolver->authorize(
            $central,
            $specialist,
            'notes',
            'read',
            capabilities: ['profile.delegate.notes.read']
        );
        $writeDenied = $this->resolver->authorize(
            $central,
            $specialist,
            'notes',
            'write',
            capabilities: ['profile.delegate.notes.read']
        );

        $this->assertFalse($denied->allowed());
        $this->assertSame('profile_delegation_required', $denied->reason());
        $this->assertTrue($allowed->allowed());
        $this->assertFalse($writeDenied->allowed());
    }

    public function testTenantBoundaryAndExplicitDeniesCannotBeOverridden(): void
    {
        $central = new WiseProfile(
            WiseProfile::CENTRAL_AGENT,
            'opus.central',
            'tenant-a',
            ['agent.central']
        );
        $otherTenant = new WiseProfile(
            WiseProfile::ADDON_AGENT,
            'addon.reports',
            'tenant-b',
            ['agent.specialist']
        );
        $sameTenant = new WiseProfile(
            WiseProfile::ADDON_AGENT,
            'addon.reports',
            'tenant-a',
            ['agent.specialist']
        );

        $tenantDenied = $this->resolver->authorize(
            $central,
            $otherTenant,
            'todos',
            'read',
            capabilities: ['profile.delegate.*.*']
        );
        $policyDenied = $this->resolver->authorize(
            $central,
            $sameTenant,
            'todos',
            'read',
            ['denies' => ['todos.read']],
            ['grants' => ['todos.read']],
            ['profile.delegate.*.*']
        );

        $this->assertSame('profile_tenant_mismatch', $tenantDenied->reason());
        $this->assertSame('profile_policy_denied', $policyDenied->reason());
    }

    public function testCombinedGoalAndStepAdapterRequiresBothPolicies(): void
    {
        $profile = new WiseProfile(WiseProfile::USER, 'user-a', 'tenant-a', ['user']);

        $allowed = $this->resolver->authorizeTool($profile, $profile, 'step.list');
        $denied = $this->resolver->authorizeTool(
            $profile,
            $profile,
            'step.list',
            principalPolicy: ['denies' => ['goals.read']]
        );

        $this->assertTrue($allowed->allowed());
        $this->assertSame(['goals', 'steps'], $allowed->metadata()['resources']);
        $this->assertFalse($denied->allowed());
        $this->assertSame('profile_policy_denied', $denied->reason());
    }

    public function testContextDefaultsToAnAgentAndAcceptsAnExplicitHumanProfile(): void
    {
        $resolver = new WiseProfileContextResolver();

        $agent = $resolver->resolve('addon.reports', ['tenant_id' => 'tenant-a']);
        $user = $resolver->resolve('opus.central', [
            'tenant_id' => 'tenant-a',
            'wise_profile' => [
                'type' => WiseProfile::USER,
                'principal_id' => 'user-a',
                'tenant_id' => 'tenant-a',
                'roles' => ['user'],
            ],
        ]);

        $this->assertSame(WiseProfile::ADDON_AGENT, $agent['actor']->type());
        $this->assertSame('addon.reports', $agent['actor']->principalId());
        $this->assertSame(WiseProfile::USER, $user['actor']->type());
        $this->assertSame('user-a', $user['target']->principalId());
    }

    public function testApplicationAndLiteralApplicationTenantKeysRemainDistinct(): void
    {
        $application = new WiseProfile(WiseProfile::USER, 'user-a');
        $tenant = new WiseProfile(WiseProfile::USER, 'user-a', 'application');

        $this->assertStringStartsWith('scope:application:', $application->key());
        $this->assertStringStartsWith('scope:tenant:', $tenant->key());
        $this->assertNotSame($application->key(), $tenant->key());
    }

    public function testProfileKeysRemainDistinctWhenIdentifiersContainDelimiters(): void
    {
        $first = new WiseProfile(WiseProfile::USER, 'b:user:c', 'a');
        $second = new WiseProfile(WiseProfile::USER, 'c', 'a:user:b');

        $this->assertNotSame($first->key(), $second->key());
    }

    public function testUnknownActionsInReservedFamiliesFailClosed(): void
    {
        $profile = new WiseProfile(WiseProfile::USER, 'user-a', 'tenant-a', ['user']);
        $decision = $this->resolver->authorizeTool($profile, $profile, 'note.share');

        $this->assertTrue($this->resolver->isProfileTool('note.share'));
        $this->assertFalse($decision->allowed());
        $this->assertSame('profile_action_unknown', $decision->reason());
    }
}
