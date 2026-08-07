<?php

namespace App\Business\Services;

final class EnvironmentLoader
{
    public static function import(string $file): void
    {
        $variables = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($variables === false) {
            return;
        }

        foreach ($variables as $variable) {
            $variable = trim($variable);
            if ($variable === '' || str_starts_with($variable, '#')) {
                continue;
            }

            $separator = strpos($variable, '=');
            if ($separator === false) {
                continue;
            }

            $name = trim(substr($variable, 0, $separator));
            if ($name === '') {
                continue;
            }

            $value = trim(substr($variable, $separator + 1));
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}
