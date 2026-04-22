<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

final readonly class LayerSuggestion
{
    /**
     * @param  list<DetectedNamespace>  $detectedNamespaces
     * @param  array<string, list<string>>  $layers  layer name → regex patterns
     * @param  array<string, list<string>>  $ruleset  layer name → allowed layers it can depend on
     */
    public function __construct(
        public array $detectedNamespaces,
        public array $layers,
        public array $ruleset,
    ) {}

    public function isEmpty(): bool
    {
        return $this->detectedNamespaces === [];
    }

    /**
     * @return list<string>
     */
    public function layerNames(): array
    {
        return array_keys($this->layers);
    }
}
