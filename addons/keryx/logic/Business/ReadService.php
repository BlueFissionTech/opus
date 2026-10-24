<?php
declare(strict_types=1);
namespace AddOns\Keryx\Business;

use App\Domain\Agents\AgentRuntimeContext;
use BlueFission\Arr;
use BlueFission\Services\{Application, Service};
use BlueFission\Str;
use Closure;
use InvalidArgumentException;
use Throwable;

/** Minimal read projection; registry, queues and policy remain upstream-owned. */
final class ReadService extends Service
{
    public function __construct(
        private readonly Gateway $gateway,
        private readonly string $application,
        private readonly string $tenant,
        private readonly array $repositories,
        private readonly Closure $context
    ) {
        parent::__construct();
        if ($application === '' || $tenant === '' || Arr::count($repositories) > 100) {
            throw new InvalidArgumentException('keryx_scope_invalid');
        }
        foreach ($repositories as $alias => $repo) {
            if (!Str::is($alias) || !Str::make($alias)->matches('/^[a-z0-9._-]{1,80}$/D')
                || !Arr::is($repo) || !is_int($repo['id'] ?? null) || $repo['id'] <= 0
                || !Str::is($repo['canonical'] ?? null)
                || !Str::make($repo['canonical'])->matches('/^[a-z0-9._-]{1,80}$/D')) {
                throw new InvalidArgumentException('keryx_repository_binding_invalid');
            }
        }
    }

    /** Native Wise uses this application's existing delegate/behavior machinery. */
    public function registerWith(Application $app): void
    {
        $app->delegate('keryx', $this);
        $app->register('keryx', 'get', function ($behavior, array $args): void {
            $result = Arr::count($args) === 2 && Str::is($args[0] ?? null) && Str::is($args[1] ?? null)
                ? $this->read($args[0], $args[1])
                : $this->result('denied', 'arguments_invalid');
            $this->_response = json_encode($result, JSON_THROW_ON_ERROR);
        });
    }

    public function read(string $operation, string $alias, int $page = 0): array
    {
        try {
            $actor = $this->authority();
            if ($actor === null || !Arr::hasKey($this->repositories, $alias)) {
                return $this->result('denied', 'scope_denied');
            }
            if (Arr::has(['job', 'discussions'], $operation, true)) {
                return $this->result('unavailable', 'non_mutating_snapshot_required');
            }
            if (!Arr::has(['directory', 'messages', 'capabilities'], $operation, true)) {
                return $this->result('denied', 'operation_denied');
            }
            if ($page !== 0) {
                return $this->result('unavailable', 'native_pagination_unavailable');
            }
            $repo = $this->repositories[$alias];
            // Identity is freshly resolved even for summary/catalog reads.
            $directory = $this->request('/v1/repos/directory?limit=500&filter=' . $repo['canonical']);
            if ($this->authority() !== $actor) {
                return $this->result('denied', 'authority_changed');
            }
            if ($directory['status'] !== 'verified') { return $directory; }
            $rows = $directory['data']['data'] ?? null;
            if (!Arr::is($rows) || !array_is_list($rows) || Arr::count($rows) > 500) {
                return $this->result('verification_failed', 'directory_shape_invalid');
            }
            $matches = [];
            foreach ($rows as $row) {
                if (!Arr::is($row)) { return $this->result('verification_failed', 'directory_shape_invalid'); }
                if (($row['canonicalName'] ?? null) === $repo['canonical'] || ($row['id'] ?? null) === $repo['id']) {
                    $matches[] = $row;
                }
            }
            if (Arr::count($matches) !== 1 || ($matches[0]['id'] ?? null) !== $repo['id']
                || ($matches[0]['canonicalName'] ?? null) !== $repo['canonical']) {
                return $this->result('verification_failed', 'identity_unverified');
            }
            if (($matches[0]['freshness']['state'] ?? null) === 'stale' || ($matches[0]['routingStatus'] ?? null) === 'stale') {
                return $this->result('stale', 'repository_stale');
            }
            if (($matches[0]['freshness']['state'] ?? null) !== 'current' || ($matches[0]['routingStatus'] ?? null) !== 'active') {
                return $this->result('verification_failed', 'repository_not_active');
            }
            if ($operation === 'directory') {
                return $this->result('verified', 'bounded_identity_observed', [['alias' => $alias, 'id' => $repo['id'], 'canonical' => $repo['canonical']]]);
            }
            $response = $this->request($operation === 'messages' ? '/v1/messages/summary' : '/v1/capabilities');
            if ($this->authority() !== $actor) {
                return $this->result('denied', 'authority_changed');
            }
            if ($response['status'] !== 'verified') { return $response; }
            return $operation === 'messages'
                ? $this->messages($response['data'], $repo['canonical'])
                : $this->capabilities($response['data']);
        } catch (Throwable) {
            return $this->result('unavailable', 'gateway_unavailable');
        }
    }

