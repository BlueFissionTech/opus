<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\EnvironmentLoader;
use BlueFission\Arr;
use PHPUnit\Framework\TestCase;

class EnvironmentLoaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'opus-env-');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        Arr::make($this->keys())->each(function (string $key): void {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        });
    }

    public function testItImportsMixedLineEndingsAndValuesContainingEquals(): void
    {
        file_put_contents(
            $this->file,
            "OPUS_TEST_FIRST=alpha\r\nOPUS_TEST_SECOND=beta=gamma\n# ignored\r\n"
        );

        EnvironmentLoader::import($this->file);

        $this->assertSame('alpha', getenv('OPUS_TEST_FIRST'));
        $this->assertSame('alpha', $_ENV['OPUS_TEST_FIRST']);
        $this->assertSame('alpha', $_SERVER['OPUS_TEST_FIRST']);
        $this->assertSame('beta=gamma', getenv('OPUS_TEST_SECOND'));
        $this->assertSame('beta=gamma', $_ENV['OPUS_TEST_SECOND']);
        $this->assertSame(EnvironmentLoader::SOURCE_DOTENV, EnvironmentLoader::sourceOf('OPUS_TEST_FIRST'));
    }

    public function testItPreservesNonEmptyValuesInjectedThroughEveryRuntimeStore(): void
    {
        file_put_contents(
            $this->file,
            "OPUS_TEST_PROCESS=dotenv\nOPUS_TEST_ENV=dotenv\nOPUS_TEST_SERVER=dotenv\n"
        );
        putenv('OPUS_TEST_PROCESS=process-secret');
        $_ENV['OPUS_TEST_ENV'] = 'environment';
        $_SERVER['OPUS_TEST_SERVER'] = 'server';

        EnvironmentLoader::import($this->file);

        $this->assertSame('process-secret', getenv('OPUS_TEST_PROCESS'));
        $this->assertSame('environment', getenv('OPUS_TEST_ENV'));
        $this->assertSame('server', getenv('OPUS_TEST_SERVER'));
        $this->assertSame('process-secret', $_ENV['OPUS_TEST_PROCESS']);
        $this->assertSame('environment', $_SERVER['OPUS_TEST_ENV']);
        $this->assertSame('server', $_ENV['OPUS_TEST_SERVER']);
        $this->assertSame(EnvironmentLoader::SOURCE_PROCESS, EnvironmentLoader::sourceOf('OPUS_TEST_PROCESS'));

        $report = Arr::make(EnvironmentLoader::lastReport());
        $this->assertSame('loaded', $report->get('status'));
        $this->assertCount(3, $report->get('preserved'));
        $this->assertStringNotContainsString('process-secret', Arr::make($report->get('preserved'))->toJson());
    }

    public function testItTreatsEmptyRuntimeValuesAsUnsetFallbacks(): void
    {
        file_put_contents($this->file, "OPUS_TEST_EMPTY=fallback\n");
        putenv('OPUS_TEST_EMPTY=');
        $_ENV['OPUS_TEST_EMPTY'] = '';
        $_SERVER['OPUS_TEST_EMPTY'] = '';

        EnvironmentLoader::import($this->file);

        $this->assertSame('fallback', getenv('OPUS_TEST_EMPTY'));
        $this->assertSame('fallback', $_ENV['OPUS_TEST_EMPTY']);
        $this->assertSame('fallback', $_SERVER['OPUS_TEST_EMPTY']);
        $this->assertSame(EnvironmentLoader::SOURCE_DOTENV, EnvironmentLoader::sourceOf('OPUS_TEST_EMPTY'));
    }

    public function testRepeatedImportsDoNotReplaceAnEstablishedValue(): void
    {
        file_put_contents($this->file, "OPUS_TEST_REPEAT=first\n");
        EnvironmentLoader::import($this->file);
        file_put_contents($this->file, "OPUS_TEST_REPEAT=second\n");

        EnvironmentLoader::import($this->file);

        $this->assertSame('first', getenv('OPUS_TEST_REPEAT'));
        $this->assertSame(EnvironmentLoader::SOURCE_DOTENV, EnvironmentLoader::sourceOf('OPUS_TEST_REPEAT'));
        $this->assertSame([], Arr::make(EnvironmentLoader::lastReport())->get('loaded'));
    }

    private function keys(): array
    {
        return [
            'OPUS_TEST_FIRST',
            'OPUS_TEST_SECOND',
            'OPUS_TEST_PROCESS',
            'OPUS_TEST_ENV',
            'OPUS_TEST_SERVER',
            'OPUS_TEST_EMPTY',
            'OPUS_TEST_REPEAT',
        ];
    }
}
