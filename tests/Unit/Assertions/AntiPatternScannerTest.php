<?php

declare(strict_types=1);

use Henryavila\Codeguard\Assertions\AntiPatternScanner;

/*
|--------------------------------------------------------------------------
| AntiPatternScanner — unit tests
|--------------------------------------------------------------------------
|
| The scanner is the workhorse behind TestQualityAssertions +
| ParallelSafetyAssertions. It walks a consumer's tests/ + database/factories/
| looking for anti-patterns, honouring an allowlist. Tested here against
| hermetic temp fixtures so no Laravel boot is required.
|
*/

function apsBase(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-aps-'.uniqid();
    mkdir($dir.'/tests/Unit', 0o755, true);
    mkdir($dir.'/tests/Arch', 0o755, true);
    mkdir($dir.'/database/factories', 0o755, true);

    return $dir;
}

function apsWrite(string $base, string $relative, string $body): void
{
    $path = $base.DIRECTORY_SEPARATOR.$relative;
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
    file_put_contents($path, "<?php\n\n".$body."\n");
}

function apsCleanup(string $base): void
{
    if (! is_dir($base)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($base);
}

// ── tautological assertions ────────────────────────────────────────

it('flags tautological expect() assertions', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'tests/Unit/FooTest.php', 'expect(true)->toBeTrue();');
        $violations = (new AntiPatternScanner($base))->tautologicalAssertions();

        expect($violations)->toHaveCount(1)
            ->and($violations[0])->toContain('tests/Unit/FooTest.php');
    } finally {
        apsCleanup($base);
    }
});

it('flags tautological PHPUnit assertions', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'tests/Unit/BarTest.php', '$this->assertTrue(true);');

        expect((new AntiPatternScanner($base))->tautologicalAssertions())->toHaveCount(1);
    } finally {
        apsCleanup($base);
    }
});

it('passes test files with real-state assertions', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'tests/Unit/FooTest.php', 'expect($user->name)->toBe("Ann");');

        expect((new AntiPatternScanner($base))->tautologicalAssertions())->toBe([]);
    } finally {
        apsCleanup($base);
    }
});

// ── Eloquent model mocking ─────────────────────────────────────────

it('flags Mockery alias mocking of a model', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'tests/Unit/FooTest.php', "Mockery::mock('alias:User');");

        expect((new AntiPatternScanner($base))->eloquentModelMocking())->toHaveCount(1);
    } finally {
        apsCleanup($base);
    }
});

it('does not flag mocking a non-aliased service', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'tests/Unit/FooTest.php', 'Mockery::mock(PaymentService::class);');

        expect((new AntiPatternScanner($base))->eloquentModelMocking())->toBe([]);
    } finally {
        apsCleanup($base);
    }
});

// ── bare assertNotNull ─────────────────────────────────────────────

it('flags a bare assertNotNull on a variable', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'tests/Unit/FooTest.php', '$this->assertNotNull($user);');

        expect((new AntiPatternScanner($base))->bareAssertNotNull())->toHaveCount(1);
    } finally {
        apsCleanup($base);
    }
});

it('does not flag assertNotNull on a property access', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'tests/Unit/FooTest.php', '$this->assertNotNull($user->name);');

        expect((new AntiPatternScanner($base))->bareAssertNotNull())->toBe([]);
    } finally {
        apsCleanup($base);
    }
});

// ── truncate / forceDelete in tests ────────────────────────────────

it('flags truncate() in a test', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'tests/Unit/FooTest.php', "DB::table('users')->truncate();");

        expect((new AntiPatternScanner($base))->truncateInTests())->toHaveCount(1);
    } finally {
        apsCleanup($base);
    }
});

it('flags forceDelete() in a test', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'tests/Unit/FooTest.php', '$user->forceDelete();');

        expect((new AntiPatternScanner($base))->forceDeleteInTests())->toHaveCount(1);
    } finally {
        apsCleanup($base);
    }
});

// ── factory definition scanning ────────────────────────────────────

it('flags a DB query inside Factory::definition()', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'database/factories/UserFactory.php', <<<'PHP'
            class UserFactory
            {
                public function definition(): array
                {
                    return ['team_id' => Team::query()->first()->id];
                }
            }
            PHP);

        expect((new AntiPatternScanner($base))->dbQueriesInFactoryDefinition())->toHaveCount(1);
    } finally {
        apsCleanup($base);
    }
});

it('ignores a DB reference inside a comment in definition()', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'database/factories/UserFactory.php', <<<'PHP'
            class UserFactory
            {
                public function definition(): array
                {
                    // legacy used Team::query() — now injected via state
                    return ['name' => 'Ann'];
                }
            }
            PHP);

        expect((new AntiPatternScanner($base))->dbQueriesInFactoryDefinition())->toBe([]);
    } finally {
        apsCleanup($base);
    }
});

it('ignores a DB reference inside a string literal in definition()', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'database/factories/UserFactory.php', <<<'PHP'
            class UserFactory
            {
                public function definition(): array
                {
                    return ['note' => 'run DB::table() by hand'];
                }
            }
            PHP);

        expect((new AntiPatternScanner($base))->dbQueriesInFactoryDefinition())->toBe([]);
    } finally {
        apsCleanup($base);
    }
});

it('flags eager create() inside Factory::definition()', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'database/factories/PostFactory.php', <<<'PHP'
            class PostFactory
            {
                public function definition(): array
                {
                    return ['user_id' => User::factory()->create()->id];
                }
            }
            PHP);

        expect((new AntiPatternScanner($base))->eagerCreateInFactoryDefinition())->toHaveCount(1);
    } finally {
        apsCleanup($base);
    }
});

it('passes lazy factory references', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'database/factories/PostFactory.php', <<<'PHP'
            class PostFactory
            {
                public function definition(): array
                {
                    return ['user_id' => User::factory()];
                }
            }
            PHP);

        expect((new AntiPatternScanner($base))->eagerCreateInFactoryDefinition())->toBe([]);
    } finally {
        apsCleanup($base);
    }
});

// ── allowlist + excluded dirs + missing dirs ───────────────────────

it('skips files listed in the allowlist', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'tests/Unit/LegacyTest.php', '$user->forceDelete();');

        $violations = (new AntiPatternScanner($base))
            ->forceDeleteInTests(['tests/Unit/LegacyTest.php']);

        expect($violations)->toBe([]);
    } finally {
        apsCleanup($base);
    }
});

it('excludes the Arch test directory by default to avoid self-matching', function (): void {
    $base = apsBase();

    try {
        apsWrite($base, 'tests/Arch/TestQualityTest.php', "DB::table('x')->truncate();");

        expect((new AntiPatternScanner($base))->truncateInTests())->toBe([]);
    } finally {
        apsCleanup($base);
    }
});

it('returns no violations when the factories directory is absent', function (): void {
    $base = apsBase();

    try {
        apsCleanup($base.'/database');

        expect((new AntiPatternScanner($base))->eagerCreateInFactoryDefinition())->toBe([]);
    } finally {
        apsCleanup($base);
    }
});
