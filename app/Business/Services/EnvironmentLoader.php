<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Str;

final class EnvironmentLoader
{
    private static ?Arr $report = null;

    public static function import(string $file): Arr
    {
        $report = Arr::make([
            'source' => FileSystem::fileBasename($file),
            'loaded' => 0,
            'preserved' => 0,
            'ignored' => 0,
            'missing' => false,
            'sources' => [],
        ]);
        $contents = FileSystem::fileContents($file);
        if ($contents === null) {
            $report->set('missing', true);
            self::$report = $report;

            return $report;
        }

        $sources = Arr::make([]);
        foreach (Str::make($contents)->splitBy('/\R/u') as $variable) {
            $variable = Str::make($variable)->trim();
            if ($variable->isEmpty() || $variable->startsWith('#')) {
                continue;
            }

            $parts = $variable->split('=');
            if ($parts->count() < 2) {
                continue;
            }

            $name = Str::make((string) $parts->shift())->trim();
            if ($name->isEmpty() || !$name->matches('/^[A-Za-z_][A-Za-z0-9_]*$/')) {
                $report->set('ignored', $report->get('ignored') + 1);
                continue;
            }

            $key = $name->val();
            $value = $parts->join('=')->trim()->val();
            $existing = getenv($key);
            if ($existing !== false && $existing !== '') {
                self::synchronize($key, $existing);
                $sources->set($key, 'process');
                $report->set('preserved', $report->get('preserved') + 1);
                continue;
            }

            self::synchronize($key, $value);
            $sources->set($key, 'dotenv');
            $report->set('loaded', $report->get('loaded') + 1);
        }

        $report->set('sources', $sources->val());
        self::$report = $report;

        return $report;
    }

    public static function report(): Arr
    {
        return Arr::make(self::$report?->val() ?? []);
    }

    private static function synchronize(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
