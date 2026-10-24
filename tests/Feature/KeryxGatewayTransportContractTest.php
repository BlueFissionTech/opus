<?php
declare(strict_types=1);
namespace Tests\Feature;

// The default native transport must be exercised, not only a mocked client.
require_once dirname(__DIR__, 2) . '/addons/keryx/tests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/addons/keryx/tests/GatewayTransportTest.php';

final class KeryxGatewayTransportContractTest extends \AddOns\Keryx\Tests\GatewayTransportTest
{
}
