<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Str;
use ParseError;
use UnexpectedValueException;

final class DeclarativeArrayParser
{
    public function parseFile(string $path): array
    {
        $source = FileSystem::fileContents($path);
        if (!Str::is($source)) {
            return $this->failure('mapping_unreadable', 'Mapping file could not be read.');
        }

        try {
            $tokens = $this->tokens(token_get_all($source, TOKEN_PARSE));
            if (!$this->tokenIs($tokens->shift(), T_OPEN_TAG)) {
                throw new UnexpectedValueException('Mapping must begin with a PHP opening tag.');
            }
            if ($this->tokenIs($tokens->get(0), T_DECLARE)) {
                $this->consumeStrictTypes($tokens);
            }
            if (!$this->tokenIs($tokens->shift(), T_RETURN)) {
                throw new UnexpectedValueException('Mapping must contain one return statement.');
            }

            $duplicates = Arr::make([]);
            $value = $this->consumeValue($tokens, $duplicates, '$');
            if ($tokens->shift() !== ';') {
                throw new UnexpectedValueException('Mapping may not contain executable statements.');
            }
            if ($this->tokenIs($tokens->get(0), T_CLOSE_TAG)) {
                $tokens->shift();
            }
            if (!$tokens->isEmpty()) {
                throw new UnexpectedValueException('Mapping may not contain executable statements.');
            }
            if (!Arr::is($value)) {
                throw new UnexpectedValueException('Mapping root must be an array.');
            }

            return [
                'valid' => $duplicates->isEmpty(),
                'value' => $value,
                'errors' => $duplicates
                    ->map(fn (string $key): array => [
                        'code' => 'mapping_duplicate_key',
                        'message' => "Duplicate mapping key {$key} is not allowed.",
                    ])
                    ->toArray(),
            ];
        } catch (ParseError|UnexpectedValueException $exception) {
            return $this->failure('mapping_declarative', $exception->getMessage());
        }
    }

    public function parseTopLevelKey(string $path, string $key): array
    {
        $source = FileSystem::fileContents($path);
        if (!Str::is($source)) {
            return $this->failure('mapping_unreadable', 'Mapping file could not be read.');
        }

        try {
            $tokens = $this->tokens(token_get_all($source, TOKEN_PARSE));
            if (!$this->tokenIs($tokens->shift(), T_OPEN_TAG)) {
                throw new UnexpectedValueException('Mapping must begin with a PHP opening tag.');
            }
            if ($this->tokenIs($tokens->get(0), T_DECLARE)) {
                $this->consumeStrictTypes($tokens);
            }
            if (!$this->tokenIs($tokens->shift(), T_RETURN)) {
                throw new UnexpectedValueException('Mapping must contain one return statement.');
            }

            $opening = $tokens->shift();
            if ($opening === '[') {
                $closing = ']';
            } elseif ($this->tokenIs($opening, T_ARRAY) && $tokens->shift() === '(') {
                $closing = ')';
            } else {
                throw new UnexpectedValueException('Mapping root must be an array.');
            }

            $duplicates = Arr::make([]);
            $found = false;
            $value = null;
            while (!$tokens->isEmpty() && $tokens->get(0) !== $closing) {
                $probe = Arr::make($tokens->toArray());
                $candidate = null;
                $keyed = false;
                try {
                    $candidate = $this->consumeValue($probe, Arr::make([]), '$');
                    $keyed = $this->tokenIs($probe->get(0), T_DOUBLE_ARROW);
                } catch (UnexpectedValueException) {
                    // Safe non-literal values are skipped without executing the mapping.
                }

                if ($keyed) {
                    $tokens = $probe;
                    $tokens->shift();
                    if ((string) $candidate === $key) {
                        if ($found) {
                            throw new UnexpectedValueException("Duplicate mapping key $.{$key} is not allowed.");
                        }
                        $value = $this->consumeValue($tokens, $duplicates, '$.' . $key);
                        $found = true;
                    } else {
                        $this->skipValue($tokens, $closing);
                    }
                } else {
                    $this->skipValue($tokens, $closing);
                }

                if ($tokens->get(0) === ',') {
                    $tokens->shift();
                    continue;
                }
                if ($tokens->get(0) !== $closing) {
                    throw new UnexpectedValueException('Expected a comma or closing array delimiter at $.');
                }
            }

            if ($tokens->shift() !== $closing || $tokens->shift() !== ';') {
                throw new UnexpectedValueException('Mapping may not contain executable statements.');
            }
            if ($this->tokenIs($tokens->get(0), T_CLOSE_TAG)) {
                $tokens->shift();
            }
            if (!$tokens->isEmpty()) {
                throw new UnexpectedValueException('Mapping may not contain executable statements.');
            }

            return [
                'valid' => $duplicates->isEmpty(),
                'value' => $value,
                'errors' => [],
            ];
        } catch (ParseError|UnexpectedValueException $exception) {
            return $this->failure('mapping_declarative', $exception->getMessage());
        }
    }

