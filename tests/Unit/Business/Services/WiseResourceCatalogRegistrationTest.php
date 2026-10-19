<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\WiseIntegrationGuard;
use App\Business\Services\WiseResourceCatalogRegistration;
use BlueFission\Wise\Res\ResourceCatalog;
use BlueFission\Services\Application;
use BlueFission\Services\Service;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WiseResourceCatalogRegistrationTest extends TestCase
{
    public function testInstalledCatalogControlsEveryDefinitionAndAlias(): void
    {
        $application = new CatalogRecordingApplication();
        $catalog = new ResourceCatalog();
        $report = (new WiseResourceCatalogRegistration())->installMappings($application, 'required', [
            'calc' => ['do' => 'handle'], 'resource' => ['list' => 'handle'],
        ]);
        $this->assertTrue($report['registered']);
        foreach ($catalog->definitions() as $name => $class) {
            $this->assertSame($class, $application->delegates[$name]);
        }
        foreach ($catalog->aliases() as $alias => $canonical) {
            $this->assertSame($application->delegates[$canonical], $application->delegates[$alias]);
        }
        $this->assertSame('handle', $application->actions['calculator']['do']);
        $this->assertSame($catalog->unavailable(), $report['unavailable']);
        $this->assertArrayNotHasKey('resource', $application->delegates);
        $this->assertArrayNotHasKey('resource', $application->actions);
        $this->assertArrayNotHasKey('function', $application->actions, 'Private profile execution needs an explicit adapter.');
    }

    public function testInspectionDoesNotConstructResourcesAndRecordedAliasesShareCanonicalVerbs(): void
    {
        $application = new CatalogRecordingApplication();
        $registration = $this->registration();
        $inspection = $registration->inspectCatalog('required');
        $this->assertTrue($inspection['ready']);
        $this->assertSame(CatalogNeverConstructedResource::class, $inspection['bindings']['alias']);
        $report = $registration->installMappings($application, 'required', ['example' => ['show' => 'handle']]);
        $this->assertTrue($report['registered']);
        $this->assertSame(CatalogNeverConstructedResource::class, $application->delegates['example']);
        $this->assertSame($application->delegates['example'], $application->delegates['alias']);
        $this->assertSame($application->actions['example'], $application->actions['alias']);
    }

    public function testRealApplicationOwnsResolutionOnlyAfterSuccessfulPreflight(): void
    {
        $application = new class(['name' => 'catalog-proof-' . bin2hex(random_bytes(6))]) extends Application {
            public array $resolutions = [];

            public function resolve(string $class)
            {
                $this->resolutions[] = $class;
                return new Service();
            }

            public function hasRegistered(string $name): bool { return $this->_services->has($name); }
        };
        $missing = new WiseResourceCatalogRegistration(
            new WiseIntegrationGuard(static fn (string $class): bool => false)
        );
        $this->assertFalse($missing->installMappings($application, 'optional')['registered']);
        $this->assertSame([], $application->resolutions);
        $registration = $this->registration();
        $registration->inspectCatalog('required');
        $this->assertSame([], $application->resolutions);
        $registration->installMappings($application, 'required', ['example' => ['show' => 'handle']]);
        $this->assertSame([CatalogNeverConstructedResource::class, CatalogNeverConstructedResource::class], $application->resolutions);
        $this->assertTrue($application->hasRegistered('example'));
        $this->assertTrue($application->hasRegistered('alias'));
        $this->assertFalse($application->hasRegistered('gone'));
    }

    /** @dataProvider profiles */
    public function testMissingCatalogNeverCallsTheFactoryOrChangesRegistrations(string $profile): void
    {
        $application = new CatalogRecordingApplication();
        $application->delegate('user', 'LocalUserResource');
        $calls = 0;
        $registration = new WiseResourceCatalogRegistration(
            new WiseIntegrationGuard(static fn (string $class): bool => false),
            static function () use (&$calls): ResourceCatalog {
                $calls++;
                throw new RuntimeException('Factory must not run.');
            }
        );
        try {
            $report = $registration->installMappings($application, $profile);
            $this->assertSame('optional', $profile);
            $this->assertFalse($report['registered']);
            $this->assertSame('catalog_unavailable', $report['reason']);
        } catch (RuntimeException $error) {
            $this->assertSame('required', $profile);
            $this->assertStringContainsString(ResourceCatalog::class, $error->getMessage());
        }
        $this->assertSame(0, $calls);
        $this->assertSame(['user' => 'LocalUserResource'], $application->delegates);
        $this->assertSame([], $application->actions);
    }

    /** @dataProvider profiles */
    public function testMissingDefinitionPreventsEveryWiseRegistration(string $profile): void
    {
        $application = new CatalogRecordingApplication();
        $catalog = new CatalogFixture([
            'example' => CatalogNeverConstructedResource::class,
            'missing' => 'Missing\\WiseResource',
            'another_missing' => 'Missing\\OtherWiseResource',
        ]);
        $registration = new WiseResourceCatalogRegistration(catalogFactory: static fn (): ResourceCatalog => $catalog);
        try {
            $report = $registration->installMappings($application, $profile, ['example' => ['show' => 'handle']]);
            $this->assertSame('optional', $profile);
            $this->assertFalse($report['registered']);
            $this->assertSame('resource_classes_unavailable', $report['reason']);
            $this->assertSame('not_implemented', $report['unavailable']['gone']);
        } catch (RuntimeException $error) {
            $this->assertSame('required', $profile);
            $this->assertStringContainsString('Missing\\WiseResource', $error->getMessage());
            $this->assertStringContainsString('Missing\\OtherWiseResource', $error->getMessage());
        }
        $this->assertSame([], $application->delegates);
        $this->assertSame([], $application->actions);
    }

    public function testUnavailableAliasesRemainUnavailableEvenWhenHostVerbsExist(): void
    {
        $application = new CatalogRecordingApplication();
        $catalog = new CatalogFixture(aliases: ['alias' => 'example', 'gone_alias' => 'gone']);
        $registration = new WiseResourceCatalogRegistration(catalogFactory: static fn (): ResourceCatalog => $catalog);
        $report = $registration->installMappings($application, 'required', ['gone' => ['list' => 'handle']]);
        $this->assertSame('not_implemented', $report['unavailable']['gone_alias']);
        $this->assertArrayNotHasKey('gone_alias', $application->delegates);
        $this->assertArrayNotHasKey('gone', $application->actions);
    }

    public function testInvalidAliasFailsBeforeAnyRegistration(): void
    {
        $application = new CatalogRecordingApplication();
        $catalog = new CatalogFixture(aliases: ['alias' => 'unknown']);
        $registration = new WiseResourceCatalogRegistration(catalogFactory: static fn (): ResourceCatalog => $catalog);
        try {
            $registration->installMappings($application, 'required');
            $this->fail('An unknown alias target must not be fabricated.');
        } catch (RuntimeException $error) {
            $this->assertSame('wise_resource_catalog_alias_invalid', $error->getMessage());
        }
        $this->assertSame([], $application->delegates);
    }

    public function testInvalidCatalogFailsBeforeAnyRegistration(): void
    {
        $application = new CatalogRecordingApplication();
        $registration = new WiseResourceCatalogRegistration(catalogFactory: static fn (): array => []);
        try {
            $registration->installMappings($application, 'required');
            $this->fail('The released catalog contract is required.');
        } catch (RuntimeException $error) {
            $this->assertSame('wise_resource_catalog_invalid', $error->getMessage());
        }
        $this->assertSame([], $application->delegates);
    }

    public static function profiles(): array
    {
        return [['required'], ['optional']];
    }

    private function registration(): WiseResourceCatalogRegistration
    {
        return new WiseResourceCatalogRegistration(catalogFactory: static fn (): ResourceCatalog => new CatalogFixture());
    }
}

final class CatalogFixture extends ResourceCatalog
{
    public function __construct(
        private array $fixtureDefinitions = ['example' => CatalogNeverConstructedResource::class],
        private array $aliases = ['alias' => 'example']
    ) {
    }

    public function definitions(): array { return $this->fixtureDefinitions; }
    public function aliases(): array { return $this->aliases; }
    public function unavailable(): array { return ['gone' => 'not_implemented']; }
}

final class CatalogNeverConstructedResource extends Service
{
    public function __construct() { throw new RuntimeException('Resource construction is forbidden during mapping.'); }
}

final class CatalogRecordingApplication
{
    public array $delegates = [];
    public array $actions = [];

    public function delegate(string $name, string $class): void { $this->delegates[$name] = $class; }
    public function register(string $name, string $verb, string $method): void { $this->actions[$name][$verb] = $method; }
}
