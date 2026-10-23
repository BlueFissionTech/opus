<?php
declare(strict_types=1);
namespace AddOns\Aimos\Tests;

use AddOns\Aimos\Business\ReportService;
use AddOns\Aimos\Domain\ReportingInitiative;
use App\Business\Services\AddOnContractValidator;
use App\Domain\Agents\{AgentApplicationScope, AgentRuntimeContext};
use BlueFission\Automata\Feedback\Observation;
use BlueFission\Automata\Goal\{Kpi, Task};
use BlueFission\Data\Storage\Storage;
use BlueFission\Services\Application;
use BlueFission\Wise\Cmd\CommandProcessor;
use PHPUnit\Framework\TestCase;

class ReportServiceTest extends TestCase
{
    private function context(string $tenant = 'tenant', array $caps = ['aimos.read'], array $active = ['aimos']): AgentRuntimeContext
    {
        return new AgentRuntimeContext($tenant, ['id' => 'operator'], $active, ['aimos' => 'active'], $caps,
            applicationScope: new AgentApplicationScope('app'));
    }

    private function kpi(float $target = 10): Kpi
    {
        return new Kpi(['kpi_id' => 'registrations', 'source' => 'fixture.events', 'unit' => 'count',
            'formula' => 'sum.v1', 'period_start' => 100, 'period_end' => 200, 'max_age' => 60, 'target' => $target]);
    }

    private function tree(): ReportingInitiative
    {
        $root = new ReportingInitiative(['initiative_id' => 'event', 'name' => 'Event attendance']);
        $child = new ReportingInitiative(['initiative_id' => 'outreach', 'name' => 'Registrations']);
        $child->addKpi($this->kpi());
        $child->addTask(new Task(['task_id' => 'notify', 'enabled' => false]));
        $root->addChild($child);
        return $root;
    }

    private function observation(float $value = 12, float $timestamp = 180, string $tenant = 'tenant'): Observation
    {
        return new Observation(['timestamp' => $timestamp, 'context' => [
            'application' => 'app', 'tenant' => $tenant, 'metric' => 'registrations',
            'source' => 'fixture.events', 'unit' => 'count', 'value' => $value,
            'confidence' => 1.0, 'quality' => 'synthetic', 'private' => 'private-token',
        ]]);
    }

    private function service(?\Closure $source = null, ?\Closure $tree = null, ?\Closure $context = null): ReportService
    {
        return new ReportService('app', 'tenant', $tree ?? fn () => $this->tree(),
            $source ?? fn () => [$this->observation()], $context ?? fn () => $this->context(), static fn () => 200);
    }

    public function testNativeGoalTreeCascadesAndReportIsDeterministic(): void
    {
        $service = $this->service();
        $result = $service->query('report');
        self::assertSame('completed', $result['status']);
        self::assertSame('achieved', $result['report']['goals']['event']['status']);
        self::assertSame('achieved', $result['report']['goals']['outreach']['status']);
        self::assertSame(['registrations'], $result['report']['goals']['outreach']['metrics']);
        $metric = $result['report']['metrics']['registrations'];
        self::assertSame(12.0, $metric['value']);
        self::assertSame('sum.v1', $metric['formula']);
        self::assertSame([100, 200], $metric['period']);
        self::assertSame('synthetic', $metric['quality']);
        self::assertSame(1.0, $metric['confidence']);
        self::assertSame($result, $service->query('report'));
        self::assertStringNotContainsString('private', json_encode($result));
    }

    public function testRecalculationDoesNotLatchPastSatisfaction(): void
    {
        $value = 12;
        $tree = $this->tree();
        $service = $this->service(function () use (&$value) { return [$this->observation($value)]; }, fn () => $tree);
        self::assertSame('achieved', $service->query('status')['report']['goals']['event']['status']);
        $value = 3;
        self::assertSame('pending', $service->query('status')['report']['goals']['event']['status']);
    }

    public function testMissingStaleAndOutOfPeriodAreNotSatisfied(): void
    {
        foreach ([['missing', []], ['stale', [$this->observation(timestamp: 110)]], ['missing', [$this->observation(timestamp: 200)]]] as [$state, $rows]) {
            $result = $this->service(fn () => $rows)->query('report');
            self::assertSame($state, $result['report']['metrics']['registrations']['freshness']);
            self::assertNull($result['report']['metrics']['registrations']['value']);
            self::assertSame('indeterminate', $result['report']['goals']['event']['status']);
        }
    }

    public function testMixedTenantObservationsAreDeniedWithoutPayload(): void
    {
        $result = $this->service(fn () => [$this->observation(999, tenant: 'foreign')])->query('report');
        self::assertSame('denied', $result['status']);
        self::assertSame([], $result['report']);
    }

    public function testDenialAndDiscoveryDoNotInvokeSource(): void
    {
        $calls = 0;
        $source = function () use (&$calls) { $calls++; return []; };
        foreach ([$this->context('foreign'), $this->context(caps: []), $this->context(active: [])] as $ctx) {
            self::assertSame('denied', $this->service($source, context: fn () => $ctx)->query('report')['status']);
        }
        self::assertSame('completed', $this->service($source)->query('discover')['status']);
        self::assertSame(0, $calls);
    }

