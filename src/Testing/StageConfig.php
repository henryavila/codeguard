<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

final readonly class StageConfig
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @param  list<string>  $fastFailArguments
     */
    public function __construct(
        public string $key,
        public bool $enabled,
        public string $label,
        public int $phase,
        public string $description,
        public array $command,
        public array $env,
        public ?string $reportType,
        public ?string $reportFile,
        public ?string $reportArgPrefix,
        public array $fastFailArguments,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $key, array $data): self
    {
        /** @var list<string> $command */
        $command = array_values((array) ($data['command'] ?? []));

        /** @var array<string, string> $env */
        $env = (array) ($data['env'] ?? []);

        /** @var list<string> $fastFailArguments */
        $fastFailArguments = array_values((array) ($data['fast_fail_arguments'] ?? []));

        $reportType = $data['report_type'] ?? null;
        $reportFile = $data['report_file'] ?? null;
        $reportArgPrefix = $data['report_arg_prefix'] ?? null;

        return new self(
            key: $key,
            enabled: (bool) ($data['enabled'] ?? false),
            label: (string) ($data['label'] ?? ucfirst($key)),
            phase: (int) ($data['phase'] ?? 1),
            description: (string) ($data['description'] ?? ''),
            command: $command,
            env: $env,
            reportType: $reportType !== null ? (string) $reportType : null,
            reportFile: $reportFile !== null ? (string) $reportFile : null,
            reportArgPrefix: $reportArgPrefix !== null ? (string) $reportArgPrefix : null,
            fastFailArguments: $fastFailArguments,
        );
    }
}
