<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Business;

use AddOns\Kwikle\Domain\{Approval, RunRequest, RunResult, Workflow};
use App\Domain\Agents\AgentRuntimeContext;
use BlueFission\Arr;
use BlueFission\DevElation;
use BlueFission\Services\Service;
use BlueFission\Str;
use BlueFission\Synematic\Interop\Hub;
use Closure;
use InvalidArgumentException;
use Throwable;

class WorkflowService extends Service
{
    private array $workflows = [];
    private array $runs = [];
    private static bool $executing = false;
    private Closure $review;

    public function __construct(array $workflows, private FixtureConnector $connector, callable $review)
    {
        parent::__construct();
        $this->review = Closure::fromCallable($review);
        foreach ($workflows as $workflow) {
            if (!$workflow instanceof Workflow) { throw new InvalidArgumentException('Invalid workflow.'); }
            $key = $this->key([$workflow->application, $workflow->tenant, $workflow->id]);
            if (Arr::hasKey($this->workflows, $key)) { throw new InvalidArgumentException('Duplicate workflow.'); }
            $this->workflows[$key] = $workflow;
        }
    }

    public function catalog(AgentRuntimeContext $context): array
    {
        $result = [];
        foreach ($this->workflows as $workflow) {
            if ($this->authorized($workflow, $context, 'kwikle.read')) { $result[] = $workflow->id; }
        }
        return $result;
    }

    public function describe(string $id, AgentRuntimeContext $context): array
    {
        $workflow = $this->find($id, $context);
        if ($workflow === null || !$this->authorized($workflow, $context, 'kwikle.read')) {
            return ['status' => 'denied'];
        }
        return ['status' => $this->supported($workflow) ? 'valid' : 'unavailable', 'workflow' => $id,
            'trigger' => $workflow->trigger->type, 'action' => $workflow->action->type,
            'client_ref' => $workflow->action->connection->client,
            'capability_ref' => $workflow->action->connection->capability,
            'mapping' => 'subject -> capture.subject', 'max_attempts' => $workflow->retry->maxAttempts,
            'approval_required' => true, 'receipt_scope' => 'service_instance',
            'definition' => $workflow->graph()->toArray()];
    }

    public function dryRun(RunRequest $request, AgentRuntimeContext $context): RunResult
    {
        $workflow = $this->find($request->workflow, $context);
        $status = $workflow !== null && $this->authorized($workflow, $context, 'kwikle.read')
            ? ($this->supported($workflow) ? 'dry_run' : 'unavailable') : 'denied';
        return new RunResult($status, 'none', $request->attempt, $context->correlationId());
    }

    public function status(string $workflow, string $event, AgentRuntimeContext $context): ?RunResult
    {
        $definition = $this->find($workflow, $context);
        if ($definition === null || !$this->authorized($definition, $context, 'kwikle.read')) { return null; }
        $receipt = $this->runs[$this->runKey($workflow, $event, $context)] ?? null;
        return $receipt !== null && $receipt['actor'] === Arr::getPath($context->actor(), 'id') ? $receipt['result'] : null;
    }

