<?php

declare(strict_types=1);

namespace AddOns\Tributary\Tests;

use AddOns\Tributary\Domain\Project;
use BlueFission\Arr;
use BlueFission\Val;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;

final class ProjectRecordTest extends TestCase
{
    public function testPortableValuesKeepTheirTypesAndDetachReferences(): void
    {
        $text = 'original';
        $input = ['null' => null, 'false' => false, 'zero' => 0, 'fraction' => 1.25,
            'numeric_text' => '1e999', 'empty' => [], 'nested' => ['text' => &$text]];
        $copy = Project::snapshot($input);
        $text = 'changed';
        self::assertSame('original', $copy['nested']['text']);
        unset($input['nested'], $copy['nested']);
        self::assertSame($input, $copy);
        self::assertSame([], Project::identifiers([]));
        self::assertSame(['worker', 'principal'], Project::identifiers(['worker', 'worker', 'principal']));
    }

    /** @dataProvider invalidLists */
    public function testMalformedIdentifierListsAreRejected(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Project::identifiers($value);
    }

    public static function invalidLists(): array
    {
        return ['sparse' => [[1 => 'worker']], 'reordered' => [[1 => 'worker', 0 => 'principal']],
            'hash' => [['role' => 'worker']], 'scalar' => ['worker'], 'null' => [null],
            'non-string member' => [[0]], 'blank member' => [[' ']]];
    }

    /** @dataProvider nonFiniteNumbers */
    public function testNonFiniteNumbersAreRejectedBeforeSerialization(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Project::snapshot(['nested' => [$value]]);
    }

    public static function nonFiniteNumbers(): array
    {
        return [[INF], [-INF], [NAN]];
    }

    public function testObjectsCannotEnterPortableRecordsThroughPrimitiveUnwrapping(): void
    {
        foreach ([Arr::make(['worker']), Val::make(1), new \stdClass(), static fn () => 'code'] as $value) {
            try {
                Project::data(['nested' => $value]);
                self::fail('Object values must be rejected before serialization.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Records must contain only data.', $error->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Project::identifiers(Arr::make(['worker']));
    }

    public function testExcessiveDepthIsRejected(): void
    {
        $nested = 'value';
        for ($depth = 0; $depth < 17; $depth++) { $nested = [$nested]; }
        $this->expectException(InvalidArgumentException::class);
        Project::data($nested);
    }

    public function testInvalidUtf8IsRejectedByThePortableJsonBoundary(): void
    {
        $this->expectException(JsonException::class);
        Project::snapshot(['invalid' => "\xff"]);
    }
}
