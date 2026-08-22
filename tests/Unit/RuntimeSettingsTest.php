<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Business\Services\RuntimePathResolver;
use PHPUnit\Framework\TestCase;

final class RuntimeSettingsTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInstalledPackageAndHostRootsRemainDistinct(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opus-settings-' . bin2hex(random_bytes(6));
        $host = $workspace . DIRECTORY_SEPARATOR . 'host';
        $package = $host . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bluefission'
            . DIRECTORY_SEPARATOR . 'opus';
        mkdir($package . DIRECTORY_SEPARATOR . 'resource', 0777, true);
        mkdir($host . DIRECTORY_SEPARATOR . 'public', 0777, true);

        $GLOBALS['OPUS_RUNTIME_PATHS'] = RuntimePathResolver::discover($package, $host);
        require_once dirname(__DIR__, 2) . '/common/helpers/functions.php';
        require dirname(__DIR__, 2) . '/common/helpers/settings.php';

        $this->assertSame(realpath($host) . DIRECTORY_SEPARATOR, APP_ROOT);
        $this->assertSame(realpath($package) . DIRECTORY_SEPARATOR, OPUS_ROOT);
        $this->assertSame(realpath($package . '/resource') . DIRECTORY_SEPARATOR, OPUS_RESOURCE_ROOT);
        $this->assertSame(realpath($host . '/public'), realpath(SITE_ROOT));

        rmdir($host . DIRECTORY_SEPARATOR . 'public');
        rmdir($package . DIRECTORY_SEPARATOR . 'resource');
        rmdir($package);
        rmdir(dirname($package));
        rmdir($host . DIRECTORY_SEPARATOR . 'vendor');
        rmdir($host);
        rmdir($workspace);
    }
}
