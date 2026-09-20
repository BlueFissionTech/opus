<?php

declare(strict_types=1);

namespace App\Domain\Guidance;

use BlueFission\Arr;
use BlueFission\Str;
use BlueFission\Net\HTTP;
use InvalidArgumentException;

/** Validation and snapshot boundary for inert advisory records. */
final class GuidanceData
{
    public static function snapshot(array $data): array
    {
        self::guard($data);
        $encoded = HTTP::jsonEncode($data);
        if (!Str::is($encoded)) {
            throw new InvalidArgumentException('guidance_serialization_failed');
        }
        $decoded = HTTP::jsonDecode($encoded, true);
        if (!Arr::is($decoded)) {
            throw new InvalidArgumentException('guidance_serialization_failed');
        }
        return $decoded;
    }

    public static function text(mixed $value, string $field): string
    {
        if (!Str::is($value) || Str::isEmpty(Str::make($value)->trim()->val())) {
            throw new InvalidArgumentException('guidance_' . $field . '_required');
        }
        return $value;
    }

    public static function scope(mixed $scope): array
    {
        if (!Arr::is($scope) || !array_key_exists('tenant', $scope)
            || ($scope['tenant'] !== null && (!Str::is($scope['tenant']) || Str::make($scope['tenant'])->trim()->val() === ''))
        ) {
            throw new InvalidArgumentException('guidance_scope_invalid');
        }
        return [
            'application' => self::text($scope['application'] ?? null, 'application'),
            'tenant' => $scope['tenant'],
            'principal' => self::text($scope['principal'] ?? null, 'principal'),
        ];
    }

    public static function list(mixed $value, string $field): array
    {
        if (!Arr::is($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('guidance_' . $field . '_list_required');
        }
        return $value;
    }

    private static function guard(mixed $value, int $depth = 0): void
    {
        if ($depth > 8 || is_object($value) || is_resource($value)
            || (is_float($value) && !is_finite($value))
            || (is_string($value) && strlen($value) > 16384)
        ) {
            throw new InvalidArgumentException('guidance_inert_payload_required');
        }
        if (Arr::is($value)) {
            if (Arr::make($value)->count() > 256) {
                throw new InvalidArgumentException('guidance_payload_too_large');
            }
            foreach ($value as $item) {
                self::guard($item, $depth + 1);
            }
        }
    }
}