    private function authority(): ?string
    {
        $context = ($this->context)();
        if (!$context instanceof AgentRuntimeContext || $context->applicationId() !== $this->application
            || $context->tenantId() !== $this->tenant
            || !Arr::has($context->capabilities(), 'keryx.read', true)
            || !Arr::has($context->activeAddOns(), 'keryx', true)
            || ($context->lifecycleStates()['keryx'] ?? null) !== 'active') {
            return null;
        }
        $id = $context->actor()['id'] ?? null;
        return Str::is($id) && Str::isNotEmpty(Str::trim($id)) ? $id : null;
    }

    private function request(string $path): array
    {
        $response = $this->gateway->fetch($path);
        if (Arr::has([401, 403], $response['status'] ?? null, true)) {
            return $this->result('denied', 'gateway_denied');
        }
        if (($response['status'] ?? null) !== 200) {
            return $this->result('unavailable', 'http_status_unverified');
        }
        try {
            $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->result('verification_failed', 'json_invalid');
        }
        return Arr::is($data) ? $this->result('verified', 'http_observed', $data)
            : $this->result('verification_failed', 'envelope_invalid');
    }

    private function messages(array $envelope, string $canonical): array
    {
        $rows = $envelope['data'] ?? null;
        if (!Arr::is($rows) || !array_is_list($rows)) {
            return $this->result('verification_failed', 'summary_shape_invalid');
        }
        $output = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!Arr::is($row)) { return $this->result('verification_failed', 'summary_shape_invalid'); }
            if (($row['repoName'] ?? null) !== $canonical) { continue; }
            $severity = $row['severity'] ?? null;
            if (!Arr::has(['info', 'warning', 'error', 'success'], $severity, true) || Arr::has($seen, $severity, true)) {
                return $this->result('verification_failed', 'summary_shape_invalid');
            }
            $total = $this->countValue($row['countTotal'] ?? null);
            $unread = $this->countValue($row['countUnread'] ?? null);
            if ($total === null || $unread === null || $unread > $total) {
                return $this->result('verification_failed', 'summary_count_invalid');
            }
            $seen[] = $severity;
            $output[] = ['severity' => $severity, 'total' => $total, 'unread' => $unread];
        }
        return $this->result('verified', 'scoped_counts_observed', $output);
    }

    private function countValue(mixed $value): ?int
    {
        if (!is_int($value) && !(Str::is($value) && Str::make($value)->matches('/^(0|[1-9][0-9]*)$/D'))) { return null; }
        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $count === false ? null : $count;
    }

    private function capabilities(array $catalog): array
    {
        if (($catalog['version'] ?? null) !== 1 || !Arr::is($catalog['capabilities'] ?? null)) {
            return $this->result('verification_failed', 'catalog_shape_invalid');
        }
        $admitted = ['GET /v1/repos/directory' => 'directory', 'GET /v1/messages/summary' => 'messages'];
        $found = [];
        foreach ($catalog['capabilities'] as $capability) {
            if (!Arr::is($capability) || !Arr::is($capability['endpoints'] ?? null)) {
                return $this->result('verification_failed', 'catalog_shape_invalid');
            }
            foreach ($admitted as $endpoint => $operation) {
                if (Arr::has($capability['endpoints'], $endpoint, true)) { $found[$operation] = true; }
            }
        }
        if (Arr::count($found) !== 2) { return $this->result('verification_failed', 'catalog_contract_missing'); }
        return $this->result('verified', 'admitted_catalog_observed', [
            'version' => 1, 'service' => 'keryx.read', 'read_only' => true,
            'operations' => ['directory', 'messages', 'capabilities'],
            'unavailable' => ['job', 'discussions'], 'mutations' => [],
        ]);
    }

    private function result(string $status, string $reason, array $data = []): array
    {
        return ['version' => 1, 'status' => $status, 'reason' => $reason, 'data' => $data,
            'partial' => true, 'next_page' => null];
    }
}
