<?php

declare(strict_types=1);

namespace App\Domain\Guidance;

interface ConsultingGuidanceInterface
{
    /** Return advisory data only; do not execute commands or mutate application state. */
    public function advise(GuidanceRequest $request): GuidanceOutcome;
}
