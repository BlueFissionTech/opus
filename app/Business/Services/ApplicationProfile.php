<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Str;
use InvalidArgumentException;

final class ApplicationProfile
{
    public function __construct(public readonly string $name, public readonly array $addons = [])
    {
        if (!Arr::has(['platform', 'api', 'dashboard'], $name, true)) {
            throw new InvalidArgumentException('application_profile_invalid');
        }
        foreach ($addons as $addon) {
            if (!Str::is($addon) || !Str::matches($addon, '/^[a-z][a-z0-9_-]*$/D')) {
                throw new InvalidArgumentException('application_addon_selection_invalid');
            }
        }
    }

    public static function requested(): bool
    {
        $name = getenv('OPUS_PROFILE');
        return $name !== false && $name !== '' && $name !== 'platform';
    }

    public static function current(): self
    {
        $name = getenv('OPUS_PROFILE');
        $selection = getenv('OPUS_ADDONS');
        $addons = $selection === false || $selection === ''
            ? [] : Str::make($selection)->split(',')->toArray();
        return new self($name === false || $name === '' ? 'platform' : $name, $addons);
    }

    public function minimal(): bool
    {
        return $this->name !== 'platform';
    }
}
