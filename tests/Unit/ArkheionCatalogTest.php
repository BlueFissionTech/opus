<?php

declare(strict_types=1);

namespace Tests\Unit;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Net\HTTP;
use BlueFission\Str;
use PHPUnit\Framework\TestCase;

final class ArkheionCatalogTest extends TestCase
{
    private const CAPABILITY_MATURITY = [
        'Aimos' => 'unknown',
        'BasicContact' => 'unknown',
        'Cogito' => 'unknown',
        'ForeUP' => 'unknown',
        'Hoom' => 'evolving',
        'Kapsle' => 'experimental',
        'Presentations' => 'unknown',
        'SageMaker' => 'unknown',
        'Smart Responder' => 'unknown',
        'Students' => 'unknown',
        'Synematic add-on' => 'unknown',
    ];

    private const PACKAGE_ROWS = [
        'bluefission/opus-addon-hoom' => 'Hoom',
        'bluefission/opus-addon-kapsle' => 'Kapsle',
    ];

    public function testCatalogCoversEveryNamedCapabilityAndMaturityState(): void
    {
        $catalog = Str::make((string) FileSystem::fileContents($this->catalogPath()));
        $rows = [];

        $catalog->split("\n")->each(function (string $line) use (&$rows): void {
            $cells = Str::make($line)->split('|');
            if ($cells->count() < 4) {
                return;
            }

            $capability = Str::make((string) $cells->get(1))->trim()->val();
            if (!Arr::make(self::CAPABILITY_MATURITY)->hasKey($capability)) {
                return;
            }

            $rows[$capability] = Str::make((string) $cells->get(3))->trim()->val();
        });

        Arr::make(self::CAPABILITY_MATURITY)->each(function (string $state, string $capability) use ($rows): void {
            $this->assertArrayHasKey($capability, $rows, $capability . ' is missing from the Arkheion catalog.');
            $this->assertSame($state, $rows[$capability], $capability . ' has an unexpected maturity state.');
        });

        Arr::make(['stable', 'evolving', 'experimental', 'planned', 'unknown'])->each(
            fn (string $state) => $this->assertTrue(
                $catalog->contains('**' . $state . '**'),
                $state . ' is missing from the catalog maturity vocabulary.'
            )
        );
    }

    public function testComposerAddOnSourcesStaySynchronizedWithTheCatalog(): void
    {
        $root = dirname(__DIR__, 2);
        $lock = Arr::make(HTTP::jsonDecode(
            (string) FileSystem::fileContents($root . '/composer.lock'),
            true,
            []
        ));
        $packages = Arr::make($lock->get('packages', []));
        $catalog = Str::make((string) FileSystem::fileContents($this->catalogPath()));

        $addOns = $packages->filter(
            fn (array $package): bool => Arr::make($package)->get('type') === 'opus-addon'
        );

        $this->assertSame(2, $addOns->size());
        $addOns->each(function (array $package) use ($catalog): void {
            $metadata = Arr::make($package);
            $source = Arr::make($metadata->get('source', []));
            $packageName = (string) $metadata->get('name');
            $reference = Str::sub((string) $source->get('reference'), 0, 7);

            $this->assertArrayHasKey($packageName, self::PACKAGE_ROWS);

            $expectedRow = Str::make('| ')
                ->append(self::PACKAGE_ROWS[$packageName])
                ->append(' | `')
                ->append($packageName)
                ->append('` at `')
                ->append($reference)
                ->append('` |')
                ->val();

            $this->assertTrue(
                $catalog->contains($expectedRow),
                $packageName . ' and ' . $reference . ' must appear in the same catalog row.'
            );
        });
    }

    private function catalogPath(): string
    {
        return dirname(__DIR__, 2) . '/ARKHEION.md';
    }
}
