<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Business;

use AddOns\Kwikle\Domain\{Approval, RunRequest, RunResult, Workflow};
use App\Domain\Agents\AgentRuntimeContext;
use BlueFission\Arr;
use BlueFission\DevElation;
use BlueFission\Services\Service;
use BlueFission\Str;
use BlueFission\Synematic\Workflow\Pipeline;
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
            'approval_required' => true, 'receipt_scope' => 'service_instance'];
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
            $mapped = null;
            try {
                $pipeline = new Pipeline(0);
                $pipeline->context([]);
                $pipeline->addStep(function () use ($request, &$mapped): string {
                    $mapped = $request->subject;
                    return 'mapped';
                }, 'map');
                $pipeline->addStep(function () use ($key, &$mapped, &$started, &$settled, $result, $approval): string {
                    if ($started || $mapped === null) { throw new InvalidArgumentException('Invalid workflow state.'); }
                    $started = true;
                    // Do not expose connector exceptions, returned data or authority to Pipeline observers.
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
                }, 'capture');
                $pipeline->run(null);
            } catch (Throwable) {
                // An observer failure after an effect cannot erase its receipt or permit replay.
            }
            $settled ??= $result($started ? 'uncertain' : 'failed', $started ? 'unknown' : 'none');
            $this->runs[$key]['result'] = $settled;
            try { DevElation::do('kwikle.run.result', [['version' => 1, 'status' => $settled->status, 'effects' => $settled->effects]]); }
            catch (Throwable) { /* Observation cannot change a settled receipt. */ }
            return $settled;
        } catch (Throwable) { return $result('denied'); }
        finally { self::$executing = false; }
    }

    private function supported(Workflow $workflow): bool
    {
        return $workflow->trigger->type === 'fixture.created' && $workflow->action->type === 'fixture.capture'
            && $workflow->action->connection->client === 'fixture';
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
