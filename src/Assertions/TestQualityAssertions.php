<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Assertions;

use Henryavila\Codeguard\Assertions\Concerns\ResolvesAntiPatternScanner;
use PHPUnit\Framework\Assert;

/**
 * Test-quality assertions — catches anti-patterns that silently erode
 * test value (tautologies, mocked Eloquent, bare null checks).
 *
 * Intended to be `uses()`d in Pest Arch tests. See
 * resources/stubs/tests/Arch/TestQualityTest.php.stub for usage.
 *
 * Each method fails the surrounding test (via PHPUnit assertions) when it
 * finds violations across the project's `tests/` directory. Scanning logic
 * lives in {@see AntiPatternScanner}.
 */
trait TestQualityAssertions
{
    use ResolvesAntiPatternScanner;

    /**
     * Assert no test contains assertions that can never fail
     * (e.g. `expect(true)->toBeTrue()`, `$this->assertTrue(true)`).
     *
     * @param  list<string>  $allowlist  relative paths to skip
     */
    public function assertNoTautologicalAssertions(array $allowlist = []): void
    {
        $violations = $this->makeAntiPatternScanner()->tautologicalAssertions($allowlist);

        Assert::assertSame([], $violations, $this->formatAntiPatternViolations(
            'Tautological assertions detected (assert real state instead):',
            $violations,
        ));
    }

    /**
     * Assert no test mocks an Eloquent model class. Mocking Eloquent
     * couples tests to ORM internals; prefer factories + SQLite in-memory.
     *
     * @param  list<string>  $allowlist  relative paths to skip
     */
    public function assertNoEloquentModelMocking(array $allowlist = []): void
    {
        $violations = $this->makeAntiPatternScanner()->eloquentModelMocking($allowlist);

        Assert::assertSame([], $violations, $this->formatAntiPatternViolations(
            'Eloquent model mocking detected:',
            $violations,
        ));
    }

    /**
     * Assert no test uses `assertNotNull($x)` as its ONLY assertion
     * on `$x`. Null checks should be followed by a behavioural assertion.
     *
     * @param  list<string>  $allowlist  relative paths to skip
     */
    public function assertNoBareAssertNotNull(array $allowlist = []): void
    {
        $violations = $this->makeAntiPatternScanner()->bareAssertNotNull($allowlist);

        Assert::assertSame([], $violations, $this->formatAntiPatternViolations(
            'Bare assertNotNull() detected:',
            $violations,
        ));
    }
}
