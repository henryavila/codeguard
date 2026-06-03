<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * Cheap, dependency-free facts about a PHP source file, used by
 * {@see PatternMatcher} to make `import` detection signals real (match a file's
 * actual `use` imports, not an approximated path prefix) and to gate
 * class-structure patterns to files that actually declare a class.
 *
 * Import extraction scans only the file head (before the first class-like
 * declaration), so trait `use` inside a class body and closure `use (...)` are
 * excluded by construction.
 */
final class PhpFileInspector
{
    /**
     * Fully-qualified class names imported via top-level `use` statements
     * (aliases resolved to the FQCN, leading backslash stripped, `use function`
     * / `use const` ignored).
     *
     * @return list<string>
     */
    public static function imports(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        $head = $contents;
        if (preg_match('/\b(?:class|interface|trait|enum)\s+\w/i', $contents, $m, PREG_OFFSET_CAPTURE) === 1) {
            $head = substr($contents, 0, (int) $m[0][1]);
        }

        if (preg_match_all('/^[ \t]*use[ \t]+(?!function[ \t]|const[ \t])([^;]+);/mi', $head, $matches) === false) {
            return [];
        }

        $imports = [];
        foreach ($matches[1] as $clause) {
            foreach (self::expandUseClause($clause) as $fqcn) {
                $imports[] = $fqcn;
            }
        }

        return array_values(array_unique($imports));
    }

    /**
     * Whether the file declares a class / trait / enum (not merely an interface
     * or a bag of free functions) — the floor for class-structure patterns.
     */
    public static function declaresClass(string $contents): bool
    {
        return preg_match('/\b(?:class|trait|enum)\s+\w/i', $contents) === 1;
    }

    /**
     * The fully-qualified name of the type this file declares — `namespace`
     * (if any) + the first declared `class|interface|trait|enum`. The node id
     * for the namespace graph (R3). Null when the file declares no type.
     */
    public static function fqcn(string $contents): ?string
    {
        if (preg_match('/\b(?:class|interface|trait|enum)\s+(\w+)/i', $contents, $cm) !== 1) {
            return null;
        }

        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;{]+)[;{]/mi', $contents, $nm) === 1) {
            $namespace = trim($nm[1]);
        }

        return $namespace === '' ? $cm[1] : $namespace.'\\'.$cm[1];
    }

    /**
     * @return list<string>
     */
    private static function expandUseClause(string $clause): array
    {
        $clause = trim($clause);

        // Group use: Prefix\{A, B as C, D\E}
        if (preg_match('/^(.+?)\\\\\{(.+)\}$/s', $clause, $m) === 1) {
            $prefix = trim($m[1]);

            return array_values(array_filter(
                array_map(
                    static fn (string $part): string => ltrim($prefix.'\\'.self::stripAlias($part), '\\'),
                    explode(',', $m[2]),
                ),
                static fn (string $name): bool => $name !== '' && ! str_ends_with($name, '\\'),
            ));
        }

        return array_values(array_filter(
            array_map(
                static fn (string $part): string => ltrim(self::stripAlias($part), '\\'),
                explode(',', $clause),
            ),
            static fn (string $name): bool => $name !== '',
        ));
    }

    private static function stripAlias(string $segment): string
    {
        return trim(preg_replace('/\s+as\s+\w+\s*$/i', '', trim($segment)) ?? $segment);
    }
}
