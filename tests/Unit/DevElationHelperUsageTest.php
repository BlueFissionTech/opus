<?php

declare(strict_types=1);

namespace Tests\Unit;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Str;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DevElationHelperUsageTest extends TestCase
{
    private const FORBIDDEN_APPLICATION_HELPERS = [
        'json_encode' => 'native JSON helper',
        'json_decode' => 'native JSON helper',
        'array_keys' => 'native array inspection helper',
        'array_column' => 'native array inspection helper',
        'array_values' => 'native array inspection helper',
        'is_array' => 'native array inspection helper',
        'str_replace' => 'native string transformation helper',
        'strtolower' => 'native string transformation helper',
        'strtoupper' => 'native string transformation helper',
        'str_starts_with' => 'native string transformation helper',
        'str_ends_with' => 'native string transformation helper',
        'trim' => 'native string normalization helper',
        'strpos' => 'native string inspection helper',
        'explode' => 'native string splitting helper',
        'implode' => 'native array joining helper',
        'count' => 'native array counting helper',
        'compact' => 'native array construction helper',
    ];

    public function testApplicationCodeUsesPackageOwnedValueHelpers(): void
    {
        $violations = Arr::make([]);
        $forbidden = Arr::make(self::FORBIDDEN_APPLICATION_HELPERS);
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = FileSystem::fileContents($file->getPathname());
            if (!Str::is($source)) {
                $violations->push($file->getPathname() . ': unreadable');
                continue;
            }

            $tokens = Arr::make(token_get_all($source));
            foreach ($tokens as $index => $token) {
                if (!Arr::is($token) || Arr::make($token)->get(0) !== T_STRING) {
                    continue;
                }

                $name = Str::lower((string) Arr::make($token)->get(1));
                $previous = $this->previousToken($tokens, $index);
                if (!$forbidden->hasKey($name)
                    || $this->nextToken($tokens, $index) !== '('
                    || (Arr::is($previous) && Arr::make([
                        T_DOUBLE_COLON,
                        T_OBJECT_OPERATOR,
                        T_NULLSAFE_OBJECT_OPERATOR,
                    ])->has(Arr::make($previous)->get(0), true))
                ) {
                    continue;
                }

                $violations->push(
                    $file->getPathname() . ': ' . $forbidden->get($name) . ' (' . $name . ')'
                );
            }
        }

        $this->assertSame([], $violations->val(), $violations->join(PHP_EOL)->val());
    }

    private function nextToken(Arr $tokens, int $index): mixed
    {
        for ($offset = $index + 1; $offset < $tokens->count(); $offset++) {
            $token = $tokens->get($offset);
            if (Arr::is($token) && Arr::make([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])->has(
                Arr::make($token)->get(0),
                true
            )) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private function previousToken(Arr $tokens, int $index): mixed
    {
        for ($offset = $index - 1; $offset >= 0; $offset--) {
            $token = $tokens->get($offset);
            if (Arr::is($token) && Arr::make([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])->has(
                Arr::make($token)->get(0),
                true
            )) {
                continue;
            }

            return $token;
        }

        return null;
    }
}
