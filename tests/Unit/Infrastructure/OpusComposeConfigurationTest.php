<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

final class OpusComposeConfigurationTest extends TestCase
{
    public function testOpusModeHasAComposeOverlayForTheWebRuntime(): void
    {
        $composePath = $this->projectRoot() . DIRECTORY_SEPARATOR . 'docker' . DIRECTORY_SEPARATOR . 'compose.opus.yml';

        $this->assertFileExists($composePath);

        $compose = (string)file_get_contents($composePath);
        $this->assertMatchesRegularExpression('/^services:/m', $compose);
        $this->assertMatchesRegularExpression('/^  nginx:/m', $compose);
        $this->assertMatchesRegularExpression('/^  php:/m', $compose);
        $this->assertStringContainsString('MYSQL_DB_HOST: ${BF_MYSQL_DB_HOST:-mysql}', $compose);
    }

    public function testOpusHarnessDefaultsMatchTheLocalMysqlService(): void
    {
        $harness = json_decode(
            (string)file_get_contents($this->projectRoot() . DIRECTORY_SEPARATOR . 'harness.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('opus', $harness['defaults']['opus']['mode']);
        $this->assertSame('mysql', $harness['docker_env']['opus']['MYSQL_DB_HOST']);
        $this->assertSame('3306', $harness['docker_env']['opus']['MYSQL_DB_PORT']);
        $this->assertSame('app', $harness['docker_env']['opus']['MYSQL_DB_NAME']);
        $this->assertSame('root', $harness['docker_env']['opus']['MYSQL_DB_USERNAME']);
        $this->assertSame('root', $harness['docker_env']['opus']['MYSQL_DB_PASSWORD']);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
