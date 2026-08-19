<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Str;

final class EnvironmentLoader
{
    public static function import(string $file): void
    {
        $variables = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($variables === false) {
            return;
        }

        foreach ($variables as $variable) {
            $variable = Str::make($variable)->trim();
            if ($variable->isEmpty() || $variable->startsWith('#')) {
                continue;
            }

            $parts = $variable->split('=');
            if ($parts->count() < 2) {
                continue;
            }

            $name = Str::make((string) $parts->shift())->trim();
            if ($name->isEmpty()) {
                continue;
            }

            $value = $parts->join('=')->trim()->val();
            putenv($name->val() . '=' . $value);
            $_ENV[$name->val()] = $value;
        }
    }
}
