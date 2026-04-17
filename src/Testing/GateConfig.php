<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

final readonly class GateConfig
{
    public function __construct(
        public string $key,
        public bool $enabled,
        public string $command,
        public string $description,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $key, array $data): self
    {
        return new self(
            key: $key,
            enabled: (bool) ($data['enabled'] ?? false),
            command: (string) ($data['command'] ?? ''),
            description: (string) ($data['description'] ?? ''),
        );
    }
}
