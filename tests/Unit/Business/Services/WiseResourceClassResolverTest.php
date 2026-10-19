<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

require_once dirname(__DIR__, 4) . '/app/Business/Services/WiseResourceClassResolver.php';

use App\Business\Services\WiseResourceClassResolver;
use ArrayObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class WiseResourceClassResolverTest extends TestCase
{
    public function testItPrefersTheCanonicalResourceClass(): void
    {
        $this->assertSame(
            stdClass::class,
            WiseResourceClassResolver::resolve(stdClass::class, ArrayObject::class)
        );
    }

    public function testItFallsBackToAnAvailableLegacyResourceClass(): void
    {
        $this->assertSame(
            stdClass::class,
            WiseResourceClassResolver::resolve(self::class . '\\MissingCanonical', stdClass::class)
        );
    }

    public function testItRejectsAnUnavailableResourceClass(): void
    {
        $canonicalClass = self::class . '\\MissingCanonical';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Wise resource class '{$canonicalClass}' is unavailable.");

        WiseResourceClassResolver::resolve($canonicalClass, self::class . '\\MissingLegacy');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testItResolvesAResourceWhenWiseCompatibilityIsPreloaded(): void
    {
        $legacyClass = \BlueFission\Wise\Commands\FileResource::class;

        $this->assertTrue(class_exists($legacyClass));
        $resource = WiseResourceClassResolver::resolve(
            \BlueFission\Wise\Res\FileResource::class,
            $legacyClass
        );

        $this->assertTrue(class_exists($resource, false));
        $this->assertContains($resource, [
            \BlueFission\Wise\Res\FileResource::class,
            $legacyClass,
        ]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testApplicationMappingRegistersTheAvailableWiseFileResource(): void
    {
        $application = new class {
            /** @var array<string, class-string> */
            public array $delegates = [];
            public array $actions = [];

            public function delegate(string $name, string $resource): void
            {
                $this->delegates[$name] = $resource;
            }

            public function register(string $service, string $behavior, string $callable): void
            {
                $this->actions[$service][$behavior] = $callable;
            }
        };

        $GLOBALS['opus_mapping_application'] = $application;
        eval(<<<'PHP'
namespace BlueFission\BlueCore;

final class Engine
{
    public static function instance(): object
    {
        return $GLOBALS['opus_mapping_application'];
    }
}
PHP);

        $report = require dirname(__DIR__, 4) . '/mapping/app.php';

        $catalog = new \BlueFission\Wise\Res\ResourceCatalog();
        foreach ($catalog->definitions() as $name => $class) {
            $this->assertSame($class, $application->delegates[$name] ?? null);
        }
        foreach ($catalog->aliases() as $alias => $canonical) {
            $this->assertSame($catalog->classFor($canonical), $application->delegates[$alias] ?? null);
        }
        foreach ($catalog->unavailable() as $name => $reason) {
            $this->assertArrayNotHasKey($name, $application->delegates);
            $this->assertSame($reason, $report['unavailable'][$name]);
        }
        $this->assertArrayNotHasKey('task', $application->delegates, 'Do not invent catalog aliases.');
        $this->assertArrayNotHasKey('task', $application->actions);
        $this->assertArrayNotHasKey('resource', $application->actions);
        $this->assertSame('browse', $application->actions['web']['get']);
        $this->assertSame('handle', $application->actions['search']['find']);
        $this->assertSame($application->actions['calc'], $application->actions['calculator']);
        $this->assertSame(\App\Business\Commands\UserResource::class, $application->delegates['user']);

        $resource = $application->delegates['file'] ?? null;
        $this->assertNotNull($resource);
        $this->assertTrue(class_exists($resource, false));
        $this->assertContains($resource, [
            \BlueFission\Wise\Res\FileResource::class,
            \BlueFission\Wise\Commands\FileResource::class,
        ]);
    }
}
