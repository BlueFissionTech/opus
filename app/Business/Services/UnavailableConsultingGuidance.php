<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Guidance\ConsultingGuidanceInterface;
use App\Domain\Guidance\GuidanceRequest;
use App\Domain\Guidance\GuidanceOutcome;

final class UnavailableConsultingGuidance implements ConsultingGuidanceInterface
{
    public function advise(GuidanceRequest $request): GuidanceOutcome
    {
        return GuidanceOutcome::unavailable($request, 'guidance_provider_unavailable');
    }
}
