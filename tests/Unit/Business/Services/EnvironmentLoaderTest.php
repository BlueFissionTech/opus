<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\EnvironmentLoader;
use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Str;
use PHPUnit\Framework\TestCase;

class EnvironmentLoaderTest extends TestCase
{
    private const KEYS = [
        'OPUS_TEST_FIRST',
        'OPUS_TEST_SECOND',
        'OPUS_TEST_EMPTY',
        'OPUS_TEST_REPEAT',
        'DB_HOST',
        'DB_DATABASE',
    ];

    private string $file;
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'opus-env-');
        foreach (self::KEYS as $key) {
            $this->originalEnvironment[$key] = [
                'process' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }
    }

    protected function tearDown(): void
    {
        if (FileSystem::fileExists($this->file)) {
            unlink($this->file);
        }
        foreach (self::KEYS as $key) {
            $original = Arr::make($this->originalEnvironment[$key]);
            $process = $original->get('process');
            putenv($process === false ? $key : $key . '=' . $process);

            if ($original->get('env_exists')) {
                $_ENV[$key] = $original->get('env');
            } else {
                unset($_ENV[$key]);
            }

            if ($original->get('server_exists')) {
                $_SERVER[$key] = $original->get('server');
            } else {
                unset($_SERVER[$key]);
            }
        }
    }

    public function testItImportsMixedLineEndingsAndValuesContainingEquals(): void
    {
        file_put_contents(
            $this->file,
            "OPUS_TEST_FIRST=alpha\r\nOPUS_TEST_SECOND=beta=gamma\n# ignored\r\n"
        );

        $report = EnvironmentLoader::import($this->file);

        $this->assertSame('alpha', getenv('OPUS_TEST_FIRST'));
        $this->assertSame('alpha', $_ENV['OPUS_TEST_FIRST']);
        $this->assertSame('alpha', $_SERVER['OPUS_TEST_FIRST']);
        $this->assertSame('beta=gamma', getenv('OPUS_TEST_SECOND'));
        $this->assertSame('beta=gamma', $_ENV['OPUS_TEST_SECOND']);
        $this->assertSame(2, $report->get('loaded'));
        $this->assertSame(0, $report->get('preserved'));
        $this->assertSame([
            'OPUS_TEST_FIRST' => 'dotenv',
            'OPUS_TEST_SECOND' => 'dotenv',
        ], $report->get('sources'));
    }

    public function testItPreservesInjectedDatabaseSettingsAndUsesDotenvFallbacks(): void
    {
        file_put_contents($this->file, "DB_HOST=dotenv-host\nDB_DATABASE=dotenv-db\n");
        putenv('DB_HOST=process-host');
        putenv('DB_DATABASE');
        unset($_ENV['DB_HOST'], $_SERVER['DB_HOST'], $_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE']);

        $report = EnvironmentLoader::import($this->file);

        $this->assertSame('process-host', getenv('DB_HOST'));
        $this->assertSame('process-host', $_ENV['DB_HOST']);
        $this->assertSame('process-host', $_SERVER['DB_HOST']);
        $this->assertSame('dotenv-db', getenv('DB_DATABASE'));
        $this->assertSame(1, $report->get('loaded'));
        $this->assertSame(1, $report->get('preserved'));
        $sources = Arr::make($report->get('sources'));
        $this->assertSame('process', $sources->get('DB_HOST'));
        $this->assertSame('dotenv', $sources->get('DB_DATABASE'));
        $this->assertFalse(Str::make($report->toJson())->contains('process-host'));
        $this->assertFalse(Str::make($report->toJson())->contains('dotenv-db'));
    }

    public function testItUsesDotenvWhenTheProcessValueIsEmpty(): void
    {
        file_put_contents($this->file, "OPUS_TEST_EMPTY=fallback\n");
        putenv('OPUS_TEST_EMPTY=');
        $_ENV['OPUS_TEST_EMPTY'] = '';
        $_SERVER['OPUS_TEST_EMPTY'] = '';

        $report = EnvironmentLoader::import($this->file);

        $this->assertSame('fallback', getenv('OPUS_TEST_EMPTY'));
        $this->assertSame('fallback', $_ENV['OPUS_TEST_EMPTY']);
        $this->assertSame('fallback', $_SERVER['OPUS_TEST_EMPTY']);
        $this->assertSame('dotenv', Arr::make($report->get('sources'))->get('OPUS_TEST_EMPTY'));
    }

    public function testRepeatedBootstrapDoesNotReplaceTheFirstResolvedValue(): void
    {
        file_put_contents($this->file, "OPUS_TEST_REPEAT=first\n");
        EnvironmentLoader::import($this->file);
        file_put_contents($this->file, "OPUS_TEST_REPEAT=second\n");

        $report = EnvironmentLoader::import($this->file);

        $this->assertSame('first', getenv('OPUS_TEST_REPEAT'));
        $this->assertSame(0, $report->get('loaded'));
        $this->assertSame(1, $report->get('preserved'));
        $this->assertSame('process', Arr::make($report->get('sources'))->get('OPUS_TEST_REPEAT'));
    }

    public function testItReportsUnreadableSourcesWithoutWarningsOrValues(): void
    {
        unlink($this->file);

        $report = EnvironmentLoader::import($this->file);

        $this->assertTrue($report->get('missing'));
        $this->assertSame(0, $report->get('loaded'));
        $this->assertSame([], $report->get('sources'));
        $this->assertSame($report->val(), EnvironmentLoader::report()->val());
    }
}
