<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Str;

final class EnvironmentLoader
{
    public const SOURCE_DOTENV = 'dotenv';
    public const SOURCE_PROCESS = 'process';

    private static array $loadedValues = [];
    private static array $sources = [];
    private static array $lastReport = [
        'status' => 'not_loaded',
        'loaded' => [],
        'preserved' => [],
        'ignored' => 0,
    ];

    public static function import(string $file): void
    {
        self::$lastReport = self::load($file);
    }

    public static function lastReport(): array
    {
        return self::$lastReport;
    }

    public static function sourceOf(string $name): ?string
    {
        return self::$sources[$name] ?? null;
    }

    private static function load(string $file): array
    {
        $report = [
            'status' => 'missing',
            'loaded' => [],
            'preserved' => [],
            'ignored' => 0,
        ];
        $contents = FileSystem::fileContents($file);
        if (!Str::is($contents)) {
            return $report;
        }

        $report['status'] = 'loaded';
        $variables = Str::make($contents)->split("\n");

        $variables->each(function (string $line) use (&$report): void {
            $variable = Str::make($line)->trim();
            if ($variable->isEmpty() || $variable->startsWith('#')) {
                return;
            }

            $parts = $variable->split('=');
            if ($parts->count() < 2) {
                $report['ignored']++;
                return;
            }

            $name = Str::make((string) $parts->shift())->trim();
            if ($name->isEmpty()) {
                $report['ignored']++;
                return;
            }

            $key = $name->val();
            $existing = self::existingValue($key);
            if ($existing !== null) {
                self::write($key, $existing);
                $source = self::isPreviouslyLoadedValue($key, $existing)
                    ? self::SOURCE_DOTENV
                    : self::SOURCE_PROCESS;
                self::$sources[$key] = $source;
                if ($source === self::SOURCE_PROCESS) {
                    unset(self::$loadedValues[$key]);
                }
                $report['preserved'][] = ['name' => $key, 'source' => $source];
                return;
            }

            $value = $parts->join('=')->trim()->val();
            self::write($key, $value);
            self::$loadedValues[$key] = $value;
            self::$sources[$key] = self::SOURCE_DOTENV;
            $report['loaded'][] = ['name' => $key, 'source' => self::SOURCE_DOTENV];
        });

        return $report;
    }

    private static function existingValue(string $name): ?string
    {
        $process = getenv($name);
        if ($process !== false && $process !== '') {
            return (string) $process;
        }

        $environment = Arr::make($_ENV);
        $environmentValue = $environment->get($name);
        if ($environment->hasKey($name) && Str::is($environmentValue) && $environmentValue !== '') {
            return $environmentValue;
        }

        $server = Arr::make($_SERVER);
        $serverValue = $server->get($name);
        if ($server->hasKey($name) && Str::is($serverValue) && $serverValue !== '') {
            return $serverValue;
        }

        return null;
    }

    private static function isPreviouslyLoadedValue(string $name, string $value): bool
    {
        return Arr::make(self::$loadedValues)->hasKey($name)
            && self::$loadedValues[$name] === $value;
    }

    private static function write(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