    private function consumeValue(Arr $tokens, Arr $duplicates, string $path): mixed
    {
        $token = $tokens->shift();
        if ($token === '(') {
            $value = $this->consumeValue($tokens, $duplicates, $path);
            if ($tokens->shift() !== ')') {
                throw new UnexpectedValueException("Unclosed parenthesized value at {$path}.");
            }

            return $value;
        }
        if ($token === '[') {
            return $this->consumeArray($tokens, $duplicates, $path, ']');
        }
        if ($this->tokenIs($token, T_ARRAY) && $tokens->shift() === '(') {
            return $this->consumeArray($tokens, $duplicates, $path, ')');
        }
        if ($this->tokenIs($token, T_CONSTANT_ENCAPSED_STRING)) {
            return $this->stringValue((string) Arr::make($token)->get(1));
        }
        if ($this->tokenIs($token, T_LNUMBER)) {
            return (int) Arr::make($token)->get(1);
        }
        if ($this->tokenIs($token, T_DNUMBER)) {
            return (float) Arr::make($token)->get(1);
        }
        if ($this->tokenIs($token, T_STRING)) {
            $literal = Str::make((string) Arr::make($token)->get(1))->lower()->val();
            if ($literal === 'true') {
                return true;
            }
            if ($literal === 'false') {
                return false;
            }
            if ($literal === 'null') {
                return null;
            }
        }

        throw new UnexpectedValueException("Unsupported value at {$path}.");
    }

    private function consumeArray(Arr $tokens, Arr $duplicates, string $path, string $closing): array
    {
        $value = [];
        $keys = Arr::make([]);
        $nextIndex = 0;

        while (!$tokens->isEmpty() && $tokens->get(0) !== $closing) {
            $candidate = $this->consumeValue($tokens, $duplicates, $path);
            if ($this->tokenIs($tokens->get(0), T_DOUBLE_ARROW)) {
                $tokens->shift();
                if (!Str::is($candidate) && !is_int($candidate)) {
                    throw new UnexpectedValueException("Array key at {$path} must be a string or integer.");
                }
                $key = $candidate;
                $item = $this->consumeValue($tokens, $duplicates, $path . '.' . (string) $key);
            } else {
                $key = $nextIndex;
                $item = $candidate;
            }

            $normalizedKey = $this->normalizedArrayKey($key);
            $identity = $this->arrayKeyIdentity($normalizedKey);
            if ($keys->has($identity, true)) {
                $duplicates->push($path . '.' . (string) $key);
            }
            $keys->push($identity);
            $value[$normalizedKey] = $item;
            if (is_int($normalizedKey) && $normalizedKey >= $nextIndex) {
                $nextIndex = $normalizedKey + 1;
            }

            if ($tokens->get(0) === ',') {
                $tokens->shift();
                continue;
            }
            if ($tokens->get(0) !== $closing) {
                throw new UnexpectedValueException("Expected a comma or closing array delimiter at {$path}.");
            }
        }

        if ($tokens->shift() !== $closing) {
            throw new UnexpectedValueException("Unclosed array at {$path}.");
        }

        return $value;
    }

    private function skipValue(Arr $tokens, string $rootClosing): void
    {
        $delimiters = Arr::make([]);
        $pairs = Arr::make(['(' => ')', '[' => ']', '{' => '}']);
        $closing = Arr::make([')' => true, ']' => true, '}' => true]);

        while (!$tokens->isEmpty()) {
            $token = $tokens->get(0);
            if ($delimiters->isEmpty() && ($token === ',' || $token === $rootClosing)) {
                return;
            }

            $token = $tokens->shift();
            if (Arr::is($token)
                && Arr::make([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])->has($token[0], true)
            ) {
                $delimiters->push('}');
                continue;
            }
            if (Str::is($token) && $pairs->hasKey($token)) {
                $delimiters->push($pairs->get($token));
                continue;
            }
            if (Str::is($token) && $closing->hasKey($token)) {
                if ($delimiters->isEmpty() || $token !== $delimiters->pop()) {
                    throw new UnexpectedValueException('Unsupported value has unbalanced delimiters.');
                }
            }
        }

        throw new UnexpectedValueException('Unsupported value is incomplete.');
    }

    private function arrayKeyIdentity(int|string $key): string
    {
        return (is_int($key) ? 'i:' : 's:') . (string) $key;
    }

    private function normalizedArrayKey(int|string $key): int|string
    {
        $normalized = [];
        $normalized[$key] = true;
        $normalizedKey = Arr::make($normalized)->keys()->get(0);

        return is_int($normalizedKey) ? $normalizedKey : (string) $normalizedKey;
    }

    private function consumeStrictTypes(Arr $tokens): void
    {
        $declare = $tokens->shift();
        $open = $tokens->shift();
        $name = $tokens->shift();
        $equals = $tokens->shift();
        $value = $tokens->shift();
        $close = $tokens->shift();
        $terminator = $tokens->shift();
        $valid = $this->tokenIs($declare, T_DECLARE)
            && $open === '('
            && $this->tokenIs($name, T_STRING)
            && Str::make((string) Arr::make((array) $name)->get(1))->lower()->val() === 'strict_types'
            && $equals === '='
            && $this->tokenIs($value, T_LNUMBER)
            && (string) Arr::make((array) $value)->get(1) === '1'
            && $close === ')'
            && $terminator === ';';
        if (!$valid) {
            throw new UnexpectedValueException('Only declare(strict_types=1) is allowed before the map.');
        }
    }

    private function stringValue(string $literal): string
    {
        $quote = Str::sub($literal, 0, 1);
        $value = Str::sub($literal, 1, -1);
        if ($quote === "'") {
            return Str::make($value)->replace('\\\\', '\\')->replace("\\'", "'")->val();
        }

        return stripcslashes($value);
    }

    private function tokens(array $tokens): Arr
    {
        return Arr::make($tokens)
            ->filter(fn ($token): bool => !Arr::is($token)
                || !Arr::make([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])->has($token[0], true))
            ->values();
    }

    private function tokenIs($token, int $type): bool
    {
        return Arr::is($token) && Arr::make($token)->get(0) === $type;
    }

    private function failure(string $code, string $message): array
    {
        return [
            'valid' => false,
            'value' => [],
            'errors' => [['code' => $code, 'message' => $message]],
        ];
    }
}
