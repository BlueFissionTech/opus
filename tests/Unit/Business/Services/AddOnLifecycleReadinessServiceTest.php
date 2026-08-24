<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AddOnLifecycleReadinessService;
use PHPUnit\Framework\TestCase;

final class AddOnLifecycleReadinessServiceTest extends TestCase
{
    public function testRequiredDatasourceFailureOverridesOptimisticLifecycleStatus(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => true,
            'action' => 'install',
            'addon' => 'sample',
            'changed' => true,
            'stage' => 'complete',
            'nextAction' => 'activate',
            'hooks' => [],
            'migrations' => [
                'ok' => false,
                'error' => 'Required schema could not be applied.',
            ],
            'population' => ['ok' => true],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('migrations', $result['stage']);
        $this->assertSame('blocked', $result['readiness']['state']);
        $this->assertSame('addon_migration_failed', $result['readiness']['reasons'][0]['code']);
        $this->assertSame('retry_lifecycle', $result['nextAction']);
    }

    public function testAbsentOptionalHookIsReportedAsSkippedWithoutContradictingSuccess(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => false,
            'action' => 'install',
            'addon' => 'sample',
            'changed' => true,
            'stage' => 'complete',
            'nextAction' => 'activate',
            'hooks' => [[
                'ok' => false,
                'hook' => 'install',
                'status' => 'missing_callable',
                'error' => 'No compatible lifecycle hook callable was found.',
            ]],
            'migrations' => ['ok' => true],
            'population' => ['ok' => true],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('ready', $result['readiness']['state']);
        $this->assertTrue($result['hooks'][0]['optional']);
        $this->assertSame('skipped', $result['hooks'][0]['status']);
        $this->assertSame('lifecycle_hook_not_declared', $result['hooks'][0]['reason']);
        $this->assertNull($result['hooks'][0]['error']);
        $this->assertSame('activate', $result['nextAction']);
    }

