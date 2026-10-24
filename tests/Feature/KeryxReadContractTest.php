<?php
declare(strict_types=1);
namespace Tests\Feature;

// Keep the package's authority and native registration contract in host CI.
require_once dirname(__DIR__, 2) . '/addons/keryx/tests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/addons/keryx/tests/ReadServiceTest.php';

final class KeryxReadContractTest extends \AddOns\Keryx\Tests\ReadServiceTest
{
}
