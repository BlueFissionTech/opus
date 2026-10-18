<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\AddOn\IAddOnOrganizationStore;
use BlueFission\Arr;
use BlueFission\Date;
use BlueFission\DevElation;
use BlueFission\Net\HTTP;
use BlueFission\Services\Service;
use BlueFission\Str;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/** Host policy and installed inventory are trusted dependencies, never request fields. */
final class AddOnOrganizationService extends Service
{
    private Closure $policy;
    private Closure $inventory;
    private string $scope;

    public function __construct(
        private IAddOnOrganizationStore $store,
        private string $applicationId,
        private ?string $tenantId,
        callable $policy,
        callable $inventory
    ) {
        parent::__construct();
        $this->text($applicationId, 256);
        if ($tenantId !== null) {
            $this->text($tenantId, 256);
        }
        $scope = HTTP::jsonEncode([$applicationId, $tenantId]);
        if (!Str::is($scope)) {
            throw new InvalidArgumentException('organization_scope_invalid');
        }
        $this->scope = $scope;
        $this->policy = Closure::fromCallable($policy);
        $this->inventory = Closure::fromCallable($inventory);
    }

    public function catalog(string $actorId, array $filters = []): array
    {
        $this->requireAccess($actorId, 'view');
        $this->filters($filters);
        $state = $this->store->load($this->scope);
        $inventory = $this->installed();
        $visible = [];
        foreach ($inventory as $id => $addon) {
            if ($this->allowed($actorId, 'view_addon', ['id' => $id])) {
                $visible[$id] = $addon;
            }
        }
        $groups = [];
        foreach ($state['groups'] as $id => $group) {
            if (!$this->allowed($actorId, 'view_group', $group)) {
                continue;
            }
            $members = Arr::make($group['members'])->filter(fn ($member): bool => Arr::hasKey($visible, $member))->values()->toArray();
            // Hidden membership must not disclose counts or influence the visible state.
            $active = Arr::make($members)->filter(fn ($member): bool => $visible[$member]['active'] === true)->count();
            $unknown = Arr::make($members)->filter(fn ($member): bool => $visible[$member]['active'] === null)->count();
            $groups[$id] = [...$group, 'members' => $members, 'state' => $unknown > 0 ? 'unknown'
                : ($members === [] ? 'empty' : ($active === Arr::size($members) ? 'complete' : ($active > 0 ? 'partial' : 'inactive')))];
        }
        uasort($groups, static fn ($a, $b): int => [$a['order'], $a['slug']] <=> [$b['order'], $b['slug']]);
        $items = [];
        foreach ($visible as $id => $addon) {
            $membership = [];
            foreach ($groups as $group) {
                if (Arr::has($group['members'], $id, true)) {
                    $membership[] = $group['slug'];
                }
            }
            $item = [...$addon, 'groups' => $membership, 'tags' => $state['tags'][$id] ?? []];
            if ($this->matches($item, $filters)) {
                $items[] = $item;
            }
        }
        usort($items, static fn ($a, $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);
        return ['revision' => $state['revision'], 'groups' => Arr::make($groups)->values()->toArray(), 'items' => $items];
    }

    public function saveGroup(string $actorId, array $group, int $revision): int
    {
        $this->requireAccess($actorId, 'manage');
        $normalized = $this->group($group);
        $state = $this->state($revision);
        $id = $normalized['slug'];
        if (Arr::hasKey($state['groups'], $id)) {
            $this->requireAccess($actorId, 'manage_group', $state['groups'][$id]);
        }
        $this->requireAccess($actorId, 'manage_group', $normalized);
        // Removing a member also changes its organization and needs authority.
        $this->members($actorId, $state['groups'][$id]['members'] ?? [], false);
        $this->members($actorId, $normalized['members']);
        $state['groups'][$id] = $normalized;
        return $this->commit($actorId, 'group_saved', $state, $revision, ['group' => $id]);
    }

    public function deleteGroup(string $actorId, string $slug, int $revision): int
    {
        $this->requireAccess($actorId, 'manage');
        $state = $this->state($revision);
        $group = $state['groups'][$slug] ?? null;
        if ($group === null) {
            throw new InvalidArgumentException('organization_group_unavailable');
        }
        $this->requireAccess($actorId, 'manage_group', $group);
        $this->members($actorId, $group['members'], false);
        unset($state['groups'][$slug]);
        return $this->commit($actorId, 'group_deleted', $state, $revision, ['group' => $slug]);
    }

    /** Replace tags on all selected add-ons atomically; no dependency metadata is written. */
    public function tag(string $actorId, array $addonIds, array $tags, int $revision): int
    {
        $this->requireAccess($actorId, 'manage');
        $addonIds = $this->strings($addonIds, 1000, 256);
        $tags = $this->strings($tags, 50, 80);
        if ($addonIds === []) {
            throw new InvalidArgumentException('organization_selection_empty');
        }
        sort($tags, SORT_STRING);
        $state = $this->state($revision);
        $this->members($actorId, $addonIds);
        foreach ($addonIds as $id) {
            if ($tags === []) {
                unset($state['tags'][$id]);
            } else {
                $state['tags'][$id] = $tags;
            }
        }
        return $this->commit($actorId, 'tags_replaced', $state, $revision, ['addons' => $addonIds]);
    }

    public function history(string $actorId, int $after = 0, int $limit = 100): array
    {
        // Audit access is separate: records can reference groups hidden from ordinary viewers.
        $this->requireAccess($actorId, 'audit');
        return $this->store->history($this->scope, $after, $limit);
    }

    private function commit(string $actor, string $action, array $state, int $revision, array $target): int
    {
        $audit = ['actor_id' => $actor, 'action' => $action, 'target' => $target, 'occurred_at' => Date::now()->format('c')->val()];
        $saved = $this->store->save($this->scope, $revision, $state, $audit);
        // Observers cannot alter or veto a committed transaction. Storage is the audit authority.
        try {
            DevElation::do(ExtensionPointCatalog::ADDON_ORGANIZATION_CHANGED, [[
                'application_id' => $this->applicationId, 'tenant_id' => $this->tenantId,
                'revision' => $saved, 'action' => $action,
            ]]);
        } catch (\Throwable) {
        }
        return $saved;
    }

    private function state(int $revision): array
    {
        $state = $this->store->load($this->scope);
        if ($revision < 0 || $state['revision'] !== $revision) {
            throw new RuntimeException('organization_revision_conflict');
        }
        return $state;
    }

    private function members(string $actor, array $members, bool $requireInstalled = true): void
    {
        $inventory = $requireInstalled ? $this->installed() : [];
        foreach ($members as $member) {
            $this->requireAccess($actor, 'manage_addon', ['id' => $member]);
            if ($requireInstalled && !Arr::hasKey($inventory, $member)) {
                throw new InvalidArgumentException('organization_addon_unavailable');
            }
        }
    }

    private function requireAccess(string $actor, string $action, ?array $target = null): void
    {
        if (!$this->allowed($actor, $action, $target)) {
            throw new RuntimeException('organization_access_denied');
        }
    }

    private function allowed(string $actor, string $action, ?array $target): bool
    {
        if (Str::make($actor)->trim()->isEmpty()) {
            return false;
        }
        try {
            $this->text($actor, 256);
            return ($this->policy)([
                'application_id' => $this->applicationId, 'tenant_id' => $this->tenantId,
                'actor_id' => $actor, 'action' => $action, 'target' => $target,
            ]) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function group(array $group): array
    {
        $slug = $this->text($group['slug'] ?? null, 80);
        if (!Str::make($slug)->matches('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D')) {
            throw new InvalidArgumentException('organization_slug_invalid');
        }
        $order = $group['order'] ?? 0;
        $mode = $group['display_mode'] ?? 'section';
        if (!is_int($order) || $order < 0 || $order > 1000000 || !Arr::has(['section', 'accordion', 'page'], $mode, true)) {
            throw new InvalidArgumentException('organization_display_invalid');
        }
        return [
            'slug' => $slug, 'name' => $this->text($group['name'] ?? null, 160),
            'description' => $this->text($group['description'] ?? '', 2000, true),
            'order' => $order, 'display_mode' => $mode,
            'visibility_policy' => $this->text($group['visibility_policy'] ?? 'operators', 128),
            'members' => $this->strings($group['members'] ?? [], 1000, 256),
        ];
    }

    private function strings(mixed $values, int $max, int $length): array
    {
        if (!Arr::is($values) || !array_is_list($values) || Arr::size($values) > $max) {
            throw new InvalidArgumentException('organization_list_invalid');
        }
        $result = Arr::make($values)->map(fn ($value): string => $this->text($value, $length))->toArray();
        if (Arr::make($result)->unique()->count() !== Arr::size($result)) {
            throw new InvalidArgumentException('organization_duplicate_value');
        }
        return $result;
    }

    private function text(mixed $value, int $max, bool $empty = false): string
    {
        if (!Str::is($value) || Str::len($value) > $max || (!$empty && Str::make($value)->trim()->isEmpty())
            || Str::make($value)->matches('/[\x00-\x1f\x7f]/')) {
            throw new InvalidArgumentException('organization_text_invalid');
        }
        return $value;
    }

    private function installed(): array
    {
        try {
            $records = ($this->inventory)();
        } catch (\Throwable) {
            throw new RuntimeException('organization_inventory_unavailable');
        }
        if (!Arr::is($records)) {
            throw new RuntimeException('organization_inventory_unavailable');
        }
        $result = [];
        foreach ($records as $record) {
            if (!Arr::is($record)) {
                throw new RuntimeException('organization_inventory_invalid');
            }
            $id = $this->text($record['id'] ?? null, 256);
            if (Arr::hasKey($result, $id)) {
                throw new RuntimeException('organization_inventory_duplicate');
            }
            $result[$id] = [
                'id' => $id, 'name' => $this->text($record['name'] ?? $id, 160),
                'active' => is_bool($record['active'] ?? null) ? $record['active'] : null,
                'vendor' => $this->text($record['vendor'] ?? 'unknown', 160),
                'health' => $this->text($record['health'] ?? 'unknown', 80),
                'update' => $this->text($record['update'] ?? 'unknown', 80),
                'configuration' => $this->text($record['configuration'] ?? 'unknown', 80),
                'tenant_availability' => $this->text($record['tenant_availability'] ?? 'unknown', 80),
                'capabilities' => $this->strings($record['capabilities'] ?? [], 1000, 256),
            ];
        }
        return $result;
    }

    private function filters(array $filters): void
    {
        foreach ($filters as $key => $value) {
            if (!Arr::has(['search', 'active', 'vendor', 'health', 'update', 'configuration', 'tenant_availability', 'capability', 'group', 'tag'], $key, true)
                || ($key === 'active' ? !is_bool($value) : !Str::is($value))) {
                throw new InvalidArgumentException('organization_filter_invalid');
            }
        }
    }

    private function matches(array $item, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            $match = match ($key) {
                'search' => Str::make($item['name'] . ' ' . $item['id'])->lower()->contains(Str::make($value)->lower()->val()),
                'group' => Arr::has($item['groups'], $value, true),
                'tag' => Arr::has($item['tags'], $value, true),
                'capability' => Arr::has($item['capabilities'], $value, true),
                default => $item[$key] === $value,
            };
            if (!$match) {
                return false;
            }
        }
        return true;
    }
}
