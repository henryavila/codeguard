<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

final readonly class PrepareConfig
{
    public function __construct(
        public string $connection,
        public string $dumpPath,
        public string $hashPath,
        public string $migrationsPath,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            connection: (string) ($data['connection'] ?? 'sqlite'),
            dumpPath: (string) ($data['dump_path'] ?? ''),
            hashPath: (string) ($data['hash_path'] ?? ''),
            migrationsPath: (string) ($data['migrations_path'] ?? ''),
        );
    }
}
