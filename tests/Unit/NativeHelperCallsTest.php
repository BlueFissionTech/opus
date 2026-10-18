<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\NativeHelperCalls;

final class NativeHelperCallsTest extends TestCase
{
    public function testDirectAndGlobalCallsHaveSourceLocationsWithoutExecutingSource(): void
    {
        $source = <<<'PHP'
<?php
trim($value);
\TRIM /* trivia */ ($value);
json_decode($payload);
throw new RuntimeException('This source must never execute');
PHP;
        self::assertSame([
            ['name' => 'trim', 'line' => 2, 'reason' => 'string'],
            ['name' => 'trim', 'line' => 3, 'reason' => 'string'],
            ['name' => 'json_decode', 'line' => 4, 'reason' => 'json'],
        ], NativeHelperCalls::find($source, ['trim' => 'string', 'json_decode' => 'json']));
    }

    public function testDeclarationsAndPackageMethodsAreNotNativeCalls(): void
    {
        $source = <<<'PHP'
<?php
interface Counter { public function count(): int; }
class Bag {
    public function &count() { return $this->items; }
    public function trim() { return Str::trim('value'); }
}
function /* comment */ &trim() { return $value; }
$bag->count(); $bag?->trim(); Str::trim($value);
new Count(); new \Count();
Vendor\trim($value); \Vendor\trim($value); namespace\trim($value);
$text = 'trim($value)'; // count($items)
/* json_decode($payload) */
PHP;
        self::assertSame([], NativeHelperCalls::find($source, ['count' => 'count', 'trim' => 'string', 'json_decode' => 'json']));
    }

    public function testADeclarationDoesNotHideCallsInItsBodyAndCallableReferencesAreVisible(): void
    {
        $source = '<?php class Bag { function count() { return count($this->items); } } $callback = \\trim(...);';
        self::assertSame(['count', 'trim'], array_column(NativeHelperCalls::find($source, [
            'count' => 'count', 'trim' => 'string',
        ]), 'name'));
    }
}
