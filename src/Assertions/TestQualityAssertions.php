<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Assertions;

/**
 * Test-quality assertions — catches anti-patterns that silently erode
 * test value (tautologies, mocked Eloquent, bare null checks).
 *
 * Intended to be `uses()`d in Pest Arch tests. See
 * resources/stubs/tests/Arch/TestQualityTest.php.stub for usage.
 *
 * @internal Implementations are scheduled for a future wave — see
 *   https://github.com/henryavila/codeguard/issues for roadmap.
 */
trait TestQualityAssertions
{
    /**
     * Assert no test contains assertions that can never fail
     * (e.g. `expect(true)->toBeTrue()`, `$this->assertTrue(true)`).
     *
     * @param  array<int, string>  $allowlist  FQCNs or file paths to skip
     */
    public function assertNoTautologicalAssertions(array $allowlist = []): void
    {
        throw new \RuntimeException(
            'Not yet implemented — see https://github.com/henryavila/codeguard/issues for roadmap'
        );
    }

    /**
     * Assert no test mocks an Eloquent model class. Mocking Eloquent
     * couples tests to ORM internals; prefer factories + SQLite in-memory.
     *
     * @param  array<int, string>  $allowlist  FQCNs or file paths to skip
     */
    public function assertNoEloquentModelMocking(array $allowlist = []): void
    {
        throw new \RuntimeException(
            'Not yet implemented — see https://github.com/henryavila/codeguard/issues for roadmap'
        );
    }

    /**
     * Assert no test uses `assertNotNull($x)` as its ONLY assertion
     * on `$x`. Null checks should be followed by a behavioural assertion.
     *
     * @param  array<int, string>  $allowlist  FQCNs or file paths to skip
     */
    public function assertNoBareAssertNotNull(array $allowlist = []): void
    {
        throw new \RuntimeException(
            'Not yet implemented — see https://github.com/henryavila/codeguard/issues for roadmap'
        );
    }
}
