<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Gates;

use Henryavila\Codeguard\Testing\GateConfig;

/**
 * Rewrites the mutation-testing gate to run through Pest's native mutation
 * runner (`pest --mutate`) when the consumer project uses Pest.
 *
 * WHY: Infection drives PHPUnit directly (`vendor/bin/phpunit`). Pest test
 * files call the global `test()` function, which only works when executed
 * through `vendor/bin/pest`; running them under raw PHPUnit aborts with
 * "Please run [./vendor/bin/pest] instead." and Infection then reports
 * "Project tests must be in a passing state". Pest ships a native mutation
 * runner (pestphp/pest-plugin-mutate) that wraps Infection and bootstraps
 * Pest correctly, so for Pest projects we drive that instead.
 *
 * Every other gate — and the Infection gate for plain-PHPUnit projects — is
 * returned unchanged, so non-Pest consumers keep their existing behaviour.
 */
final readonly class MutationGateResolver
{
    public function __construct(private bool $usesPest) {}

    public function resolve(GateConfig $gate): GateConfig
    {
        if (! $this->usesPest || ! $this->isMutationGate($gate)) {
            return $gate;
        }

        return new GateConfig(
            key: $gate->key,
            enabled: $gate->enabled,
            command: $this->pestMutateCommand($gate->command),
            description: $gate->description.' (via Pest)',
        );
    }

    private function isMutationGate(GateConfig $gate): bool
    {
        return str_contains($gate->command, 'infection');
    }

    /**
     * Translate the Infection invocation into the equivalent Pest mutation
     * command. Pest exposes a single MSI threshold via `--min`, mapped from
     * Infection's `--min-msi`. Infection-only flags (`--min-covered-msi`,
     * `--no-progress`) have no Pest equivalent and are dropped — Pest manages
     * its own mutation scope and output via the project's Pest configuration.
     */
    private function pestMutateCommand(string $infectionCommand): string
    {
        $command = './vendor/bin/pest --mutate';

        $min = $this->extractFlagValue($infectionCommand, 'min-msi');
        if ($min !== null) {
            $command .= ' --min='.$min;
        }

        return $command;
    }

    private function extractFlagValue(string $command, string $flag): ?string
    {
        if (preg_match('/--'.preg_quote($flag, '/').'[= ](\d+)/', $command, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
