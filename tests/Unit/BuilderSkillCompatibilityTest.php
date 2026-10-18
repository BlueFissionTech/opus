<?php

declare(strict_types=1);

namespace Tests\Unit;

use BlueFission\Automata\Context;
use BlueFission\Automata\Intent\Skill\BaseSkill;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class BuilderSkillCompatibilityTest extends TestCase
{
    public static function skills(): array
    {
        return [
            ['ApplicationBuilderSkill'],
            ['ModelBuilderSkill'],
            ['WebsiteBuilderSkill'],
        ];
    }

    /** @dataProvider skills */
    public function testOptionalContextContractAndWarningFreeCompilation(string $skill): void
    {
        $path = dirname(__DIR__, 2) . '/app/Business/Skills/' . $skill . '.php';
        require_once $path;
        $class = new ReflectionClass('App\\Business\\Skills\\' . $skill);
        self::assertTrue($class->isSubclassOf(BaseSkill::class));
        $method = $class->getMethod('execute');
        self::assertTrue($method->isPublic());
        self::assertSame(0, $method->getNumberOfRequiredParameters());
        $parameters = $method->getParameters();
        self::assertCount(1, $parameters);
        $parameter = $parameters[0];
        self::assertSame('context', $parameter->getName());
        self::assertInstanceOf(ReflectionNamedType::class, $parameter->getType());
        self::assertSame(Context::class, $parameter->getType()->getName());
        self::assertTrue($parameter->allowsNull());
        self::assertNull($parameter->getDefaultValue());

        // Compile separately: loading an already cached class hides compile-time deprecations.
        $process = proc_open([PHP_BINARY, '-n', '-d', 'error_reporting=-1',
            '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-l', $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $errors);
        self::assertSame('', $errors, $output . $errors);
        self::assertStringNotContainsString('Deprecated:', $output);
    }
}
