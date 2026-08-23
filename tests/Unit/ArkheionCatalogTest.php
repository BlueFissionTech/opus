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
    private const CAPABILITIES = [
        'Aimos',
        'BasicContact',
        'Cogito',
        'ForeUP',
        'Hoom',
        'Kapsle',
        'Presentations',
        'SageMaker',
        'Smart Responder',
        'Students',
        'Synematic add-on',
    ];

    public function testCatalogCoversEveryNamedCapabilityAndMaturityState(): void
    {
        $catalog = Str::make((string) FileSystem::fileContents($this->catalogPath()));

        Arr::make(self::CAPABILITIES)->each(
            fn (string $capability) => $this->assertTrue(
                $catalog->contains('| ' . $capability . ' |'),
                $capability . ' is missing from the Arkheion catalog.'
            )
        );

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

            $this->assertTrue($catalog->contains('`' . $metadata->get('name') . '`'));
            $this->assertTrue($catalog->contains('`' . Str::sub((string) $source->get('reference'), 0, 7) . '`'));
        });
    }

    private function catalogPath(): string
    {
        return dirname(__DIR__, 2) . '/ARKHEION.md';
    }
}