    public function testCyclesSharedNodesAndConflictingTargetsFailBeforeSource(): void
    {
        foreach (['cycle', 'shared', 'conflict'] as $case) {
            $root = $this->tree();
            if ($case === 'cycle') { $root->children()[0]->addChild($root); }
            if ($case === 'shared') { $other = new ReportingInitiative(['initiative_id' => 'other']); $other->addChild($root->children()[0]); $root->addChild($other); }
            if ($case === 'conflict') { $root->addKpi($this->kpi(20)); }
            $calls = 0;
            $service = $this->service(function () use (&$calls) { $calls++; return []; }, fn () => $root);
            self::assertSame('invalid', $service->query('report')['status']);
            self::assertSame(0, $calls);
        }
    }

    public function testRevocationDuringObservationDiscardsReport(): void
    {
        $ctx = $this->context();
        $service = $this->service(function () use (&$ctx) { $ctx = $this->context(caps: []); return [$this->observation()]; },
            context: function () use (&$ctx) { return $ctx; });
        self::assertSame('denied', $service->query('report')['status']);
    }

    public function testTargetsAndTriggerEffectsAreNotEnabledByReads(): void
    {
        $service = $this->service();
        self::assertSame('denied', $service->query('activate')['status']);
        self::assertSame('denied', $service->query('set-target')['status']);
        $privileged = $this->service(context: fn () => $this->context(caps: ['aimos.read', 'aimos.manage']));
        self::assertSame('unavailable', $privileged->query('activate')['status']);
        $result = $service->query('report');
        self::assertSame('unavailable', $result['report']['triggers'][0]['status']);
    }

    public function testPackageIsPassiveAndValid(): void
    {
        $package = dirname(__DIR__);
        self::assertTrue((new AddOnContractValidator())->validate($package)['valid']);
        $factory = require $package . '/main.php';
        self::assertIsArray($factory()->contributions());
    }

    public function testZeroIsAnObservationAndPeriodEndIsExclusive(): void
    {
        $result = $this->service(fn () => [$this->observation(0), $this->observation(999, 200)])->query('report');
        self::assertSame(0.0, $result['report']['metrics']['registrations']['value']);
        self::assertSame(1, $result['report']['metrics']['registrations']['observations']);
        self::assertSame('pending', $result['report']['goals']['event']['status']);
    }

    public function testNonFiniteWrongUnitLowQualityAndFutureDataFailClosed(): void
    {
        foreach (['nonfinite', 'unit', 'quality', 'future', 'source', 'confidence', 'string'] as $case) {
            $row = $this->observation();
            if ($case === 'nonfinite') { $row->context()->set('value', INF); }
            if ($case === 'unit') { $row->context()->set('unit', 'money'); }
            if ($case === 'quality') { $row->context()->set('quality', 'unverified'); }
            if ($case === 'future') { $row->field('timestamp', 201); }
            if ($case === 'source') { $row->context()->set('source', 'unreviewed'); }
            if ($case === 'confidence') { $row->context()->set('confidence', 1.1); }
            if ($case === 'string') { $row->context()->set('value', '12'); }
            $result = $this->service(fn () => [$row])->query('report');
            self::assertSame('invalid', $result['status'], $case);
            self::assertSame([], $result['report']);
        }
    }

    public function testObservationFailureAndOversizeDoNotLeakOrProducePartialSuccess(): void
    {
        $result = $this->service(fn () => throw new \RuntimeException('private-token'))->query('report');
        self::assertSame('unavailable', $result['status']);
        self::assertStringNotContainsString('private', json_encode($result));
        self::assertSame('invalid', $this->service(fn () => array_fill(0, 1001, $this->observation()))->query('report')['status']);
    }

    public function testNativeTriggerIsNeverActivatedAndUnknownFormulaIsRejected(): void
    {
        foreach (['enabled', 'formula', 'period', 'target'] as $case) {
            $tree = $this->tree();
            $child = $tree->children()[0];
            if ($case === 'enabled') { $child->tasks()[0]->field('enabled', true); }
            if ($case === 'formula') { $child->kpis()[0]->field('formula', 'eval.v1'); }
            if ($case === 'period') { $child->kpis()[0]->field('period_end', 100); }
            if ($case === 'target') { $child->kpis()[0]->field('target', NAN); }
            self::assertSame('invalid', $this->service(tree: fn () => $tree)->query('report')['status']);
        }
    }

    public function testNativeInputsAreNotMutatedByRepeatedReports(): void
    {
        $tree = $this->tree();
        $service = $this->service(tree: fn () => $tree);
        $service->query('report');
        $service->query('explain');
        self::assertSame([], $tree->criteria());
        self::assertSame([], $tree->children()[0]->criteria());
        self::assertFalse($tree->children()[0]->tasks()[0]->field('enabled'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testApplicationResolvesAndWiseDispatchesNativeService(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        $ctx = $this->context();
        $app = Application::instance();
        $app->bindArgs(['application' => 'app', 'tenant' => 'tenant', 'tree' => fn () => $this->tree(),
            'observations' => fn () => [$this->observation()], 'context' => function () use (&$ctx) { return $ctx; },
            'clock' => static fn () => 200], ReportService::class);
        $service = $app->resolve(ReportService::class);
        self::assertInstanceOf(ReportService::class, $service);
        $service->registerWith($app);
        $wise = new CommandProcessor($this->createMock(Storage::class));
        self::assertContains('get aimos', $wise->availableCommands());
        self::assertSame('completed', json_decode($wise->process('get aimos "report"')->output(), true)['status']);
        $ctx = $this->context(caps: []);
        self::assertSame('denied', json_decode($wise->process('get aimos "status"')->output(), true)['status']);
    }
}
