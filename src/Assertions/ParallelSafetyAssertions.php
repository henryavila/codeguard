<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Assertions;

/**
 * Parallel-safety assertions — catches patterns that break parallel test
 * execution (Pest's `--parallel`) or cause cross-worker state leaks.
 *
 * Intended to be `uses()`d in Pest Arch tests. See
 * resources/stubs/tests/Arch/TestQualityTest.php.stub for usage.
 *
 * @internal Implementations are scheduled for a future wave — see
 *   https://github.com/henryavila/codeguard/issues for roadmap.
 */
trait ParallelSafetyAssertions
{
    /**
     * Assert no test calls `DB::table(...)->truncate()` or equivalent.
     * Truncation bypasses transaction rollback and leaks across workers.
     *
     * @param  array<int, string>  $allowlist  FQCNs or file paths to skip
     */
    public function assertNoTruncateInTests(array $allowlist = []): void
    {
        throw new \RuntimeException(
            'Not yet implemented — see https://github.com/henryavila/codeguard/issues for roadmap'
        );
    }

    /**
     * Assert no test calls `->forceDelete()`. forceDelete bypasses
     * soft-delete semantics and commits state outside the test transaction.
     *
     * @param  array<int, string>  $allowlist  FQCNs or file paths to skip
     */
    public function assertNoForceDeleteInTests(array $allowlist = []): void
    {
        throw new \RuntimeException(
            'Not yet implemented — see https://github.com/henryavila/codeguard/issues for roadmap'
        );
    }

    /**
     * Assert no Factory::definition() method issues DB queries.
     * definition() runs on every `make()`/`create()` — queries there
     * cause N+1 explosions in test setup.
     *
     * @param  array<int, string>  $allowlist  FQCNs or file paths to skip
     */
    public function assertNoDbQueriesInFactoryDefinition(array $allowlist = []): void
    {
        throw new \RuntimeException(
            'Not yet implemented — see https://github.com/henryavila/codeguard/issues for roadmap'
        );
    }

    /**
     * Assert no Factory::definition() eagerly calls `->create()` on
     * nested factories. Use the lazy `Model::factory()` form instead.
     *
     * @param  array<int, string>  $allowlist  FQCNs or file paths to skip
     */
    public function assertNoEagerCreateInFactoryDefinition(array $allowlist = []): void
    {
        throw new \RuntimeException(
            'Not yet implemented — see https://github.com/henryavila/codeguard/issues for roadmap'
        );
    }
}
