<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

final readonly class StageConfig
{
    /**
     * @param  array<string, string>  $env
     */
    public function __construct(
        public string $key,
        public bool $enabled,
        public string $command,
        public array $env,
        public string $reportFormat,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $key, array $data): self
    {
        /** @var array<string, string> $env */
        $env = $data['env'] ?? [];

        return new self(
            key: $key,
            enabled: (bool) ($data['enabled'] ?? false),
            command: (string) ($data['command'] ?? ''),
            env: $env,
            reportFormat: (string) ($data['report_format'] ?? 'junit'),
        );
    }
}
