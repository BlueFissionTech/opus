<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

final class KapsleEnumAutoloadTest extends TestCase
{
    /**
     * @dataProvider loadOrders
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInstalledEnumsLoadIndependentlyFromAnAuthoritativeClassmap(array $order): void
    {
        $root = dirname(__DIR__, 4);
        $autoload = getenv('KAPSLE_AUTOLOAD') ?: $root . '/vendor/autoload.php';
        $loader = require $autoload;
        self::assertInstanceOf(ClassLoader::class, $loader);
        $loader->setClassMapAuthoritative(true);
        self::assertTrue($loader->isClassMapAuthoritative());

        $expected = [
            'TenantStatusEnum' => [
                'ACTIVE' => ['active', 'Active'],
                'INACTIVE' => ['inactive', 'Inactive'],
                'SUSPENDED' => ['suspended', 'Suspended'],
                'DELETED' => ['deleted', 'Deleted'],
            ],
            'LogTypeEnum' => [
                'INFO' => ['info', 'Information'],
                'WARNING' => ['warning', 'Warning'],
                'ERROR' => ['error', 'Error'],
            ],
        ];

        foreach ($order as $name) {
            $class = 'AddOns\\Kapsle\\Domain\\Enums\\' . $name;
            self::assertFalse(enum_exists($class, false), 'Each enum must load independently.');
            self::assertArrayHasKey($class, $loader->getClassMap());
            $file = $loader->findFile($class);
            self::assertIsString($file);
            self::assertStringEndsWith('/logic/Domain/Enums/' . $name . '.php', str_replace('\\', '/', $file));
            $loader->loadClass($class);
            self::assertTrue(enum_exists($class, false));
            self::assertSame(realpath($file), (new ReflectionEnum($class))->getFileName());

            $actual = [];
            foreach ($class::cases() as $case) {
                $actual[$case->name] = [$case->value, $case->label()];
                self::assertSame($case, $class::from($case->value));
            }
            self::assertSame($expected[$name], $actual);
        }
    }

    public static function loadOrders(): array
    {
        return [
            'tenant first' => [['TenantStatusEnum', 'LogTypeEnum']],
            'log first' => [['LogTypeEnum', 'TenantStatusEnum']],
        ];
    }
}
