<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * Pre-filters which patterns apply to which files via `detection.signals`,
 * producing {@see AnalysisUnit}s. Only files matched by at least one pattern
 * become units — so the LLM only ever sees relevant code.
 *
 * Signal handling:
 *  - file      → glob (`**`, `*`, `?`, `{a,b}`) against the relative path
 *  - directory → relative-path prefix
 *  - import    → matches the file's ACTUAL `use` imports (via {@see PhpFileInspector}).
 *                A value of `**`/`**​/*`/`*` means "any import" — i.e. a whole-project
 *                architectural signal that a per-file pass cannot satisfy; those
 *                patterns are therefore NOT selected until a cross-file namespace
 *                graph exists (design doc R3).
 *
 * Class-structure patterns (god-object, single-responsibility, …) are gated to
 * files that actually declare a class, so config arrays, route files and
 * pure-function helpers are not pointlessly reviewed for them.
 */
final class PatternMatcher
{
    /**
     * Patterns that only make sense for a file declaring a class.
     *
     * @var list<string>
     */
    private const CLASS_STRUCTURE_PATTERNS = [
        'no-god-object',
        'single-responsibility',
        'no-deep-inheritance',
        'no-constructor-many-params',
    ];

    public function __construct(private readonly string $workingDirectory) {}

    /**
     * @param  list<string>  $files  Absolute paths.
     * @param  list<Pattern>  $patterns
     * @return list<AnalysisUnit>
     */
    public function match(array $files, array $patterns): array
    {
        $units = [];

        foreach ($files as $file) {
            $relative = $this->relative($file);
            $contents = is_file($file) ? (file_get_contents($file) ?: '') : '';
            $imports = PhpFileInspector::imports($contents);
            $declaresClass = PhpFileInspector::declaresClass($contents);

            $matched = array_values(array_filter(
                $patterns,
                function (Pattern $pattern) use ($relative, $imports, $declaresClass): bool {
                    if (! $declaresClass && in_array($pattern->key, self::CLASS_STRUCTURE_PATTERNS, true)) {
                        return false;
                    }

                    return $this->patternApplies($pattern, $relative, $imports);
                },
            ));

            if ($matched !== []) {
                $units[] = new AnalysisUnit($file, $matched);
            }
        }

        return $units;
    }

    /**
     * @param  list<string>  $imports
     */
    private function patternApplies(Pattern $pattern, string $relativePath, array $imports): bool
    {
        foreach ($pattern->detectionSignals as $signal) {
            if ($this->signalMatches($signal, $relativePath, $imports)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $imports
     */
    private function signalMatches(DetectionSignal $signal, string $relativePath, array $imports): bool
    {
        return match ($signal->type) {
            'file' => preg_match($this->globToRegex($signal->value), $relativePath) === 1,
            'directory' => str_starts_with($relativePath, rtrim($signal->value, '/').'/'),
            'import' => $this->importMatches($imports, $signal->value),
            default => false,
        };
    }

    /**
     * @param  list<string>  $imports
     */
    private function importMatches(array $imports, string $value): bool
    {
        // Catch-all import = whole-project architectural signal; a per-file pass
        // cannot judge it without the cross-file graph (R3), so it selects nothing.
        if ($value === '*' || $value === '**' || $value === '**/*') {
            return false;
        }

        if (str_ends_with($value, '\\*')) {
            $prefix = substr($value, 0, -1); // "App\Services\"
            foreach ($imports as $fqcn) {
                if (str_starts_with($fqcn, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        $exact = ltrim($value, '\\');
        $prefix = rtrim($exact, '\\').'\\';
        foreach ($imports as $fqcn) {
            if ($fqcn === $exact || str_starts_with($fqcn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function globToRegex(string $glob): string
    {
        // `**/` matches zero or more directories.
        $glob = str_replace('**/', "\x01", $glob);

        $regex = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];
            $regex .= match (true) {
                $char === "\x01" => '(?:.*/)?',
                $char === '*' => '[^/]*',
                $char === '?' => '[^/]',
                $char === '{' => '(?:',
                $char === '}' => ')',
                $char === ',' => '|',
                default => preg_quote($char, '#'),
            };
        }

        return '#^'.$regex.'$#';
    }

    private function relative(string $absolute): string
    {
        $prefix = rtrim($this->workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : $absolute;

        return str_replace('\\', '/', $relative);
    }
}
