<?php

declare(strict_types=1);

namespace Opus\Tools;

use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/** Passive source evidence, not runtime type inference or a compatibility verdict. */
final class DevElationUsageInventory
{
    public const FAMILIES = ['Val', 'Str', 'Arr', 'Num', 'Flag', 'Date', 'Obj', 'Data', 'Service', 'Behavior', 'Hooks'];
    public const ROOTS = ['app', 'common', 'mapping'];

    public function audit(string $root): array
    {
        if (!class_exists(PhpToken::class, false) || !method_exists(PhpToken::class, 'tokenize')) {
            throw new RuntimeException('inventory_tokenizer_unavailable');
        }
        $root = realpath($root) ?: throw new RuntimeException('inventory_root_unavailable');
        $files = [];
        $errors = [];
        foreach (self::ROOTS as $directory) {
            $path = $root . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($path) || is_link($path)) {
                $errors[] = ['path' => $directory, 'reason' => 'source_directory_unavailable'];
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,
                    RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($iterator as $file) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    if ($file->isLink()) {
                        $errors[] = ['path' => $relative, 'reason' => 'source_link_not_followed'];
                    } elseif ($file->isFile() && $file->getExtension() === 'php') {
                        $source = @file_get_contents($file->getPathname());
                        if ($source === false) {
                            $errors[] = ['path' => $relative, 'reason' => 'source_unreadable'];
                            continue;
                        }
                        try {
                            $files[$relative] = $this->inspect($source, $relative);
                        } catch (Throwable) {
                            $errors[] = ['path' => $relative, 'reason' => 'source_parse_failed'];
                        }
                    }
                }
            } catch (Throwable) {
                $errors[] = ['path' => $directory, 'reason' => 'source_directory_unreadable'];
            }
        }
        ksort($files);
        $modules = [];
        foreach ($files as $file) {
            $module = dirname($file['path']);
            $modules[$module] ??= ['files' => 0, 'references' => array_fill_keys(self::FAMILIES, 0),
                'fluent_chains' => 0, 'behavior_candidates' => 0, 'declarations' => 0];
            $modules[$module]['files']++;
            foreach ($file['references'] as $reference) {
                $modules[$module]['references'][$reference['family']]++;
            }
            $modules[$module]['fluent_chains'] += count($file['fluent_chains']);
            $modules[$module]['behavior_candidates'] += count($file['behavior_candidates']);
            $modules[$module]['declarations'] += count($file['declarations']);
        }
        ksort($modules);
        usort($errors, static fn (array $a, array $b): int => [$a['path'], $a['reason']] <=> [$b['path'], $b['reason']]);
        return ['schema_version' => 1, 'complete' => $errors === [], 'scope' => self::ROOTS,
            'evidence' => 'lexical_source_only',
            'limitations' => ['No variable receiver or dynamic callable type inference.',
                'Imports are declarations, not proof of execution; instance-only and return-type references are not enumerated.',
                'Fluent chains cover direct static roots; parenthesized receivers are not inferred.',
                'Behavior candidates match instance method names only; receiver ownership is unresolved.',
                'Complete means all scoped PHP files were read and parsed, not compatibility or policy conformance.'],
            'file_count' => count($files), 'modules' => $modules, 'files' => array_values($files), 'errors' => $errors];
    }

    public function inspect(string $source, string $path): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source, TOKEN_PARSE),
            static fn (PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        $imports = [];
        $namespace = '';
        $depth = 0;
        $namespaceDepth = 0;
        $references = $chains = $behaviors = $declarations = [];
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            if ($token->id === T_NAMESPACE) {
                $imports = [];
                $namespace = '';
                while (isset($tokens[++$i]) && !$tokens[$i]->is([';', '{'])) {
                    $namespace .= $tokens[$i]->text;
                }
                $namespaceDepth = $tokens[$i]->text === '{' ? ++$depth : $depth;
                continue;
            }
            if ($token->id === T_USE && $depth === $namespaceDepth && $next?->text !== '(') {
                $parts = [];
                while (isset($tokens[++$i]) && $tokens[$i]->text !== ';') {
                    $parts[] = $tokens[$i]->text;
                }
                foreach ($this->imports(implode(' ', $parts)) as $alias => $name) {
                    $imports[$alias] = $name;
                    if (($family = $this->family($name)) !== null) {
                        $references[] = ['line' => $token->line, 'family' => $family, 'symbol' => $name, 'kind' => 'import'];
                    }
                }
                continue;
            }
            if ($token->is([ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->id === ord('}')) {
                $depth--;
            }
            if ($token->is([T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                $nameIndex = $i + 1;
                if (($tokens[$nameIndex] ?? null)?->is([T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
                    T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG])) {
                    $nameIndex++;
                }
                if (($tokens[$nameIndex] ?? null)?->id === T_STRING && $previous?->id !== T_DOUBLE_COLON) {
                    $declarations[] = ['line' => $token->line, 'kind' => strtolower(substr($token->getTokenName(), 2)),
                        'name' => $tokens[$nameIndex]->text];
                }
            }
            if (!$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                continue;
            }
            if ($previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) && $next?->text === '('
                && in_array(strtolower($token->text), ['behavior', 'dispatch', 'when', 'perform', 'is', 'halt'], true)) {
                $behaviors[] = ['line' => $token->line, 'method' => $token->text, 'receiver' => 'unresolved'];
            }
            $kind = $next?->id === T_DOUBLE_COLON ? 'static_access'
                : ($previous?->id === T_NEW ? 'construction'
                    : ($previous?->is([T_EXTENDS, T_IMPLEMENTS]) ? 'inheritance'
                        : ($next?->id === T_VARIABLE ? 'type' : null)));
            if ($kind === null) {
                continue;
            }
            $name = $this->resolve($token->text, $namespace, $imports);
            if (($family = $this->family($name)) === null) {
                continue;
            }
            $reference = ['line' => $token->line, 'family' => $family, 'symbol' => $name, 'kind' => $kind];
            if ($kind === 'static_access' && ($tokens[$i + 2] ?? null)?->id === T_STRING) {
                $reference['member'] = $tokens[$i + 2]->text;
                $chain = $this->chain($tokens, $i + 2);
                if (count($chain) > 1) {
                    $chains[] = ['line' => $token->line, 'family' => $family, 'symbol' => $name, 'methods' => $chain];
                }
            }
            $references[] = $reference;
        }
        return ['path' => $path, 'sha256' => hash('sha256', $source), 'references' => $references,
            'fluent_chains' => $chains, 'behavior_candidates' => $behaviors, 'declarations' => $declarations];
    }

    private function imports(string $source): array
    {
        $source = preg_replace('/\s*\\\\\s*/', '\\', trim($source));
        if (preg_match('/^(function|const)\s/i', $source)) {
            return [];
        }
        $prefix = '';
        if (($open = strpos($source, '{')) !== false) {
            $prefix = substr($source, 0, $open);
            $source = substr($source, $open + 1, strrpos($source, '}') - $open - 1);
        }
        $imports = [];
        foreach (explode(',', $source) as $part) {
            $part = trim($part);
            if (preg_match('/^(function|const)\s/i', $part)) {
                continue;
            }
            $pieces = preg_split('/\s+as\s+/i', $part);
            $name = ltrim($prefix . trim($pieces[0]), '\\');
            $segments = explode('\\', $name);
            $imports[strtolower(trim($pieces[1] ?? end($segments)))] = $name;
        }
        return $imports;
    }

    private function resolve(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return ltrim($namespace . '\\' . substr($name, 10), '\\');
        }
        $parts = explode('\\', $name, 2);
        return isset($imports[strtolower($parts[0])])
            ? $imports[strtolower($parts[0])] . (isset($parts[1]) ? '\\' . $parts[1] : '')
            : ltrim($namespace . '\\' . $name, '\\');
    }

    private function family(string $name): ?string
    {
        foreach (['Val', 'Str', 'Arr', 'Num', 'Flag', 'Date', 'Obj'] as $primitive) {
            if (strcasecmp($name, 'BlueFission\\' . $primitive) === 0) {
                return $primitive;
            }
        }
        foreach (['Data\\' => 'Data', 'Services\\' => 'Service', 'Behavioral\\' => 'Behavior'] as $prefix => $family) {
            if (str_starts_with(strtolower($name), strtolower('BlueFission\\' . $prefix))) {
                return $family;
            }
        }
        return strcasecmp($name, 'BlueFission\\DevElation') === 0 ? 'Hooks' : null;
    }

    private function chain(array $tokens, int $index): array
    {
        $methods = [];
        while (($tokens[$index] ?? null)?->id === T_STRING && ($tokens[$index + 1] ?? null)?->text === '(') {
            $methods[] = $tokens[$index]->text;
            $index += 2;
            $depth = 1;
            while (isset($tokens[$index]) && $depth > 0) {
                $depth += $tokens[$index]->text === '(' ? 1 : ($tokens[$index]->text === ')' ? -1 : 0);
                $index++;
            }
            if (!($tokens[$index] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                break;
            }
            $index++;
        }
        return $methods;
    }
}
