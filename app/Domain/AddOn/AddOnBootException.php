<?php

declare(strict_types=1);

namespace App\Domain\AddOn;

use RuntimeException;

/** Native exception boundary; the host supplies a redacted scalar report. */
final class AddOnBootException extends RuntimeException
{
    public function __construct(private readonly array $bootReport)
    {
        parent::__construct('addon_boot_registration_failed');
    }

    public function report(): array
    {
        return $this->bootReport;
    }
}
