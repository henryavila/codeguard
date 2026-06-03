<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * Pre-filters which patterns apply to which files via `detection.signals`,
 * producing {@see AnalysisUnit}s. Only files matched by at least one pattern
 * become units — so the LLM only ever sees relevant code.
 *
 * Signal handling (MVP):
 *  - file      → glob (supports `**`, `*`, `?`, `{a,b}`) against the relative path
 *  - directory → relative-path prefix
 *  - import    → namespace glob approximated as a PSR-4 path prefix
 */
final class PatternMatcher
{
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

            $matched = array_values(array_filter(
                $patterns,
                fn (Pattern $pattern): bool => $this->patternApplies($pattern, $relative),
            ));

            if ($matched !== []) {
                $units[] = new AnalysisUnit($file, $matched);
            }
        }

        return $units;
    }

    private function patternApplies(Pattern $pattern, string $relativePath): bool
    {
        foreach ($pattern->detectionSignals as $signal) {
            if ($this->signalMatches($signal, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    private function signalMatches(DetectionSignal $signal, string $relativePath): bool
    {
        return match ($signal->type) {
            'file' => preg_match($this->globToRegex($signal->value), $relativePath) === 1,
            'directory' => str_starts_with($relativePath, rtrim($signal->value, '/').'/'),
            'import' => str_starts_with($relativePath, $this->importToPathPrefix($signal->value)),
            default => false,
        };
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

    private function importToPathPrefix(string $namespaceGlob): string
    {
        $path = str_replace('\\', '/', rtrim($namespaceGlob, '\\*'));
        $segments = explode('/', $path, 2);
        $segments[0] = lcfirst($segments[0]);

        return rtrim(implode('/', $segments), '/').'/';
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
