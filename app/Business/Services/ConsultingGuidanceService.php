<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Guidance\ConsultingGuidanceInterface;
use App\Domain\Guidance\GuidanceData;
use App\Domain\Guidance\GuidanceRequest;
use App\Domain\Guidance\GuidanceOutcome;
use BlueFission\Arr;
use InvalidArgumentException;
use Throwable;

final class ConsultingGuidanceService
{
    public function __construct(private ?ConsultingGuidanceInterface $provider = null) {}

    public function advise(GuidanceRequest $request, int $now): GuidanceOutcome
    {
        if ($now < 0) { throw new InvalidArgumentException('guidance_time_invalid'); }
        try {
            $outcome = $this->provider?->advise($request)
                ?? GuidanceOutcome::unavailable($request, 'guidance_provider_unavailable');
        } catch (Throwable) {
            $outcome = GuidanceOutcome::unavailable($request, 'guidance_provider_failed');
        }
        return $this->validate($request, $outcome, $now);
    }

    public function override(GuidanceRequest $request, GuidanceOutcome $outcome, string $candidateId, string $reason, int $now): GuidanceOutcome
    {
        GuidanceData::text($reason, 'override_reason');
        $input = $request->toArray();
        if (!in_array($candidateId, Arr::make($input['candidates'])->map(static fn (array $candidate): string => $candidate['id'])->values()->toArray(), true)) {
            throw new InvalidArgumentException('guidance_override_candidate_unknown');
        }
        $data = $this->validate($request, $outcome, $now)->toArray();
        if (in_array($data['status'], ['invalid', 'expired', 'unavailable'], true)) {
            return new GuidanceOutcome($data);
        }
        $data['audit'][] = ['kind' => 'caller_override', 'principal' => $input['scope']['principal'], 'prior_recommended' => $data['recommended'], 'reason' => $reason];
        $data['recommended'] = $candidateId;
        $data['status'] = 'review_required';
        $data['required_approvals'] = Arr::make($data['required_approvals'])->merge(['execution_policy'])->unique()->values()->toArray();
        return new GuidanceOutcome($data);
    }

    private function validate(GuidanceRequest $request, GuidanceOutcome $outcome, int $now): GuidanceOutcome
    {
        if ($now < 0) { throw new InvalidArgumentException('guidance_time_invalid'); }
        $input = $request->toArray();
        $data = $outcome->toArray();
        if ($data['request_id'] !== $input['id'] || $data['scope'] !== $input['scope']) {
            $data = $this->reject($request, 'invalid', 'guidance_scope_mismatch')->toArray();
        }
        $candidateIds = Arr::make($input['candidates'])->map(static fn (array $candidate): string => $candidate['id'])->values()->toArray();
        $rankedIds = Arr::make($data['alternatives'])->map(static fn (array $candidate): string => $candidate['id'])->values()->toArray();
        if (Arr::make($rankedIds)->unique()->count() !== Arr::make($rankedIds)->count()
            || array_diff($rankedIds, $candidateIds) !== []
            || ($data['recommended'] !== null && !in_array($data['recommended'], $candidateIds, true))
        ) {
            $data = $this->reject($request, 'invalid', 'guidance_candidate_unknown')->toArray();
        }
        if ($data['expires_at'] !== null && $data['expires_at'] <= $now) {
            $data['status'] = 'expired';
            $data['reasons'][] = 'guidance_expired';
        }
        if (in_array($data['status'], ['invalid', 'expired', 'unavailable'], true)) {
            $data['recommended'] = null;
            $data['alternatives'] = [];
            $data['confidence'] = null;
        }
        foreach ($input['evidence'] as $evidence) {
            if ($evidence['status'] !== 'current' || (isset($evidence['expires_at']) && $evidence['expires_at'] <= $now)) {
                $data['missing_evidence'][] = $evidence['id'];
            }
        }
        $data['missing_evidence'] = Arr::make($data['missing_evidence'])->unique()->values()->toArray();
        if ($input['approval_policy']['required']) { $data['required_approvals'][] = 'host_policy'; }
        $data['required_approvals'] = Arr::make($data['required_approvals'])->unique()->values()->toArray();
        $failedGate = false;
        foreach (Arr::make($data['value_gates'])->merge($data['constraint_gates'])->toArray() as $gate) { $failedGate = $failedGate || $gate['status'] !== 'pass'; }
        if (in_array($data['status'], ['advisory', 'review_required'], true)
            && ($data['missing_evidence'] !== [] || $data['required_approvals'] !== [] || $failedGate)
        ) {
            $data['status'] = 'review_required';
        }
        if ($data['missing_evidence'] !== []) { $data['confidence'] = null; }
        return new GuidanceOutcome($data);
    }

    private function reject(GuidanceRequest $request, string $status, string $reason): GuidanceOutcome
    {
        $data = GuidanceOutcome::unavailable($request, $reason)->toArray();
        $data['status'] = $status;
        return new GuidanceOutcome($data);
    }
}