    public function testRequiredHookResolutionFailureBlocksLifecycleReadiness(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => true,
            'action' => 'install',
            'addon' => 'sample',
            'changed' => false,
            'stage' => 'complete',
            'hooks' => [[
                'ok' => false,
                'hook' => 'install',
                'status' => 'missing_primary_file',
                'error' => 'Primary file could not be resolved.',
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('hook', $result['stage']);
        $this->assertSame('addon_hook_failed', $result['readiness']['reasons'][0]['code']);
    }

    public function testOptionalHookDoesNotHideAnIndependentLifecycleFailure(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => false,
            'action' => 'install',
            'addon' => 'sample',
            'changed' => false,
            'stage' => 'registration',
            'error' => 'Registration factory failed.',
            'hooks' => [[
                'ok' => false,
                'hook' => 'install',
                'status' => 'missing_callable',
                'error' => 'No compatible lifecycle hook callable was found.',
            ]],
            'migrations' => ['ok' => true],
            'population' => ['ok' => true],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('registration', $result['stage']);
        $this->assertSame('Registration factory failed.', $result['error']);
        $this->assertSame('addon_lifecycle_failed', $result['readiness']['reasons'][0]['code']);
    }

    public function testRequiredHookDoesNotHideAnIndependentAggregateFailure(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => false,
            'action' => 'install',
            'addon' => 'sample',
            'changed' => false,
            'stage' => 'registration',
            'error' => 'Registration factory failed.',
            'hooks' => [[
                'ok' => false,
                'hook' => 'install',
                'status' => 'failed',
                'error' => 'Required hook failed.',
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('registration', $result['stage']);
        $this->assertSame('addon_lifecycle_failed', $result['readiness']['reasons'][0]['code']);
        $this->assertSame('addon_hook_failed', $result['readiness']['reasons'][1]['code']);
    }

    public function testRecoveredSingleHookClearsFailureMetadata(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => false,
            'action' => 'install',
            'addon' => 'sample',
            'changed' => true,
            'stage' => 'hook',
            'nextAction' => 'retry_lifecycle',
            'hooks' => [[
                'ok' => false,
                'hook' => 'install',
                'status' => 'missing_callable',
                'error' => 'No compatible lifecycle hook callable was found.',
            ]],
            'migrations' => ['ok' => true],
            'population' => ['ok' => true],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('complete', $result['stage']);
        $this->assertSame('activate', $result['nextAction']);
        $this->assertNull($result['error']);
    }

    public function testMultipleRequiredFailuresKeepTheSelectedStageAndErrorAligned(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => false,
            'action' => 'install',
            'addon' => 'sample',
            'changed' => true,
            'stage' => 'population',
            'error' => 'Population failed.',
            'hooks' => [],
            'migrations' => ['ok' => false, 'error' => 'Migration failed.'],
            'population' => ['ok' => false, 'error' => 'Population failed.'],
        ]);

        $this->assertSame('population', $result['stage']);
        $this->assertSame('Population failed.', $result['error']);
        $this->assertSame('addon_migration_failed', $result['readiness']['reasons'][0]['code']);
        $this->assertSame('addon_population_failed', $result['readiness']['reasons'][1]['code']);
    }

    public function testBatchCountsAreRecomputedFromNormalizedChildren(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => true,
            'action' => 'install_all',
            'total' => 2,
            'succeeded' => 2,
            'failed' => 0,
            'nextAction' => 'activate_all',
            'results' => [
                [
                    'ok' => true,
                    'action' => 'install',
                    'addon' => 'first',
                    'changed' => true,
                    'hooks' => [],
                    'migrations' => ['ok' => true],
                    'population' => ['ok' => true],
                ],
                [
                    'ok' => true,
                    'action' => 'install',
                    'addon' => 'second',
                    'changed' => false,
                    'hooks' => [],
                    'migrations' => ['ok' => false, 'error' => 'Migration failed.'],
                    'population' => [],
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['succeeded']);
        $this->assertSame(1, $result['failed']);
        $this->assertTrue($result['changed']);
        $this->assertSame('blocked', $result['readiness']['state']);
        $this->assertFalse($result['results'][1]['ok']);
        $this->assertSame('migrations', $result['stage']);
        $this->assertSame('Migration failed.', $result['error']);
        $this->assertSame('retry_lifecycle', $result['nextAction']);
    }

    public function testRecoveredOptionalHookBatchClearsFailureMetadata(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => false,
            'action' => 'install_all',
            'stage' => 'hook',
            'error' => 'A child failed.',
            'nextAction' => 'retry_lifecycle',
            'results' => [[
                'ok' => false,
                'action' => 'install',
                'addon' => 'sample',
                'changed' => true,
                'stage' => 'complete',
                'hooks' => [[
                    'ok' => false,
                    'hook' => 'install',
                    'status' => 'missing_callable',
                    'error' => 'No compatible lifecycle hook callable was found.',
                ]],
                'migrations' => ['ok' => true],
                'population' => ['ok' => true],
            ]],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('complete', $result['stage']);
        $this->assertNull($result['error']);
        $this->assertSame('activate_all', $result['nextAction']);
        $this->assertSame('ready', $result['readiness']['state']);
    }

    public function testBatchPreservesAnIndependentFailureWithoutFailedChildren(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => false,
            'action' => 'install_all',
            'stage' => 'discovery',
            'error' => 'Add-on discovery failed.',
            'results' => [],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('discovery', $result['stage']);
        $this->assertSame('Add-on discovery failed.', $result['error']);
        $this->assertSame('blocked', $result['readiness']['state']);
        $this->assertSame('addon_lifecycle_failed', $result['readiness']['reasons'][0]['code']);
    }

    public function testAggregateFailureWithSharedMessageAndDifferentStageRemainsIndependent(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => false,
            'action' => 'install',
            'stage' => 'registration',
            'error' => 'Operation failed.',
            'hooks' => [[
                'ok' => false,
                'status' => 'failed',
                'error' => 'Operation failed.',
            ]],
        ]);

        $this->assertSame('registration', $result['stage']);
        $this->assertSame('addon_lifecycle_failed', $result['readiness']['reasons'][0]['code']);
        $this->assertSame('addon_hook_failed', $result['readiness']['reasons'][1]['code']);
    }

    public function testIndependentDiscoveryFailureSurvivesOptionalChildRecovery(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => false,
            'action' => 'install_all',
            'stage' => 'discovery',
            'error' => 'Discovery failed.',
            'results' => [[
                'ok' => false,
                'action' => 'install',
                'stage' => 'hook',
                'hooks' => [[
                    'ok' => false,
                    'status' => 'missing_callable',
                    'error' => 'No lifecycle hook.',
                ]],
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('discovery', $result['stage']);
        $this->assertSame('Discovery failed.', $result['error']);
    }

    public function testBatchWithoutAggregateErrorUsesTheMatchingChildFailure(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => false,
            'action' => 'install_all',
            'stage' => 'migrations',
            'results' => [[
                'ok' => false,
                'action' => 'install',
                'stage' => 'migrations',
                'migrations' => ['ok' => false, 'error' => 'Schema failed.'],
            ]],
        ]);

        $this->assertSame('migrations', $result['stage']);
        $this->assertSame('Schema failed.', $result['error']);
        $this->assertCount(1, $result['readiness']['reasons']);
    }

    public function testRecoveredOptionalHookBatchMayOmitTheAggregateStage(): void
    {
        $result = (new AddOnLifecycleReadinessService())->normalize([
            'ok' => false,
            'action' => 'install_all',
            'results' => [[
                'ok' => false,
                'action' => 'install',
                'hooks' => [[
                    'ok' => false,
                    'status' => 'missing_callable',
                ]],
            ]],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('complete', $result['stage']);
        $this->assertNull($result['error']);
        $this->assertSame('ready', $result['readiness']['state']);
    }
}
