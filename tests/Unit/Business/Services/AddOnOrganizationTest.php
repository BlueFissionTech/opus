<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AddOnOrganizationService;
use App\Business\Services\SQLiteAddOnOrganizationStore;
use BlueFission\Connections\Database\SQLiteLink;
use BlueFission\Services\Service;
use PHPUnit\Framework\TestCase;

final class AddOnOrganizationTest extends TestCase
{
    private string $path;
    private SQLiteLink $link;
    private SQLiteAddOnOrganizationStore $store;
    private array $inventory;
    private array $denied = [];

    protected function setUp(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('SQLite3 is required for the optional organization store.');
        }
        $this->path = tempnam(sys_get_temp_dir(), 'opus-organization-');
        $this->link = new SQLiteLink(['database' => $this->path]);
        $this->link->open();
        require_once dirname(__DIR__, 4) . '/datasources/organization/20261018_create_addon_organization.php';
        (new \CreateAddOnOrganization($this->link))->change();
        $this->store = new SQLiteAddOnOrganizationStore($this->link);
        $this->inventory = [
            ['id' => 'mail', 'name' => 'Mail', 'active' => true, 'capabilities' => ['messages.send'], 'requires' => ['identity'], 'private' => 'not-a-public-field'],
            ['id' => 'identity', 'name' => 'Identity', 'active' => false, 'vendor' => 'bluefission'],
            ['id' => 'search', 'name' => 'Search', 'active' => true],
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->link)) {
            $this->link->close();
        }
        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function service(string $app = 'app', ?string $tenant = 'team'): AddOnOrganizationService
    {
        return new AddOnOrganizationService($this->store, $app, $tenant,
            function (array $request): bool {
                $target = $request['target']['slug'] ?? $request['target']['id'] ?? '';
                return $request['actor_id'] === 'operator'
                    && !in_array($request['action'] . ':' . $target, $this->denied, true);
            }, fn (): array => $this->inventory);
    }

    private function group(string $slug, array $members, int $order = 0): array
    {
        return ['slug' => $slug, 'name' => ucfirst($slug), 'members' => $members, 'order' => $order];
    }

    private function rejects(callable $call, string $code): void
    {
        $error = null;
        try {
            $call();
        } catch (\Exception $caught) {
            $error = $caught;
        }
        $this->assertNotNull($error, 'The operation must fail.');
        $this->assertSame($code, $error->getMessage());
    }

    public function testGroupsTagsAndOrderedMembershipPersistAcrossReopen(): void
    {
        $service = $this->service();
        $this->assertInstanceOf(Service::class, $service);
        $this->assertInstanceOf(Service::class, $this->store);
        $this->assertSame([], $service->catalog('operator')['groups']);
        $this->assertSame(1, $service->saveGroup('operator', $this->group('operations', ['mail', 'identity'], 20), 0));
        $this->assertSame(2, $service->saveGroup('operator', $this->group('support', ['mail', 'search'], 10), 1));
        $this->assertSame(3, $service->tag('operator', ['mail', 'search'], ['priority', 'customer'], 2));
        $this->link->close();
        $this->link->open();
        $catalog = $this->service()->catalog('operator');
        $this->assertSame(['support', 'operations'], array_column($catalog['groups'], 'slug'));
        $this->assertSame(['mail', 'identity'], $catalog['groups'][1]['members']);
        $this->assertSame('partial', $catalog['groups'][1]['state']);
        $this->assertSame('complete', $catalog['groups'][0]['state']);
        $mail = array_values(array_filter($catalog['items'], fn ($a) => $a['id'] === 'mail'))[0];
        $this->assertSame(['support', 'operations'], $mail['groups']);
        $this->assertSame(['customer', 'priority'], $mail['tags']);
        $this->assertArrayNotHasKey('private', $mail);
        $history = $service->history('operator');
        $this->assertSame([1, 2, 3], array_column($history, 'revision'));
        $this->assertSame('tags_replaced', $history[2]['receipt']['action']);
        $this->assertSame('operator', $history[2]['receipt']['actor_id']);
        $this->assertSame([2], array_column($service->history('operator', 1, 1), 'revision'));
    }

    public function testScopeIsolationIncludesNullTenantAndSameNamedGroups(): void
    {
        $service = $this->service();
        $service->saveGroup('operator', $this->group('operations', ['mail']), 0);
        foreach ([$this->service('other'), $this->service('app', null), $this->service('app', 'other')] as $other) {
            $this->assertSame(0, $other->catalog('operator')['revision']);
            $this->assertSame([], $other->history('operator'));
            $this->assertSame(1, $other->saveGroup('operator', $this->group('operations', ['search']), 0));
            $this->assertSame(['search'], $other->catalog('operator')['groups'][0]['members']);
        }
        $this->assertSame(['mail'], $service->catalog('operator')['groups'][0]['members']);
    }

    public function testDeniedActorCannotReadWriteOrAuditAndPolicyMustReturnTrue(): void
    {
        $service = $this->service();
        foreach (['', 'visitor'] as $actor) {
            $this->rejects(fn () => $service->catalog($actor), 'organization_access_denied');
            $this->rejects(fn () => $service->tag($actor, ['mail'], ['tag'], 0), 'organization_access_denied');
            $this->rejects(fn () => $service->history($actor), 'organization_access_denied');
        }
        $loose = new AddOnOrganizationService($this->store, 'app', 'team', fn () => 'true', fn () => $this->inventory);
        $this->rejects(fn () => $loose->catalog('operator'), 'organization_access_denied');
        $this->assertSame([], $service->history('operator'));
    }

    public function testBulkPermissionFailureIsAtomicIncludingRemovedMembers(): void
    {
        $service = $this->service();
        $service->saveGroup('operator', $this->group('operations', ['mail', 'search']), 0);
        $this->denied = ['manage_addon:search'];
        $this->rejects(fn () => $service->tag('operator', ['mail', 'search'], ['blocked'], 1), 'organization_access_denied');
        $this->rejects(fn () => $service->saveGroup('operator', $this->group('operations', ['mail']), 1), 'organization_access_denied');
        $this->rejects(fn () => $service->deleteGroup('operator', 'operations', 1), 'organization_access_denied');
        $this->assertSame(1, $service->catalog('operator')['revision']);
        $this->assertCount(1, $service->history('operator'));
        $this->assertSame([], $service->catalog('operator', ['tag' => 'blocked'])['items']);
    }

    public function testHiddenGroupsAndAddonsDoNotLeakThroughFiltersCountsOrGroupState(): void
    {
        $service = $this->service();
        $service->saveGroup('operator', $this->group('secret', ['mail']), 0);
        $service->saveGroup('operator', $this->group('public', ['mail', 'identity']), 1);
        $this->denied = ['view_group:secret', 'view_addon:identity', 'audit:'];
        $catalog = $service->catalog('operator');
        $this->assertSame(['public'], array_column($catalog['groups'], 'slug'));
        $this->assertSame(['mail'], $catalog['groups'][0]['members']);
        $this->assertSame('complete', $catalog['groups'][0]['state']);
        $this->assertCount(2, $catalog['items']);
        $this->assertSame([], $service->catalog('operator', ['group' => 'secret'])['items']);
        $this->assertStringNotContainsString('secret', json_encode($catalog));
        $this->rejects(fn () => $service->history('operator'), 'organization_access_denied');
    }

    public function testStaleWriterCannotOverwriteStateAndStoreAlsoEnforcesRevision(): void
    {
        $first = $this->service();
        $second = $this->service();
        $first->tag('operator', ['mail'], ['first'], 0);
        $this->rejects(fn () => $second->tag('operator', ['mail'], ['second'], 0), 'organization_revision_conflict');
        $scope = json_encode(['app', 'team']);
        $this->rejects(fn () => $this->store->save($scope, 0, ['groups' => [], 'tags' => []], []), 'organization_revision_conflict');
        $this->assertCount(1, $first->history('operator'));
        $this->assertCount(1, $first->catalog('operator', ['tag' => 'first'])['items']);
    }

    public function testAuditWriteFailureRollsBackStateAndAllowsExplicitRetry(): void
    {
        $db = $this->link->connection();
        $db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON opus_addon_organization_audit BEGIN SELECT RAISE(ABORT, 'private-storage-detail'); END");
        $service = $this->service();
        $this->rejects(fn () => $service->tag('operator', ['mail'], ['tag'], 0), 'organization_storage_unavailable');
        $this->assertSame(0, $service->catalog('operator')['revision']);
        $this->assertSame([], $service->history('operator'));
        $db->exec('DROP TRIGGER fail_audit');
        $this->assertSame(1, $service->tag('operator', ['mail'], ['tag'], 0));
    }

    public function testHundredAddonsCanBeFilteredWithoutInventingOrganizationOrCapabilities(): void
    {
        $this->inventory = [];
        for ($i = 0; $i < 125; $i++) {
            $this->inventory[] = ['id' => sprintf('addon-%03d', $i), 'active' => $i % 2 === 0,
                'vendor' => 'bluefission', 'health' => 'healthy', 'update' => 'current',
                'configuration' => 'configured', 'tenant_availability' => 'available', 'capabilities' => ['search']];
        }
        $service = $this->service();
        $this->assertCount(125, $service->catalog('operator')['items']);
        $service->tag('operator', ['addon-002', 'addon-004', 'addon-003'], ['priority', 'invented.capability'], 0);
        $filtered = $service->catalog('operator', ['active' => true, 'tag' => 'priority', 'vendor' => 'bluefission',
            'health' => 'healthy', 'update' => 'current', 'configuration' => 'configured',
            'tenant_availability' => 'available', 'capability' => 'search', 'search' => 'ADDON-00']);
        $this->assertSame(['addon-002', 'addon-004'], array_column($filtered['items'], 'id'));
        $this->assertSame([], $service->catalog('operator', ['capability' => 'invented.capability'])['items']);
        $this->assertSame([], $filtered['groups']);
        $this->assertSame([], $filtered['items'][0]['groups']);
        $this->rejects(fn () => $service->catalog('operator', ['active' => 'true']), 'organization_filter_invalid');
    }

    public function testUnknownAvailabilityIsVisibleAndEditingDoesNotInferGroups(): void
    {
        unset($this->inventory[0]['active']);
        $service = $this->service();
        $service->saveGroup('operator', $this->group('operations', ['mail']), 0);
        $this->assertSame('unknown', $service->catalog('operator')['groups'][0]['state']);
        $this->assertSame(2, $service->deleteGroup('operator', 'operations', 1));
        $this->assertSame([], $service->catalog('operator')['groups']);
        $this->assertSame(['identity'], $this->inventory[0]['requires']);
    }

    public function testInvalidMembershipAndVisibilityChangesCannotPartiallyWrite(): void
    {
        $service = $this->service();
        $this->rejects(fn () => $service->saveGroup('operator', $this->group('operations', ['mail', 'mail']), 0), 'organization_duplicate_value');
        $this->rejects(fn () => $service->saveGroup('operator', $this->group('operations', ['missing']), 0), 'organization_addon_unavailable');
        $this->rejects(fn () => $service->tag('operator', [], [], 0), 'organization_selection_empty');
        $this->rejects(fn () => $service->tag('operator', ['mail'], ["unsafe\nlabel"], 0), 'organization_text_invalid');
        $this->assertSame(0, $service->catalog('operator')['revision']);
        $this->assertSame([], $service->history('operator'));
    }

    public function testMigrationRepeatDoesNotEraseDataAndRevertIsExplicit(): void
    {
        $service = $this->service();
        $service->tag('operator', ['mail'], ['retain'], 0);
        $this->rejects(fn () => (new \CreateAddOnOrganization($this->link))->change(), 'organization_migration_failed');
        $this->assertCount(1, $service->history('operator'));
        (new \CreateAddOnOrganization($this->link))->revert();
        $this->rejects(fn () => $service->catalog('operator'), 'organization_storage_unavailable');
        (new \CreateAddOnOrganization($this->link))->change();
        $this->assertSame(0, $service->catalog('operator')['revision']);
    }

    public function testMigrationFailureRollsBackEarlierTableCreation(): void
    {
        (new \CreateAddOnOrganization($this->link))->revert();
        $db = $this->link->connection();
        $db->exec('CREATE TABLE opus_addon_organization_audit (existing TEXT)');
        $this->rejects(fn () => (new \CreateAddOnOrganization($this->link))->change(), 'organization_migration_failed');
        $this->assertSame(0, $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='opus_addon_organization'"));
        $this->assertSame(1, $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='opus_addon_organization_audit'"));
    }
    public function testPolicyAndInventoryFailuresDoNotLeakPrivateDetails(): void
    {
        $failedPolicy = new AddOnOrganizationService($this->store, 'app', 'team', function () {
            throw new \RuntimeException('private-policy-detail');
        }, fn () => $this->inventory);
        $this->rejects(fn () => $failedPolicy->catalog('operator'), 'organization_access_denied');
        $failedInventory = new AddOnOrganizationService($this->store, 'app', 'team', fn () => true, function () {
            throw new \RuntimeException('private-inventory-detail');
        });
        $this->rejects(fn () => $failedInventory->catalog('operator'), 'organization_inventory_unavailable');
        $this->assertSame([], $this->service()->history('operator'));
    }

    public function testRemovedPackagesCanBeRemovedFromOrganizationWithoutGrantingTheirCapabilities(): void
    {
        $service = $this->service();
        $service->saveGroup('operator', $this->group('operations', ['mail', 'identity']), 0);
        $this->inventory = array_values(array_filter($this->inventory, fn ($a) => $a['id'] !== 'identity'));
        $this->assertSame(2, $service->saveGroup('operator', $this->group('operations', ['mail']), 1));
        $this->assertSame(['mail'], $service->catalog('operator')['groups'][0]['members']);
    }

    public function testPolicySeesBothExistingAndProposedVisibilityBeforeWriting(): void
    {
        $service = $this->service();
        $service->saveGroup('operator', $this->group('operations', ['mail']), 0);
        $observed = [];
        $restricted = new AddOnOrganizationService($this->store, 'app', 'team', function (array $request) use (&$observed): bool {
            $observed[] = $request;
            return ($request['target']['visibility_policy'] ?? '') !== 'everyone';
        }, fn () => $this->inventory);
        $this->rejects(fn () => $restricted->saveGroup('operator', [...$this->group('operations', ['mail']), 'visibility_policy' => 'everyone'], 1), 'organization_access_denied');
        $this->assertSame(['operators', 'everyone'], array_values(array_filter(array_map(fn ($r) => $r['target']['visibility_policy'] ?? null, $observed))));
        $this->assertSame('operators', $service->catalog('operator')['groups'][0]['visibility_policy']);
        $this->assertCount(1, $service->history('operator'));
    }

    public function testRealLockPreventsWritesAndRecoveryDoesNotInventAuditHistory(): void
    {
        $db = new \SQLite3($this->path);
        try {
            $db->exec('BEGIN EXCLUSIVE');
            $this->rejects(fn () => $this->service()->tag('operator', ['mail'], ['tag'], 0), 'organization_storage_unavailable');
            $db->exec('ROLLBACK');
            $this->assertSame([], $this->service()->history('operator'));
            $this->assertSame(1, $this->service()->tag('operator', ['mail'], ['tag'], 0));
        } finally {
            $db->close();
        }
    }

    public function testCorruptStorageFailsClosedInsteadOfReturningAnEmptyOrganization(): void
    {
        $this->service()->tag('operator', ['mail'], ['tag'], 0);
        $this->link->connection()->exec("UPDATE opus_addon_organization SET document = 'not-json'");
        $this->rejects(fn () => $this->service()->catalog('operator'), 'organization_document_invalid');
    }

    public function testPostCommitEventIsSanitizedAndObserverFailureDoesNotRepeatTheWrite(): void
    {
        $reflection = new \ReflectionClass(\BlueFission\DevElation::class);
        $active = $reflection->getProperty('_isActive');
        $actions = $reflection->getProperty('_actions');
        $originalActive = $active->getValue();
        $originalActions = $actions->getValue();
        try {
            \BlueFission\DevElation::up();
            $actions->setValue(null, []);
            $events = [];
            $auditCounts = [];
            \BlueFission\DevElation::action(\App\Business\Services\ExtensionPointCatalog::ADDON_ORGANIZATION_CHANGED,
                function (array $event) use (&$events, &$auditCounts): void {
                    $events[] = $event;
                    $auditCounts[] = count($this->service()->history('operator'));
                    throw new \RuntimeException('observer-failed-after-commit');
                });
            $service = $this->service();
            $this->assertSame(1, $service->tag('operator', ['mail'], ['private-tag'], 0));
            $this->assertSame([1], $auditCounts);
            $this->assertSame([['application_id' => 'app', 'tenant_id' => 'team', 'revision' => 1, 'action' => 'tags_replaced']], $events);
            $this->assertSame(1, $service->catalog('operator')['revision']);
            $this->assertCount(1, $service->history('operator'));
            $this->rejects(fn () => $service->tag('operator', ['mail'], ['private-tag'], 0), 'organization_revision_conflict');
            $this->assertCount(1, $events);
        } finally {
            $active->setValue(null, $originalActive);
            $actions->setValue(null, $originalActions);
        }
    }

    public function testInvalidUtf8NeverBecomesAnEmptyPersistedDocument(): void
    {
        $service = $this->service();
        $this->rejects(fn () => $service->tag('operator', ['mail'], ["bad\xFF"], 0), 'organization_document_invalid');
        $this->assertSame(0, $service->catalog('operator')['revision']);
        $this->assertSame([], $service->history('operator'));
    }

    public function testNumericStringAddonIdsRetainTheirTypeAtPolicyAndMembershipBoundaries(): void
    {
        $this->inventory = [
            ['id' => '0', 'name' => 'Zero', 'active' => true],
            ['id' => '42', 'name' => 'Forty two', 'active' => true],
        ];
        $service = new AddOnOrganizationService($this->store, 'app', 'team', static function (array $request): bool {
            if (in_array($request['action'], ['view_addon', 'manage_addon'], true)) {
                return in_array($request['target']['id'], ['0', '42'], true);
            }
            return true;
        }, fn () => $this->inventory);
        $service->saveGroup('operator', $this->group('operations', ['42', '0']), 0);
        $service->tag('operator', ['42'], ['selected'], 1);
        $catalog = $service->catalog('operator', ['group' => 'operations']);
        $this->assertSame(['42', '0'], array_column($catalog['items'], 'id'));
        $this->assertSame(['42', '0'], $catalog['groups'][0]['members']);
        $this->assertSame(['42'], array_column($service->catalog('operator', ['tag' => 'selected'])['items'], 'id'));
    }

}
