<?php

declare(strict_types=1);

namespace App\Business\Presentation;

final class VibeValue
{
    public const URL = 'url';

    public const SCRIPT = 'script';

    public const TRUSTED_MARKUP = 'trusted_markup';

    private function __construct(
        private readonly string $context,
        private readonly mixed $value
    ) {
    }

    public static function url(string $value): self
    {
        return new self(self::URL, $value);
    }

    public static function script(mixed $value): self
    {
        return new self(self::SCRIPT, $value);
    }

    public static function trustedMarkup(string $value): self
    {
        return new self(self::TRUSTED_MARKUP, $value);
    }

    public function context(): string
    {
        return $this->context;
    }

    public function value(): mixed
    {
        return $this->value;
    }
}
