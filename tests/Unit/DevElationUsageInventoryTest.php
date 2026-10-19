<?php

declare(strict_types=1);

namespace Tests\Unit;

use Opus\Tools\DevElationUsageInventory;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/DevElationUsageInventory.php';

final class DevElationUsageInventoryTest extends TestCase
{
    public function testImportsAliasesAndDirectChainsRemainSourceEvidenceOnly(): void
    {
        $source = <<<'PHP'
<?php
namespace Example;
use BlueFission\{Arr as Values, Str};
use BlueFission\Data\FileSystem;
use BlueFission\Services\Service;
use BlueFission\Behavioral\Behaviors\Event;
use BlueFission\DevElation as Hooks;
final class Probe extends Service {
    private function strings(Values $input): array {
        $quoted = 'Str::make("not a reference")->trim()';
        // Values::make([])->map('not a reference');
        Hooks::do('example.event', [Event::CHANGE]);
        return Values::make([Str::make(' value ')->trim()->val()])
            ->map(fn ($value) => $value)->values()->val();
    }
}
throw new \RuntimeException('Source must never execute');
PHP;
        $report = (new DevElationUsageInventory())->inspect($source, 'app/Probe.php');
        $this->assertSame(hash('sha256', $source), $report['sha256']);
        $this->assertSame(['make', 'map', 'values', 'val'], $report['fluent_chains'][0]['methods']);
        $this->assertSame(['make', 'trim', 'val'], $report['fluent_chains'][1]['methods']);
        $this->assertSame('BlueFission\\Arr', $report['fluent_chains'][0]['symbol']);
        $this->assertSame(['Probe', 'strings'], array_column($report['declarations'], 'name'));
        $this->assertSame(['import', 'type', 'static_access'], array_column(array_values(array_filter(
            $report['references'], static fn (array $reference): bool => $reference['family'] === 'Arr'
        )), 'kind'));
        $hooks = array_values(array_filter($report['references'], static fn (array $reference): bool =>
            $reference['family'] === 'Hooks' && $reference['kind'] === 'static_access'));
        $this->assertSame('do', $hooks[0]['member']);
        $this->assertSame(12, $hooks[0]['line']);
        $this->assertContains('Behavior', array_column($report['references'], 'family'));
        $this->assertContains('inheritance', array_column($report['references'], 'kind'));
    }

    public function testNamespacesFunctionImportsAndClosureUsesCannotForgeAliases(): void
    {
        $source = <<<'PHP'
<?php
namespace First {
    use BlueFission\Arr as Values;
    use BlueFission\{Str, function trim as helper, const SAMPLE};
    $variable = 'value';
    $closure = function () use ($variable) { return "{$variable}"; };
    Values::make([])->val();
    \BlueFission\Num::make(1)->add(2)->val();
}
namespace Second {
    use function BlueFission\Arr;
    use const BlueFission\Str;
    class Values { public static function make() {} }
    Values::make();
    Arr();
}
namespace BlueFission {
    new namespace\Obj();
    Str::make('ok');
}
PHP;
        $report = (new DevElationUsageInventory())->inspect($source, 'app/Example.php');
        $static = array_values(array_filter($report['references'], static fn (array $reference): bool =>
            $reference['kind'] === 'static_access'));
        $this->assertSame(['BlueFission\\Arr', 'BlueFission\\Num', 'BlueFission\\Str'], array_column($static, 'symbol'));
        $this->assertCount(2, $report['fluent_chains']);
        $this->assertContains('Obj', array_column($report['references'], 'family'));
        $this->assertSame(['Values', 'make'], array_column($report['declarations'], 'name'));
    }

    public function testModuleInventoryIncludesUnusedFamiliesAndFilesWithoutReferences(): void
    {
        $root = $this->fixture();
        try {
            file_put_contents($root . '/app/First.php', '<?php use BlueFission\\Flag; Flag::make(true);');
            file_put_contents($root . '/common/plain.php', '<?php return [];');
            file_put_contents($root . '/mapping/map.php', '<?php use BlueFission\\Date;');
            $inventory = new DevElationUsageInventory();
            $report = $inventory->audit($root);
            $this->assertSame($report, $inventory->audit($root));
            $this->assertTrue($report['complete']);
            $this->assertSame(3, $report['file_count']);
            $this->assertSame(['app/First.php', 'common/plain.php', 'mapping/map.php'], array_column($report['files'], 'path'));
            $this->assertSame(2, $report['modules']['app']['references']['Flag']);
            $this->assertSame(0, $report['modules']['app']['references']['Val']);
            $this->assertSame(1, $report['modules']['common']['files']);
            $this->assertSame(0, array_sum($report['modules']['common']['references']));
            $this->assertSame('lexical_source_only', $report['evidence']);
            $this->assertStringNotContainsString($root, json_encode($report));
        } finally {
            $this->removeFixture($root);
        }
    }

