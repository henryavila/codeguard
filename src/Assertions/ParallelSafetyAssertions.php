<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Assertions;

use Henryavila\Codeguard\Assertions\Concerns\ResolvesAntiPatternScanner;
use PHPUnit\Framework\Assert;

/**
 * Parallel-safety assertions — catches patterns that break parallel test
 * execution (Pest's `--parallel`) or cause cross-worker state leaks.
 *
 * Intended to be `uses()`d in Pest Arch tests. See
 * resources/stubs/tests/Arch/TestQualityTest.php.stub for usage.
 *
 * Each method fails the surrounding test (via PHPUnit assertions) when it
 * finds violations. Scanning logic lives in {@see AntiPatternScanner}.
 */
trait ParallelSafetyAssertions
{
    use ResolvesAntiPatternScanner;

    /**
     * Assert no test calls `DB::table(...)->truncate()` or equivalent.
     * Truncation bypasses transaction rollback and leaks across workers.
     *
     * @param  list<string>  $allowlist  relative paths to skip
     */
    public function assertNoTruncateInTests(array $allowlist = []): void
    {
        $violations = $this->makeAntiPatternScanner()->truncateInTests($allowlist);

        Assert::assertSame([], $violations, $this->formatAntiPatternViolations(
            'truncate() calls in tests:',
            $violations,
        ));
    }

    /**
     * Assert no test calls `->forceDelete()`. forceDelete bypasses
     * soft-delete semantics and commits state outside the test transaction.
     *
     * @param  list<string>  $allowlist  relative paths to skip
     */
    public function assertNoForceDeleteInTests(array $allowlist = []): void
    {
        $violations = $this->makeAntiPatternScanner()->forceDeleteInTests($allowlist);

        Assert::assertSame([], $violations, $this->formatAntiPatternViolations(
            'forceDelete() calls in tests:',
            $violations,
        ));
    }

    /**
     * Assert no Factory::definition() method issues DB queries.
     * definition() runs on every `make()`/`create()` — queries there
     * cause N+1 explosions in test setup.
     *
     * @param  list<string>  $allowlist  relative paths to skip
     */
    public function assertNoDbQueriesInFactoryDefinition(array $allowlist = []): void
    {
        $violations = $this->makeAntiPatternScanner()->dbQueriesInFactoryDefinition($allowlist);

        Assert::assertSame([], $violations, $this->formatAntiPatternViolations(
            'DB queries in factory definition():',
            $violations,
        ));
    }

    /**
     * Assert no Factory::definition() eagerly calls `->create()` on
     * nested factories. Use the lazy `Model::factory()` form instead.
     *
     * @param  list<string>  $allowlist  relative paths to skip
     */
    public function assertNoEagerCreateInFactoryDefinition(array $allowlist = []): void
    {
        $violations = $this->makeAntiPatternScanner()->eagerCreateInFactoryDefinition($allowlist);

        Assert::assertSame([], $violations, $this->formatAntiPatternViolations(
            'Eager create() in factory definition():',
            $violations,
        ));
    }
}
