<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\WiseIntegrationGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WiseIntegrationGuardTest extends TestCase
{
    public function testRequiredProfileAllowsACompleteTypeSet(): void
    {
        $checked = [];
        $guard = new WiseIntegrationGuard(function (string $class) use (&$checked): bool {
            $checked[] = $class;

            return true;
        });

        $this->assertTrue($guard->allows(' required ', ['Wise\\One', 'Wise\\Two']));
        $this->assertSame(['Wise\\One', 'Wise\\Two'], $checked);
    }

    public function testOptionalProfileSkipsTheEntireIntegrationWhenATypeIsMissing(): void
    {
        $guard = new WiseIntegrationGuard(
            static fn (string $class): bool => $class !== 'Wise\\Missing'
        );

        $this->assertFalse($guard->allows('optional', ['Wise\\Present', 'Wise\\Missing']));
    }

    public function testRequiredProfileReportsEveryMissingTypeAndMigrationPath(): void
    {
        $guard = new WiseIntegrationGuard(
            static fn (string $class): bool => $class === 'Wise\\Present'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Missing types: Wise\\MissingOne, Wise\\MissingTwo. '
            . 'Install a compatible bluefission/wise release or set WISE_INTEGRATION=optional.'
        );

        $guard->allows('required', ['Wise\\Present', 'Wise\\MissingOne', 'Wise\\MissingTwo']);
    }

    public function testUnknownProfileFailsWithAnActionableDiagnostic(): void
    {
        $guard = new WiseIntegrationGuard(static fn (string $class): bool => true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unknown Wise integration profile 'disabled'.");

        $guard->allows('disabled', ['Wise\\Present']);
    }
}
