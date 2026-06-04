<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * One unit of work: a single file plus the patterns whose detection signals
 * matched it. The file is the expensive shared LLM context; patterns are the
 * cheap appended blocks — so the runner makes ONE call per unit.
 */
final readonly class AnalysisUnit
{
    /**
     * @param  string  $file  Absolute path to the source file.
     * @param  list<Pattern>  $patterns
     */
    public function __construct(
        public string $file,
        public array $patterns,
    ) {}

    /**
     * @return list<string>
     */
    public function patternKeys(): array
    {
        return array_map(static fn (Pattern $p): string => $p->key, $this->patterns);
    }
}
