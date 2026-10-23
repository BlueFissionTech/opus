<?php
declare(strict_types=1);
namespace Tests\Feature;

// Keep the package conformance contract in the ordinary host CI test suite.
require_once dirname(__DIR__, 2) . '/addons/aimos/tests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/addons/aimos/tests/ReportServiceTest.php';

final class AimosReportingContractTest extends \AddOns\Aimos\Tests\ReportServiceTest
{
}