    public function testIncompleteSourceCannotProduceACompleteReport(): void
    {
        $root = $this->fixture();
        try {
            file_put_contents($root . '/app/Broken.php', '<?php private-secret invalid syntax');
            rmdir($root . '/mapping');
            $report = (new DevElationUsageInventory())->audit($root);
            $this->assertFalse($report['complete']);
            $this->assertSame([
                ['path' => 'app/Broken.php', 'reason' => 'source_parse_failed'],
                ['path' => 'mapping', 'reason' => 'source_directory_unavailable'],
            ], $report['errors']);
            $this->assertStringNotContainsString('private-secret', json_encode($report));
        } finally {
            $this->removeFixture($root);
        }
    }

    public function testEveryPrimitiveFamilyAndHookMemberIsDistinguished(): void
    {
        $source = '<?php ';
        foreach (['Val', 'Str', 'Arr', 'Num', 'Flag', 'Date', 'Obj'] as $primitive) {
            $source .= '\\BlueFission\\' . $primitive . '::make(null);';
        }
        $source .= '\\BlueFission\\DevElation::filter("x", fn ($v) => $v);';
        $report = (new DevElationUsageInventory())->inspect($source, 'app/Values.php');
        $this->assertSame(['Val', 'Str', 'Arr', 'Num', 'Flag', 'Date', 'Obj', 'Hooks'], array_column($report['references'], 'family'));
        $this->assertSame('filter', $report['references'][7]['member']);
    }

    public function testBehaviorCandidatesDoNotClaimReceiverTypesOrIncludePayloads(): void
    {
        $source = <<<'PHP'
<?php
$unknown->behavior('private event', function () {});
$unknown?->dispatch('private payload');
$unknown->perform();
$text = '$unknown->halt("not code")';
// $unknown->when('not code');
PHP;
        $report = (new DevElationUsageInventory())->inspect($source, 'app/Events.php');
        $this->assertSame(['behavior', 'dispatch', 'perform'], array_column($report['behavior_candidates'], 'method'));
        $this->assertSame(['unresolved'], array_values(array_unique(array_column($report['behavior_candidates'], 'receiver'))));
        $this->assertSame([], $report['references']);
        $this->assertStringNotContainsString('private', json_encode($report));
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir() . '/opus-usage-' . bin2hex(random_bytes(8));
        mkdir($root);
        foreach (DevElationUsageInventory::ROOTS as $directory) {
            mkdir($root . '/' . $directory);
        }
        return $root;
    }

    public function testStandaloneCommandDoesNotExecuteSourceAndSignalsIncompleteScans(): void
    {
        $root = $this->fixture();
        try {
            mkdir($root . '/bin');
            mkdir($root . '/tools');
            copy(dirname(__DIR__, 2) . '/bin/audit-develation-usage.php', $root . '/bin/audit-develation-usage.php');
            copy(dirname(__DIR__, 2) . '/tools/DevElationUsageInventory.php', $root . '/tools/DevElationUsageInventory.php');
            file_put_contents($root . '/app/Probe.php', '<?php throw new RuntimeException("must not execute");');
            [$exit, $report, $errors] = $this->command($root);
            $this->assertSame(0, $exit, $errors . json_encode($report));
            $this->assertSame('', $errors);
            $this->assertTrue($report['complete']);
            $this->assertSame(1, $report['file_count']);
            $this->assertArrayNotHasKey('files', $report);
            file_put_contents($root . '/app/Probe.php', '<?php invalid private-source');
            [$exit, $report, $errors] = $this->command($root);
            $this->assertSame(1, $exit, $errors);
            $this->assertFalse($report['complete']);
            $this->assertSame('source_parse_failed', $report['errors'][0]['reason']);
            $this->assertStringNotContainsString('private-source', json_encode($report));
        } finally {
            $this->removeFixture($root);
        }
    }

    private function command(string $root): array
    {
        $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=',
            $root . '/bin/audit-develation-usage.php', '--summary'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), json_decode($output, true, flags: JSON_THROW_ON_ERROR), $errors];
    }

    private function removeFixture(string $root): void
    {
        foreach ([...DevElationUsageInventory::ROOTS, 'bin', 'tools'] as $directory) {
            foreach (glob($root . '/' . $directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($root . '/' . $directory)) {
                rmdir($root . '/' . $directory);
            }
        }
        rmdir($root);
    }
}
