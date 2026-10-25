<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\VibeGenerationService;
use BlueFission\DevElation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class VibeGenerationObservationTest extends TestCase
{
    private const HOOK = 'opus.generation.result';
    private array $saved = [];
    private string $workspace;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(DevElation::class);
        foreach (['_isActive', '_actions', '_filters'] as $name) {
            $property = $reflection->getProperty($name);
            $this->saved[$name] = $property->getValue();
            $property->setValue(null, $name === '_isActive' ? true : []);
        }
        $this->workspace = sys_get_temp_dir() . '/opus-observation-' . bin2hex(random_bytes(8));
        mkdir($this->workspace);
        file_put_contents($this->workspace . '/private.vibe', 'Private {$value}');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            (new ReflectionClass(DevElation::class))->getProperty($name)->setValue(null, $value);
        }
        foreach (glob($this->workspace . '/*') as $file) { unlink($file); }
        rmdir($this->workspace);
    }

    private function service(): VibeGenerationService
    {
        return new VibeGenerationService(workspace: $this->workspace);
    }

    private function summary(string $operation, bool $valid, string $phase = 'returned'): array
    {
        return ['version' => 1, 'operation' => $operation, 'valid' => $valid, 'phase' => $phase];
    }

    public function testNativeRenderingAndWritingPublishOnlyBoundedSettledSummaries(): void
    {
        $seen = [];
        $writtenAtObservation = null;
        DevElation::action(self::HOOK, function (array $summary) use (&$seen, &$writtenAtObservation): void {
            $seen[] = $summary;
            if ($summary['operation'] === 'writeRenderedFile') {
                $writtenAtObservation = file_get_contents($this->workspace . '/result.txt');
            }
        });
        $result = $this->service()->writeRenderedFile($this->workspace . '/private.vibe', 'result.txt', ['value' => 'tenant-secret']);
        $this->assertTrue($result['valid']);
        $this->assertSame('Private tenant-secret', $writtenAtObservation);
        $this->assertSame('Private tenant-secret', $result['output']);
        $this->assertSame([
            $this->summary('validateSource', true), $this->summary('renderSource', true),
            $this->summary('renderFile', true), $this->summary('writeRenderedFile', true),
        ], $seen);
        $this->assertStringNotContainsString('Private', json_encode($seen));
        $this->assertStringNotContainsString('tenant-secret', json_encode($seen));
        $this->assertStringNotContainsString($this->workspace, json_encode($seen));
    }

    public function testInvalidSyntaxMissingFileAndRejectedWriteRetainTheirResults(): void
    {
        $service = $this->service();
        $seen = [];
        DevElation::action(self::HOOK, static function (array $summary) use (&$seen): void { $seen[] = $summary; });
        $this->assertFalse($service->validateSource('{#if private}')['valid']);
        $this->assertFalse($service->validateFile($this->workspace . '/missing')['valid']);
        $this->assertFalse($service->renderFile($this->workspace . '/missing')['valid']);
        $this->assertFalse($service->writeRenderedFile($this->workspace . '/private.vibe', '../outside', ['value' => 'secret'])['valid']);
        $this->assertSame($this->summary('validateSource', false), $seen[0]);
        $this->assertSame($this->summary('validateFile', false), $seen[1]);
        $this->assertSame($this->summary('renderFile', false), $seen[2]);
        $this->assertSame($this->summary('writeRenderedFile', false), $seen[6]);
        $this->assertCount(7, $seen);
    }

    public function testNativePriorityOrderAndObserverReturnValuesCannotReplaceResult(): void
    {
        $order = [];
        foreach ([20, 10, 10] as $index => $priority) {
            DevElation::action(self::HOOK, static function (array $summary) use (&$order, $index): array {
                $order[] = $index;
                $summary['valid'] = false;
                return $summary;
            }, $priority);
        }
        $this->assertTrue($this->service()->validateSource('hello')['valid']);
        $this->assertSame([1, 2, 0], $order);
    }

    public function testObserverExceptionStopsLaterHandlersButPreservesWriteAndResetsGuard(): void
    {
        $calls = 0;
        $later = 0;
        DevElation::action(self::HOOK, static function () use (&$calls): void {
            ++$calls;
            throw new \Error('private-observer-error');
        }, 1);
        DevElation::action(self::HOOK, static function () use (&$later): void { ++$later; }, 2);
        $service = $this->service();
        $this->assertTrue($service->writeRenderedFile($this->workspace . '/private.vibe', 'result.txt', ['value' => 'value'])['valid']);
        $this->assertSame('Private value', file_get_contents($this->workspace . '/result.txt'));
        $this->assertTrue($service->validateFile($this->workspace . '/private.vibe')['valid']);
        $this->assertSame(6, $calls);
        $this->assertSame(0, $later);
    }

    public function testEveryEntryPointRejectsCrossInstanceObserverReentryBeforeWork(): void
    {
        $first = $this->service();
        $other = $this->service();
        $count = 0;
        $attempts = [];
        DevElation::action(self::HOOK, function () use ($first, $other, &$count, &$attempts): void {
            ++$count;
            foreach ([$first, $other] as $service) {
                foreach ([
                    fn () => $service->validateSource('valid'),
                    fn () => $service->validateFile($this->workspace . '/private.vibe'),
                    fn () => $service->renderSource('render'),
                    fn () => $service->renderFile($this->workspace . '/private.vibe'),
                    fn () => $service->writeRenderedFile($this->workspace . '/private.vibe', 'forbidden.txt'),
                ] as $call) {
                    try { $call(); $attempts[] = 'unexpected_success'; }
                    catch (Throwable $error) { $attempts[] = $error::class . ':' . $error->getMessage(); }
                }
            }
        });
        $this->assertTrue($first->validateSource('valid')['valid']);
        $this->assertTrue($other->validateSource('still valid')['valid']);
        $this->assertSame(2, $count);
        $this->assertSame(array_fill(0, 20, RuntimeException::class . ':generation_observer_reentry_denied'), $attempts);
        $this->assertFileDoesNotExist($this->workspace . '/forbidden.txt');
    }

    public function testDisabledAndUnsubscribedDispatchDoNotChangeResultsOrReplay(): void
    {
        $service = $this->service();
        $expected = $service->renderSource('Private {$value}', ['value' => 'value']);
        $count = 0;
        DevElation::action(self::HOOK, static function () use (&$count): void { ++$count; });
        DevElation::down();
        $this->assertSame($expected, $service->renderSource('Private {$value}', ['value' => 'value']));
        $this->assertSame(0, $count);
        DevElation::up();
        $this->assertSame(0, $count);
        $this->assertSame($expected, $service->renderSource('Private {$value}', ['value' => 'value']));
        $this->assertSame(2, $count);
    }

    public function testOriginalThrownFailureIsPreservedWithNoPrivateDetailsInObservation(): void
    {
        $error = new RuntimeException('private-source-failure');
        $service = new class($error, $this->workspace) extends VibeGenerationService {
            public function __construct(private Throwable $error, string $workspace) { parent::__construct(workspace: $workspace); }
            public function validateSource(string $source): array { throw $this->error; }
        };
        $seen = [];
        DevElation::action(self::HOOK, static function (array $summary) use (&$seen): void { $seen[] = $summary; throw new \Error('observer'); });
        try { $service->validateFile($this->workspace . '/private.vibe'); $this->fail('Original error was lost.'); }
        catch (RuntimeException $actual) { $this->assertSame($error, $actual); }
        $this->assertSame([$this->summary('validateFile', false, 'threw')], $seen);
        $this->assertTrue($this->service()->validateSource('still valid')['valid']);
    }

    public function testYieldingObserverRetainsGuardUntilDeliveryFinishes(): void
    {
        $service = $this->service();
        $calls = 0;
        DevElation::action(self::HOOK, static function () use (&$calls): void {
            if (++$calls === 1) { \Fiber::suspend('observing'); }
        });
        $fiber = new \Fiber(fn () => $service->validateSource('valid'));
        try {
            $this->assertSame('observing', $fiber->start());
            try { $this->service()->renderSource('not permitted during notification'); $this->fail('Guard lost across yield.'); }
            catch (RuntimeException $error) { $this->assertSame('generation_observer_reentry_denied', $error->getMessage()); }
        } finally {
            if ($fiber->isSuspended()) { $fiber->resume(); }
        }
        $this->assertTrue($fiber->getReturn()['valid']);
        $this->assertTrue($service->validateSource('valid again')['valid']);
        $this->assertSame(2, $calls);
    }
}
