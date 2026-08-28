<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Business\Presentation\VibeValue;
use BlueFission\Arr;
use BlueFission\Net\HTTP;
use BlueFission\Str;

final class VibeContextPolicy
{
    private Arr $allowedUrlSchemes;

    /** @param list<string> $allowedUrlSchemes */
    public function __construct(array $allowedUrlSchemes = ['http', 'https', 'mailto', 'tel'])
    {
        $this->allowedUrlSchemes = Arr::make($allowedUrlSchemes)->map(
            static fn (string $scheme): string => Str::lower(Str::trim($scheme))
        );
    }

    public function protect(mixed $value): mixed
    {
        if ($value instanceof VibeValue) {
            return match ($value->context()) {
                VibeValue::URL => $this->protectUrl($value->value()),
                VibeValue::SCRIPT => $this->protectScript($value->value()),
                VibeValue::TRUSTED_MARKUP => $this->trustedMarkup($value->value()),
                default => throw new \InvalidArgumentException('Unknown Vibe value context.'),
            };
        }

        if (Arr::is($value)) {
            return Arr::toArray(Arr::make($value)->map(
                fn (mixed $item): mixed => $this->protect($item)
            ), true);
        }

        if (Str::is($value)) {
            return $this->escapeHtml((string) Str::make($value));
        }

        if (is_object($value) || is_resource($value)) {
            throw new \InvalidArgumentException(
                'Object and resource template values require an explicit supported Vibe value context.'
            );
        }

        return $value;
    }

    private function protectUrl(mixed $value): string
    {
        if (!Str::is($value)) {
            throw new \InvalidArgumentException('Vibe URL values must be strings.');
        }

        $url = Str::make($value)->trim();
        $rawUrl = $url->val();

        if (
            preg_match('/[\x00-\x20\x7F]/', $rawUrl) === 1
            || preg_match('~^[\\\\/]{2}~', $rawUrl) === 1
        ) {
            throw new \InvalidArgumentException('Vibe URL values must not contain controls or protocol-relative targets.');
        }

        $scheme = HTTP::urlScheme($rawUrl);
        if (
            Str::isNotEmpty($scheme)
            && !$this->allowedUrlSchemes->contains(Str::lower((string) $scheme))
        ) {
            throw new \InvalidArgumentException("Vibe URL scheme '{$scheme}' is not allowed.");
        }

        return $this->escapeHtml($rawUrl);
    }

    private function protectScript(mixed $value): string
    {
        $this->assertScriptValue($value);

        $encoded = HTTP::jsonEncode($value);
        if (!Str::is($encoded)) {
            throw new \InvalidArgumentException('Vibe script value could not be encoded.');
        }

        return Str::make($encoded)
            ->replace('&', '\\u0026')
            ->replace('<', '\\u003C')
            ->replace('>', '\\u003E')
            ->replace("'", '\\u0027')
            ->val();
    }

    private function assertScriptValue(mixed $value): void
    {
        if (Arr::is($value)) {
            Arr::make($value)->each(fn (mixed $item): mixed => $this->validateScriptItem($item));

            return;
        }

        if (is_object($value) || is_resource($value)) {
            throw new \InvalidArgumentException('Vibe script values must be JSON-compatible scalars or arrays.');
        }
    }

    private function validateScriptItem(mixed $value): mixed
    {
        $this->assertScriptValue($value);

        return $value;
    }

    private function trustedMarkup(mixed $value): string
    {
        if (!Str::is($value)) {
            throw new \InvalidArgumentException('Trusted Vibe markup must be a string.');
        }

        return (string) Str::make($value);
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
