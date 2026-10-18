<?php

declare(strict_types=1);

namespace Tests\Support;

/** Lexical call inventory; does not execute source or resolve dynamic functions. */
final class NativeHelperCalls
{
    public static function find(string $source, array $helpers): array
    {
        $tokens = array_values(array_filter(token_get_all($source), static fn ($token): bool =>
            !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $calls = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
            if (!array_key_exists($name, $helpers) || ($tokens[$index + 1] ?? null) !== '(') {
                continue;
            }
            $previous = $tokens[$index - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], [
                T_FUNCTION, T_NEW, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR,
            ], true)) {
                continue;
            }
            // A function or method can return by reference: function &count().
            if ($previous === '&' || (is_array($previous) && in_array($previous[0], [
                T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
            ], true))) {
                $beforeReference = $tokens[$index - 2] ?? null;
                if (is_array($beforeReference) && $beforeReference[0] === T_FUNCTION) {
                    continue;
                }
            }
            $calls[] = ['name' => $name, 'line' => $token[2], 'reason' => $helpers[$name]];
        }

        return $calls;
    }
}
