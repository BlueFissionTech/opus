<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Str;
use Closure;
use RuntimeException;

final class WiseIntegrationGuard
{
    public const OPTIONAL = 'optional';
    public const REQUIRED = 'required';

    private Closure $classAvailable;

    public function __construct(?callable $classAvailable = null)
    {
        $this->classAvailable = $classAvailable === null
            ? static fn (string $class): bool => class_exists($class)
            : Closure::fromCallable($classAvailable);
    }

    /**
     * @param list<class-string> $requiredTypes
     */
    public function allows(string $profile, array $requiredTypes): bool
    {
        $profile = Str::make($profile)->trim()->lower()->val();
        if (!Arr::make([self::OPTIONAL, self::REQUIRED])->contains($profile)) {
            throw new RuntimeException(
                "Unknown Wise integration profile '{$profile}'. Use 'required' or 'optional'."
            );
        }

        $missing = Arr::make([]);
        foreach ($requiredTypes as $requiredType) {
            if (!(($this->classAvailable)($requiredType))) {
                $missing->push($requiredType);
            }
        }

        if ($missing->isEmpty()) {
            return true;
        }

        if ($profile === self::OPTIONAL) {
            return false;
        }

        throw new RuntimeException(
            'Wise integration is required but unavailable. Missing types: '
            . $missing->join(', ')->val()
            . '. Install a compatible bluefission/wise release or set WISE_INTEGRATION=optional.'
        );
    }
}
