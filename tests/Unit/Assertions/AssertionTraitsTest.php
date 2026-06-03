<?php

declare(strict_types=1);

use Henryavila\Codeguard\Assertions\AntiPatternScanner;
use Henryavila\Codeguard\Assertions\ParallelSafetyAssertions;
use Henryavila\Codeguard\Assertions\TestQualityAssertions;
use PHPUnit\Framework\ExpectationFailedException;

/*
|--------------------------------------------------------------------------
| Assertion traits — delegation behaviour
|--------------------------------------------------------------------------
|
| The traits are thin adapters over AntiPatternScanner that fail the test
| (throw ExpectationFailedException) when violations exist and pass when
| clean. We exercise them via anonymous classes that point the scanner at
| a fixture base path, so no Laravel base_path() resolution is needed.
|
*/

function aptBase(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-traits-'.uniqid();
    mkdir($dir.'/tests/Unit', 0o755, true);

    return $dir;
}

function aptWrite(string $base, string $relative, string $body): void
{
    $path = $base.DIRECTORY_SEPARATOR.$relative;
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
    file_put_contents($path, "<?php\n\n".$body."\n");
}

function aptCleanup(string $base): void
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

function aptTestQualitySubject(string $base): object
{
    return new class($base)
    {
        use TestQualityAssertions;

        public function __construct(public string $base) {}

        protected function makeAntiPatternScanner(): AntiPatternScanner
        {
            return new AntiPatternScanner($this->base);
        }
    };
}

function aptParallelSafetySubject(string $base): object
{
    return new class($base)
    {
        use ParallelSafetyAssertions;

        public function __construct(public string $base) {}

        protected function makeAntiPatternScanner(): AntiPatternScanner
        {
            return new AntiPatternScanner($this->base);
        }
    };
}

it('TestQualityAssertions fails on a tautological assertion', function (): void {
    $base = aptBase();

    try {
        aptWrite($base, 'tests/Unit/FooTest.php', 'expect(true)->toBeTrue();');
        $subject = aptTestQualitySubject($base);

        $threw = false;
        try {
            $subject->assertNoTautologicalAssertions();
        } catch (ExpectationFailedException) {
            $threw = true;
        }

        expect($threw)->toBeTrue();
    } finally {
        aptCleanup($base);
    }
});

it('TestQualityAssertions passes on clean tests', function (): void {
    $base = aptBase();

    try {
        aptWrite($base, 'tests/Unit/FooTest.php', 'expect($user->name)->toBe("Ann");');
        $subject = aptTestQualitySubject($base);

        $threw = false;
        try {
            $subject->assertNoTautologicalAssertions();
            $subject->assertNoEloquentModelMocking();
            $subject->assertNoBareAssertNotNull();
        } catch (ExpectationFailedException) {
            $threw = true;
        }

        expect($threw)->toBeFalse();
    } finally {
        aptCleanup($base);
    }
});

it('ParallelSafetyAssertions fails on truncate() in a test', function (): void {
    $base = aptBase();

    try {
        aptWrite($base, 'tests/Unit/FooTest.php', "DB::table('x')->truncate();");
        $subject = aptParallelSafetySubject($base);

        $threw = false;
        try {
            $subject->assertNoTruncateInTests();
        } catch (ExpectationFailedException) {
            $threw = true;
        }

        expect($threw)->toBeTrue();
    } finally {
        aptCleanup($base);
    }
});

it('ParallelSafetyAssertions honours the allowlist', function (): void {
    $base = aptBase();

    try {
        aptWrite($base, 'tests/Unit/FooTest.php', '$user->forceDelete();');
        $subject = aptParallelSafetySubject($base);

        $threw = false;
        try {
            $subject->assertNoForceDeleteInTests(['tests/Unit/FooTest.php']);
        } catch (ExpectationFailedException) {
            $threw = true;
        }

        expect($threw)->toBeFalse();
    } finally {
        aptCleanup($base);
    }
});

it('ParallelSafetyAssertions fails on a DB query inside a factory definition()', function (): void {
    $base = aptBase();

    try {
        aptWrite(
            $base,
            'database/factories/UserFactory.php',
            "class UserFactory\n{\n    public function definition(): array\n    {\n        return ['role' => DB::table('roles')->first()];\n    }\n}",
        );
        $subject = aptParallelSafetySubject($base);

        $threw = false;
        try {
            $subject->assertNoDbQueriesInFactoryDefinition();
        } catch (ExpectationFailedException) {
            $threw = true;
        }

        expect($threw)->toBeTrue();
    } finally {
        aptCleanup($base);
    }
});

it('ParallelSafetyAssertions fails on eager create() inside a factory definition()', function (): void {
    $base = aptBase();

    try {
        aptWrite(
            $base,
            'database/factories/UserFactory.php',
            "class UserFactory\n{\n    public function definition(): array\n    {\n        return ['role_id' => Role::factory()->create()->id];\n    }\n}",
        );
        $subject = aptParallelSafetySubject($base);

        $threw = false;
        try {
            $subject->assertNoEagerCreateInFactoryDefinition();
        } catch (ExpectationFailedException) {
            $threw = true;
        }

        expect($threw)->toBeTrue();
    } finally {
        aptCleanup($base);
    }
});
