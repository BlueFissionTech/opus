<?php

declare(strict_types=1);

namespace App\Domain\Guidance;

use BlueFission\Arr;
use InvalidArgumentException;
use JsonSerializable;

final class GuidanceRequest implements JsonSerializable
{
    public const VERSION = '1.0';
    private Arr $data;

    public function __construct(array $data)
    {
        $data = GuidanceData::snapshot($data);
        if (($data['version'] ?? self::VERSION) !== self::VERSION) {
            throw new InvalidArgumentException('guidance_version_unsupported');
        }
        $scope = GuidanceData::scope($data['scope'] ?? null);
        $candidates = GuidanceData::list($data['candidates'] ?? [], 'candidates');
        $ids = [];
        foreach ($candidates as $candidate) {
            if (!Arr::is($candidate)) {
                throw new InvalidArgumentException('guidance_candidate_invalid');
            }
            $id = GuidanceData::text($candidate['id'] ?? null, 'candidate_id');
            if (in_array($id, $ids, true)) {
                throw new InvalidArgumentException('guidance_candidate_duplicate');
            }
            $ids[] = $id;
        }
        $evidence = GuidanceData::list($data['evidence'] ?? [], 'evidence');
        $evidenceIds = [];
        foreach ($evidence as $record) {
            if (!Arr::is($record) || !in_array($record['status'] ?? null, ['current', 'stale', 'missing', 'unknown'], true)
                || (isset($record['expires_at']) && (!is_int($record['expires_at']) || $record['expires_at'] < 0))
            ) {
                throw new InvalidArgumentException('guidance_evidence_invalid');
            }
            $id = GuidanceData::text($record['id'] ?? null, 'evidence_id');
            GuidanceData::text($record['reference'] ?? null, 'evidence_reference');
            if (in_array($id, $evidenceIds, true)) {
                throw new InvalidArgumentException('guidance_evidence_duplicate');
            }
            $evidenceIds[] = $id;
        }
        $policy = $data['approval_policy'] ?? ['required' => true];
        if (!Arr::is($policy) || !is_bool($policy['required'] ?? null)) {
            throw new InvalidArgumentException('guidance_approval_policy_invalid');
        }
        foreach (['constraints', 'regime', 'efficacy'] as $field) {
            if (isset($data[$field]) && !Arr::is($data[$field])) {
                throw new InvalidArgumentException('guidance_' . $field . '_invalid');
            }
        }
        $this->data = Arr::make([
            'version' => self::VERSION,
            'id' => GuidanceData::text($data['id'] ?? null, 'request_id'),
            'question' => GuidanceData::text($data['question'] ?? null, 'question'),
            'scope' => $scope,
            'evidence' => $evidence,
            'constraints' => $data['constraints'] ?? [],
            'regime' => $data['regime'] ?? [],
            'efficacy' => $data['efficacy'] ?? [],
            'candidates' => $candidates,
            'approval_policy' => $policy,
        ]);
    }

    public function toArray(): array { return $this->data->toArray(); }
    public function jsonSerialize(): array { return $this->toArray(); }
}
