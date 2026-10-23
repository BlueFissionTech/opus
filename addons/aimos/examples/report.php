<?php
declare(strict_types=1);

// Fixed synthetic observations only; no database, provider, account or effects.
$autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (!is_file($autoload)) { $autoload = dirname(__DIR__) . '/vendor/autoload.php'; }
$loader = require $autoload;
$loader->addPsr4('AddOns\\Aimos\\', dirname(__DIR__) . '/logic/');

use AddOns\Aimos\Business\ReportService;
use AddOns\Aimos\Domain\ReportingInitiative;
use App\Domain\Agents\{AgentApplicationScope, AgentRuntimeContext};
use BlueFission\Automata\Feedback\Observation;
use BlueFission\Automata\Goal\Kpi;

$output = [];
foreach (['sample_a' => 12, 'sample_b' => 3] as $tenant => $count) {
    $service = new ReportService('sample', $tenant, static function (): ReportingInitiative {
        $root = new ReportingInitiative(['initiative_id' => 'attendance']);
        $child = new ReportingInitiative(['initiative_id' => 'registration_goal']);
        $child->addKpi(new Kpi(['kpi_id' => 'registrations', 'source' => 'fixture.events', 'unit' => 'count',
            'formula' => 'sum.v1', 'period_start' => 100, 'period_end' => 200, 'max_age' => 60, 'target' => 10.0]));
        $root->addChild($child);
        return $root;
    }, static fn () => [new Observation(['timestamp' => 180, 'context' => [
        'application' => 'sample', 'tenant' => $tenant, 'metric' => 'registrations', 'source' => 'fixture.events',
        'unit' => 'count', 'value' => $count, 'confidence' => 1.0, 'quality' => 'synthetic',
    ]])], static fn () => new AgentRuntimeContext($tenant, ['id' => 'sample_operator'], ['aimos'],
        ['aimos' => 'active'], ['aimos.read'], applicationScope: new AgentApplicationScope('sample')),
        static fn () => 200);
    $output[$tenant] = $service->query('report');
}
echo json_encode(['synthetic' => true, 'reports' => $output], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