    public function run(RunRequest $request, AgentRuntimeContext $context): RunResult
    {
        $result = fn (string $status, string $effects = 'none'): RunResult =>
            new RunResult($status, $effects, $request->attempt, $context->correlationId());
        $workflow = $this->find($request->workflow, $context);
        if (self::$executing || $workflow === null || $context->correlationId() === null
            || !$this->authorized($workflow, $context, 'kwikle.execute')) { return $result('denied'); }
        if (!$this->supported($workflow)) { return $result('unavailable'); }

        // Review callbacks and observers cannot reenter execution.
        self::$executing = true;
        try {
            $approval = ($this->review)($request, $context);
            if (!$approval instanceof Approval || $approval->request !== $request
                || $approval->application !== $context->applicationId() || $approval->tenant !== $context->tenantId()
                || $approval->actor !== Arr::getPath($context->actor(), 'id')) { return $result('denied'); }
            $key = $this->runKey($request->workflow, $request->event, $context);
            $prior = $this->runs[$key] ?? null;
            if ($prior !== null) {
                if ($prior['actor'] !== Arr::getPath($context->actor(), 'id')) { return $result('denied'); }
                if ($prior['subject'] !== $request->subject) { return $result('conflict'); }
                if ($prior['result']->attempt === $request->attempt) { return $prior['result']; }
                if ($prior['result']->status !== 'failed' || $prior['result']->effects !== 'none'
                    || $request->attempt !== $prior['result']->attempt + 1) { return $result('retry_denied'); }
            } elseif ($request->attempt !== 1) { return $result('retry_denied'); }
            if ($request->attempt > $workflow->retry->maxAttempts) { return $result('retry_denied'); }
            if ($prior === null && Arr::size($this->runs) >= 1000) { return $result('capacity'); }
            if (!$this->connector->available()) { return $result('unavailable'); }

            $this->runs[$key] = ['actor' => Arr::getPath($context->actor(), 'id'), 'subject' => $request->subject,
                'result' => $result('running', 'unknown')];
            $settled = null;
            $started = false;
            $triggered = false;
            $finished = false;
            $mapped = null;
            try {
                $trigger = static function () use (&$triggered): string {
                    if ($triggered) { throw new InvalidArgumentException('Trigger already consumed.'); }
                    $triggered = true;
                    return 'triggered';
                };
                $map = static function () use ($request, &$triggered, &$mapped, &$started): string {
                    if (!$triggered || $started) { throw new InvalidArgumentException('Invalid workflow state.'); }
                    $mapped = $request->subject;
                    return 'mapped';
                };
                $capture = function () use ($key, &$mapped, &$started, &$settled, $result, $approval): string {
                    if ($started || $mapped === null) { throw new InvalidArgumentException('Invalid workflow state.'); }
                    $started = true;
                    // Native Node/Trail receive labels, never connector payloads or authority.
                    try {
                        $outcome = $this->connector->capture($key, $mapped);
                        if (($outcome['ok'] ?? null) === true && ($outcome['effects'] ?? null) === 'committed') {
                            $settled = $result('completed', 'committed');
                        } elseif (($outcome['ok'] ?? null) === false && ($outcome['effects'] ?? null) === 'none') {
                            $settled = $result('failed');
                        } elseif (($outcome['ok'] ?? null) === false && ($outcome['effects'] ?? null) === 'committed') {
                            $settled = $result('partial', 'committed');
                            if ($approval->compensation) {
                                $settled = $this->connector->compensate($key)
                                    ? $result('compensated', 'reversed') : $result('uncertain', 'unknown');
                            }
                        } else { $settled = $result('uncertain', 'unknown'); }
                    } catch (Throwable) { $settled = $result('uncertain', 'unknown'); }
                    return 'attempted';
                };
                $document = $workflow->graph();
                $graph = $document->toGraph(static function (string $reference, string $kind) use ($trigger, $map, $capture, &$mapped): callable {
                    return match ([$kind, $reference]) {
                        ['node', 'fixture.created'] => $trigger,
                        ['node', 'fixture.subject'] => $map,
                        ['node', 'fixture.capture'] => $capture,
                        ['condition', 'fixture.subject-present'] => static function () use (&$mapped): bool { return $mapped !== null && $mapped !== ''; },
                        default => throw new InvalidArgumentException('Unavailable workflow resolver.'),
                    };
                });
                $hub = new Hub();
                $hub->addWorkflow('approved', $graph);
                $hub->runWorkflow('approved', $document->entry(), null);
                $finished = true;
            } catch (Throwable) {
                // An observer failure after an effect cannot erase its receipt or permit replay.
            }
            $settled ??= $result($started ? 'uncertain' : ($finished ? 'skipped' : 'failed'), $started ? 'unknown' : 'none');
            $this->runs[$key]['result'] = $settled;
            try { DevElation::do('kwikle.run.result', [['version' => 1, 'status' => $settled->status, 'effects' => $settled->effects]]); }
            catch (Throwable) { /* Observation cannot change a settled receipt. */ }
            return $settled;
        } catch (Throwable) { return $result('denied'); }
        finally { self::$executing = false; }
    }

    private function supported(Workflow $workflow): bool
    {
        if ($workflow->trigger->type !== 'fixture.created' || $workflow->action->type !== 'fixture.capture'
            || $workflow->action->connection->client !== 'fixture'
            || $workflow->action->connection->capability !== 'fixture.capture') { return false; }
        try {
            $definition = $workflow->graph()->toArray();
            if ($definition['mode'] !== 'first' || $definition['max_steps'] > 16
                || Arr::size($definition['nodes']) > 16 || Arr::size($definition['edges']) > 32) { return false; }
            $captures = 0;
            foreach ($definition['nodes'] as $node) {
                if (!Arr::has(['fixture.created', 'fixture.subject', 'fixture.capture'], $node['resolver'], true)) { return false; }
                if ($node['resolver'] === 'fixture.capture') { $captures++; }
            }
            foreach ($definition['edges'] as $edge) {
                if (!Arr::has(['', 'fixture.subject-present'], $edge['condition_ref'] ?? '', true)) { return false; }
            }
            // A native graph is not permission to multiply the approved fixture effect.
            return $captures === 1;
        } catch (Throwable) { return false; }
    }

    private function find(string $id, AgentRuntimeContext $context): ?Workflow
    {
        return $this->workflows[$this->key([$context->applicationId(), $context->tenantId(), $id])] ?? null;
    }

    private function authorized(Workflow $workflow, AgentRuntimeContext $context, string $capability): bool
    {
        $actor = Arr::getPath($context->actor(), 'id');
        return Str::is($actor) && Str::isNotEmpty(Str::trim($actor))
            && $workflow->application === $context->applicationId() && $workflow->tenant === $context->tenantId()
            && Arr::has($context->activeAddOns(), 'kwikle', true)
            && ($context->lifecycleStates()['kwikle'] ?? null) === 'active'
            && Arr::has($context->capabilities(), $capability, true);
    }

    private function runKey(string $workflow, string $event, AgentRuntimeContext $context): string
    {
        return $this->key([$context->applicationId(), $context->tenantId(), $workflow, $event]);
    }

    // Authority keys must not be changed by application serialization filters.
    private function key(array $parts): string { return json_encode($parts, JSON_THROW_ON_ERROR); }
}
