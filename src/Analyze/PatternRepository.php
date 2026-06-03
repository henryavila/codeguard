<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * Source of curated patterns. Interfaced because the schema-dump / ai-rules
 * siblings will consume the same corpus through this seam.
 */
interface PatternRepository
{
    /**
     * Patterns whose layer/preset is in $presets (e.g. ['core','php','php-laravel']).
     *
     * @param  list<string>  $presets
     * @return list<Pattern>
     */
    public function forPresets(array $presets): array;

    /**
     * Whether a pattern with this key exists anywhere in the corpus
     * (used by the {@see PatternMatch} trust boundary).
     */
    public function has(string $key): bool;
}
