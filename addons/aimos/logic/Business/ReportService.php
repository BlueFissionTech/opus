<?php
declare(strict_types=1);
namespace AddOns\Aimos\Business;

use AddOns\Aimos\Domain\ReportingInitiative;
use App\Domain\Agents\AgentRuntimeContext;
use BlueFission\Arr;
use BlueFission\Automata\Feedback\Observation;
use BlueFission\Automata\Goal\{Condition, GoalManager, Kpi, Task};
use BlueFission\Num;
use BlueFission\Services\{Application, Service};
use BlueFission\Str;
use Closure;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class ReportService extends Service
{
    private bool $reading = false;

    public function __construct(
        private readonly string $application,
        private readonly string $tenant,
        private readonly Closure $tree,
        private readonly Closure $observations,
        private readonly Closure $context,
        private readonly Closure $clock
    ) {
        parent::__construct();
        if ($application === '' || $tenant === '') { throw new InvalidArgumentException('aimos_scope_invalid'); }
    }

    public function registerWith(Application $app): void
    {
        $app->delegate('aimos', $this);
        $app->register('aimos', 'get', function ($behavior, array $args): void {
            $result = Arr::count($args) === 1 && Str::is($args[0] ?? null)
                ? $this->query($args[0]) : $this->result('denied', 'arguments_invalid');
            $this->_response = json_encode($result, JSON_THROW_ON_ERROR);
        });
    }

    public function query(string $operation): array
    {
        if ($this->reading) { return $this->result('denied', 'reentry_denied'); }
        $this->reading = true;
        try {
            $ctx = $this->authorized();
            if ($ctx === null) { return $this->result('denied', 'scope_denied'); }
            $actor = $ctx->actor()['id'];
            if (Arr::has(['activate', 'set-target'], $operation, true)) {
                return Arr::has($ctx->capabilities(), 'aimos.manage', true)
                    ? $this->result('unavailable', 'durable_effect_contract_required')
                    : $this->result('denied', 'mutation_denied');
            }
            if (!Arr::has(['discover', 'status', 'explain', 'report'], $operation, true)) {
                return $this->result('denied', 'operation_denied');
            }
            $root = ($this->tree)();
            if (!$root instanceof ReportingInitiative || $root->parent() !== null) {
                throw new InvalidArgumentException('tree_invalid');
            }
            [$nodes, $specs, $triggers] = $this->compile($root);
            $this->requireSameActor($actor);
            if ($operation === 'discover') {
                return $this->result('completed', 'catalog', [
                    'goals' => Arr::keys($nodes), 'metrics' => Arr::keys($specs),
                    'operations' => ['discover', 'status', 'explain', 'report'], 'triggers' => $triggers,
                ]);
            }
            $now = ($this->clock)();
            if (!is_int($now) || $now < 0) { throw new InvalidArgumentException('clock_invalid'); }
            $metrics = [];
            foreach ($specs as $id => $spec) {
                $this->requireSameActor($actor);
                // A fresh native record prevents an adapter from changing report policy.
                $rows = ($this->observations)(new Kpi($spec), $ctx);
                $this->requireSameActor($actor);
                $metrics[$id] = $this->metric($spec, $rows, $now);
            }
            $goals = [];
            $known = ['metrics' => [], 'goals' => []];
            foreach ($metrics as $id => $metric) {
                if ($metric['freshness'] === 'current') { $known['metrics'][$id] = $metric['value']; }
            }
            // Child-first report composition; Automata owns every satisfaction decision.
            foreach (Arr::make($nodes)->reverse()->toArray() as $id => $node) {
                $incomplete = false;
                $metricIds = [];
                foreach ($node->kpis() as $kpi) {
                    $metricId = $kpi->field('kpi_id');
                    $metricIds[] = $metricId;
                    $incomplete = $incomplete || $metrics[$metricId]['freshness'] !== 'current';
                    $node->addCondition(new Condition(['type' => $metricId, 'path' => 'metrics.' . $metricId,
                        'operator' => 'gte', 'value' => $kpi->field('target'), 'expected' => $kpi->field('target')]));
                }
                $children = [];
                foreach ($node->children() as $child) {
                    $childId = $child->field('initiative_id');
                    $children[] = $childId;
                    $incomplete = $incomplete || $goals[$childId]['status'] === 'indeterminate';
                    $node->addCondition(new Condition(['type' => 'child_' . $childId, 'path' => 'goals.' . $childId,
                        'operator' => 'eq', 'value' => true, 'expected' => true]));
                }
                $manager = new GoalManager([$node]);
                $manager->updateCriteriaSatisfied($known);
                $satisfied = Arr::isNotEmpty($node->conditions());
                foreach ($node->conditions() as $condition) {
                    $satisfied = $satisfied && $condition->field('is_satisfied') === true;
                }
                $status = $incomplete || !Arr::isNotEmpty($node->conditions()) ? 'indeterminate' : ($satisfied ? 'achieved' : 'pending');
                $known['goals'][$id] = $status === 'achieved';
                $goals[$id] = ['id' => $id, 'parent' => $node->parent()?->field('initiative_id'),
                    'children' => $children, 'metrics' => $metricIds, 'status' => $status,
                    'evaluation' => 'automata.all-conditions-and-children'];
            }
            $this->requireSameActor($actor);
            return $this->result('completed', 'report', ['as_of' => $now, 'goals' => $goals, 'metrics' => $metrics, 'triggers' => $triggers]);
        } catch (DomainException) {
            return $this->result('denied', 'scope_changed_or_mismatched');
        } catch (InvalidArgumentException) {
            return $this->result('invalid', 'report_input_invalid');
        } catch (Throwable) {
            return $this->result('unavailable', 'report_unavailable');
        } finally {
            $this->reading = false;
        }
    }

    /** Validate and take a fresh native graph snapshot; never mutate the host graph. */
    private function compile(ReportingInitiative $root): array
    {
        $objects = [];
        $nodes = [];
        $specs = [];
        $triggers = [];
        $walk = function (ReportingInitiative $source, ?ReportingInitiative $parent = null, ?ReportingInitiative $sourceParent = null) use (&$walk, &$objects, &$nodes, &$specs, &$triggers): ReportingInitiative {
            $id = $source->field('initiative_id');
            if (!$this->identifier($id) || Arr::hasKey($nodes, $id) || Arr::has($objects, spl_object_id($source), true)
                || Arr::count($nodes) >= 32 || $source->parent() !== $sourceParent || $source->criteria() !== []) {
                throw new InvalidArgumentException('tree_invalid');
            }
            $objects[] = spl_object_id($source);
            $node = new ReportingInitiative(['initiative_id' => $id]);
            $nodes[$id] = $node;
            if ($parent !== null) { $parent->addChild($node); }
            foreach ($source->kpis() as $kpi) {
                if (!$kpi instanceof Kpi || Arr::count($specs) >= 32) { throw new InvalidArgumentException('kpi_invalid'); }
                $spec = $this->spec($kpi);
                $metricId = $spec['kpi_id'];
                if (Arr::hasKey($specs, $metricId)) { throw new InvalidArgumentException('kpi_conflict'); }
                $specs[$metricId] = $spec;
                $node->addKpi(new Kpi($spec));
            }
            foreach ($source->tasks() as $task) {
                if (!$task instanceof Task || !$this->identifier($task->field('task_id')) || Arr::count($triggers) >= 32
                    || $task->field('enabled') !== false) { throw new InvalidArgumentException('trigger_invalid'); }
                $triggers[] = ['id' => $task->field('task_id'), 'goal' => $id, 'status' => 'unavailable', 'reason' => 'durable_effect_contract_required'];
            }
            foreach ($source->children() as $child) {
                if (!$child instanceof ReportingInitiative) { throw new InvalidArgumentException('child_invalid'); }
                $walk($child, $node, $source);
            }
            return $node;
        };
        $walk($root);
        return [$nodes, $specs, $triggers];
    }

    private function spec(Kpi $kpi): array
    {
        $spec = [];
        foreach (['kpi_id', 'source', 'unit', 'formula', 'period_start', 'period_end', 'max_age', 'target'] as $key) { $spec[$key] = $kpi->field($key); }
        if (!$this->identifier($spec['kpi_id']) || !$this->identifier($spec['unit'])
            || !Str::is($spec['source']) || !Str::make($spec['source'])->matches('/^[a-z0-9._-]{1,80}$/D')
            || $spec['formula'] !== 'sum.v1' || !is_int($spec['period_start']) || !is_int($spec['period_end'])
            || $spec['period_start'] < 0 || $spec['period_end'] <= $spec['period_start']
            || !is_int($spec['max_age']) || $spec['max_age'] < 1 || $spec['max_age'] > 86400
            || !$this->number($spec['target'])) { throw new InvalidArgumentException('kpi_definition_invalid'); }
        return $spec;
    }

    private function metric(array $spec, mixed $rows, int $now): array
    {
        if (!Arr::is($rows) || !array_is_list($rows) || Arr::count($rows) > 1000) { throw new InvalidArgumentException('observations_invalid'); }
        $total = Num::make(0.0);
        $count = 0;
        $fresh = true;
        $confidence = 1.0;
        $quality = 'verified';
        foreach ($rows as $row) {
            if (!$row instanceof Observation) { throw new InvalidArgumentException('observation_invalid'); }
            $data = $row->context()->all();
            if (($data['application'] ?? null) !== $this->application || ($data['tenant'] ?? null) !== $this->tenant) { throw new DomainException('observation_scope_denied'); }
            if (($data['metric'] ?? null) !== $spec['kpi_id'] || ($data['source'] ?? null) !== $spec['source']
                || ($data['unit'] ?? null) !== $spec['unit'] || !$this->number($data['value'] ?? null)
                || !$this->number($data['confidence'] ?? null) || $data['confidence'] < 0 || $data['confidence'] > 1
                || !Arr::has(['synthetic', 'verified'], $data['quality'] ?? null, true)) { throw new InvalidArgumentException('observation_quality_invalid'); }
            $stamp = $row->timestamp();
            if (!is_finite($stamp) || $stamp < 0 || $stamp > $now) { throw new InvalidArgumentException('observation_time_invalid'); }
            if ($stamp < $spec['period_start'] || $stamp >= $spec['period_end']) { continue; }
            $count++;
            $total->add($data['value']);
            if (!$this->number($total->val())) { throw new InvalidArgumentException('aggregate_overflow'); }
            $fresh = $fresh && $now - $stamp <= $spec['max_age'];
            $confidence = (float) Num::min($confidence, $data['confidence']);
            if ($data['quality'] === 'synthetic') { $quality = 'synthetic'; }
        }
        $state = $count === 0 ? 'missing' : ($fresh ? 'current' : 'stale');
        return ['id' => $spec['kpi_id'], 'source' => $spec['source'], 'period' => [$spec['period_start'], $spec['period_end']],
            'unit' => $spec['unit'], 'formula' => $spec['formula'], 'target' => (float) $spec['target'],
            'freshness' => $state, 'quality' => $state === 'current' ? $quality : 'unqualified',
            'confidence' => $state === 'current' ? $confidence : 0.0, 'observations' => $count,
            'value' => $state === 'current' ? (float) $total->val() : null];
    }

    private function authorized(): ?AgentRuntimeContext
    {
        $ctx = ($this->context)();
        if (!$ctx instanceof AgentRuntimeContext || $ctx->applicationId() !== $this->application || $ctx->tenantId() !== $this->tenant
            || !Arr::has($ctx->activeAddOns(), 'aimos', true) || ($ctx->lifecycleStates()['aimos'] ?? null) !== 'active'
            || !Arr::has($ctx->capabilities(), 'aimos.read', true) || !Str::is($ctx->actor()['id'] ?? null)
            || Str::isEmpty(Str::trim($ctx->actor()['id']))) { return null; }
        return $ctx;
    }

    private function requireSameActor(string $id): void
    {
        if (($this->authorized()?->actor()['id'] ?? null) !== $id) { throw new DomainException('authority_changed'); }
    }

    private function identifier(mixed $value): bool { return Str::is($value) && Str::make($value)->matches('/^[a-z][a-z0-9_]{0,63}$/D'); }
    private function number(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float) $value); }
    private function result(string $status, string $reason, array $report = []): array { return ['version' => 1, 'status' => $status, 'reason' => $reason, 'report' => $report]; }
}
