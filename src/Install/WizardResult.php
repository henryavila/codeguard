<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

final readonly class WizardResult
{
    /**
     * @param  list<LayerDecision>  $decisions
     * @param  list<string>  $customLayers
     */
    public function __construct(
        public array $decisions,
        public array $customLayers,
    ) {}

    public function isEmpty(): bool
    {
        return $this->decisions === [];
    }

    /**
     * @return array<string, ?string>  namespace => layerName (null = skip)
     */
    public function toSerializableMap(): array
    {
        $map = [];

        foreach ($this->decisions as $decision) {
            $map[$decision->namespace] = $decision->layerName;
        }

        return $map;
    }
}
