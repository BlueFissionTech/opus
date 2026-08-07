<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\EnvironmentLoader;
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
        putenv('OPUS_TEST_FIRST');
        putenv('OPUS_TEST_SECOND');
        unset($_ENV['OPUS_TEST_FIRST'], $_ENV['OPUS_TEST_SECOND']);
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
        $this->assertSame('beta=gamma', getenv('OPUS_TEST_SECOND'));
        $this->assertSame('beta=gamma', $_ENV['OPUS_TEST_SECOND']);
    }
}
