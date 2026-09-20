<?php

declare(strict_types=1);

namespace App\Domain\Guidance;

use BlueFission\Arr;
use InvalidArgumentException;
use JsonSerializable;

final class GuidanceOutcome implements JsonSerializable
{
    public const VERSION = '1.0';
    private Arr $data;

    public function __construct(array $data)
    {
        $data = GuidanceData::snapshot($data);
        if (($data['version'] ?? self::VERSION) !== self::VERSION) {
            throw new InvalidArgumentException('guidance_version_unsupported');
        }
        $status = $data['status'] ?? 'advisory';
        if (!in_array($status, ['advisory', 'review_required', 'unavailable', 'expired', 'invalid'], true)) {
            throw new InvalidArgumentException('guidance_status_invalid');
        }
        $confidence = $data['confidence'] ?? null;
        if ($confidence !== null && ((!is_float($confidence) && !is_int($confidence)) || $confidence < 0 || $confidence > 1)) {
            throw new InvalidArgumentException('guidance_confidence_invalid');
        }
        $expires = $data['expires_at'] ?? null;
        if ($expires !== null && (!is_int($expires) || $expires < 0)) {
            throw new InvalidArgumentException('guidance_expiry_invalid');
        }
        $risk = $data['risk'] ?? 'unknown';
        if (!in_array($risk, ['low', 'medium', 'high', 'unknown'], true)) {
            throw new InvalidArgumentException('guidance_risk_invalid');
        }
        $normalized = [
            'version' => self::VERSION,
            'request_id' => GuidanceData::text($data['request_id'] ?? null, 'request_id'),
            'scope' => GuidanceData::scope($data['scope'] ?? null),
            'status' => $status,
            'recommended' => isset($data['recommended']) ? GuidanceData::text($data['recommended'], 'recommended') : null,
            'confidence' => $confidence,
            'risk' => $risk,
            'expires_at' => $expires,
            'authority' => 'none',
        ];
        foreach (['alternatives', 'reasons', 'tradeoffs', 'missing_evidence', 'value_gates', 'constraint_gates', 'required_approvals', 'audit'] as $field) {
            $normalized[$field] = GuidanceData::list($data[$field] ?? [], $field);
        }
        foreach (['reasons', 'tradeoffs', 'missing_evidence', 'required_approvals'] as $field) {
            foreach ($normalized[$field] as $text) { GuidanceData::text($text, $field); }
        }
        foreach ($normalized['alternatives'] as $candidate) {
            if (!Arr::is($candidate) || (!is_int($candidate['score'] ?? null) && !is_float($candidate['score'] ?? null))) {
                throw new InvalidArgumentException('guidance_alternative_invalid');
            }
            GuidanceData::text($candidate['id'] ?? null, 'alternative_id');
        }
        foreach (Arr::make($normalized['value_gates'])->merge($normalized['constraint_gates'])->toArray() as $gate) {
            if (!Arr::is($gate) || !in_array($gate['status'] ?? null, ['pass', 'fail', 'unknown'], true)) {
                throw new InvalidArgumentException('guidance_value_gate_invalid');
            }
            GuidanceData::text($gate['id'] ?? null, 'value_gate_id');
        }
        $this->data = Arr::make($normalized);
    }

    public static function unavailable(GuidanceRequest $request, string $reason): self
    {
        $input = $request->toArray();
        return new self(['request_id' => $input['id'], 'scope' => $input['scope'], 'status' => 'unavailable', 'reasons' => [$reason]]);
    }

    public function toArray(): array { return $this->data->toArray(); }
    public function jsonSerialize(): array { return $this->toArray(); }
}
