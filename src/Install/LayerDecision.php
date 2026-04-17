<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

final readonly class LayerDecision
{
    public function __construct(
        public string $namespace,
        public ?string $layerName,
    ) {}

    public function isSkipped(): bool
    {
        return $this->layerName === null;
    }

    public static function skip(string $namespace): self
    {
        return new self(namespace: $namespace, layerName: null);
    }

    public static function assign(string $namespace, string $layerName): self
    {
        return new self(namespace: $namespace, layerName: $layerName);
    }
}
