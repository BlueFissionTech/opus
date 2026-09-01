<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\ExtensionPointCatalog;
use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Str;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

final class ExtensionPointCatalogTest extends TestCase
{
    public function testPublishedCatalogIsCompleteAndReady(): void
    {
        $catalog = new ExtensionPointCatalog(dirname(__DIR__, 4));
        $report = Arr::make($catalog->readinessReport());

        $this->assertSame('1.0.0', $report->get('catalog_version'));
        $this->assertSame(7, $report->get('extension_point_count'));
        $this->assertSame(8, $report->get('boundary_count'));
        $this->assertSame([], $report->get('missing'));
        $this->assertSame([], $report->get('unexpected'));
        $this->assertSame([], $report->get('uncovered'));
        $this->assertSame([], $report->get('invalid'));
        $this->assertTrue($report->get('ready'));
    }

    public function testEveryPublishedNameUsesTheCentralConstantSurface(): void
    {
        $catalog = new ExtensionPointCatalog(dirname(__DIR__, 4));
        $names = Arr::make($catalog->extensionPoints())
            ->map(fn (array $definition): string => (string) Arr::make($definition)->get('name'))
            ->values();

        $this->assertSame(ExtensionPointCatalog::NAMES, $names->val());

        $constants = Arr::make((new ReflectionClass(ExtensionPointCatalog::class))->getConstants())
            ->filter(fn ($value): bool => Str::is($value)
                && Str::make($value)->matches('/^opus\.[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/'))
            ->values();
        $this->assertSame(ExtensionPointCatalog::NAMES, $constants->val());

        $root = dirname(__DIR__, 4);
        Arr::make($catalog->extensionPoints())->each(function (array $definition) use ($root): void {
            $entry = Arr::make($definition);
            $owner = (string) $entry->get('owner');
            $source = FileSystem::fileContents(
                $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Business'
                . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . $owner . '.php'
            );

            $this->assertTrue(Str::is($source), $owner . ' source must be readable.');
            $this->assertTrue(
                Str::make((string) $source)->contains('ExtensionPointCatalog::'),
                $owner . ' must use the central extension-point constant surface.'
            );
        });
    }

    public function testApplicationCodeDoesNotExposeRawUncataloguedHookNames(): void
    {
        $violations = Arr::make([]);
        $root = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = FileSystem::fileContents($file->getPathname());
            if (!Str::is($source) || !Str::make($source)->contains('DevElation::')) {
                continue;
            }

            Arr::make(token_get_all($source))->each(function ($token) use ($file, $violations): void {
                if (!Arr::is($token) || Arr::make($token)->get(0) !== T_CONSTANT_ENCAPSED_STRING) {
                    return;
                }

                $name = Str::make((string) Arr::make($token)->get(1))->trim("'\"")->val();
                if (Str::make($name)->matches('/^opus\.[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/')) {
                    $violations->push($file->getPathname() . ': raw extension-point name ' . $name);
                }
            });
        }

        $this->assertSame([], $violations->val(), $violations->join(PHP_EOL)->val());
    }

    public function testBoundaryInventoryUsesExplicitSupportedStates(): void
    {
        $catalog = new ExtensionPointCatalog(dirname(__DIR__, 4));
        $inventory = Arr::make($catalog->boundaryInventory());
        $statuses = $inventory
            ->map(fn (array $boundary): string => (string) Arr::make($boundary)->get('status'))
            ->unique()
            ->values();

        $this->assertSame(
            ['hooked', 'stronger_abstraction', 'intentionally_closed'],
            $statuses->val()
        );
    }

    public function testInvalidCatalogFailsClosedWithActionableDiagnostics(): void
    {
        $catalog = new ExtensionPointCatalog(
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Fixtures'
                . DIRECTORY_SEPARATOR . 'extension-point-invalid'
        );
        $report = Arr::make($catalog->readinessReport());
        $invalid = Arr::make($report->get('invalid', []));

        $this->assertFalse($report->get('ready'));
        $this->assertContains('schema_version must be 1', $invalid->val());
        $this->assertContains('catalog_version must be a nonempty string', $invalid->val());
        $this->assertContains('namespace must be opus', $invalid->val());
        $this->assertContains('opus.intake.defaults is missing phase', $invalid->val());
        $this->assertContains('opus.intake.defaults is missing owner', $invalid->val());
        $this->assertContains('opus.intake.defaults is missing mutability', $invalid->val());
        $this->assertContains('opus.intake.defaults is missing exception_policy', $invalid->val());
        $this->assertContains('opus.agent.command_context has an invalid payload schema', $invalid->val());
        $this->assertContains(
            'opus.agent.command_context invariants must contain nonempty strings',
            $invalid->val()
        );
        $this->assertContains('extension-point name is duplicated: opus.intake.defaults', $invalid->val());
        $this->assertContains('extension-point name is invalid', $invalid->val());
        $this->assertContains('application_intake has an invalid inventory status', $invalid->val());
        $this->assertContains('application_intake is missing owner', $invalid->val());
        $this->assertContains('application_intake is missing rationale', $invalid->val());
        $this->assertContains('application_intake references an unknown extension point', $invalid->val());
        $this->assertContains('boundary inventory area is duplicated: application_intake', $invalid->val());
        $this->assertContains(
            'opus.intake.defaults is assigned to multiple boundary areas',
            $invalid->val()
        );
        $this->assertContains(
            'opus.agent.command_context does not belong to boundary area other_area',
            $invalid->val()
        );
    }
}
