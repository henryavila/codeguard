<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Assertions;

use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Scans a consumer project's test suite and factories for quality /
 * parallel-safety anti-patterns via localized regex matching.
 *
 * This is the framework-free workhorse behind {@see TestQualityAssertions}
 * and {@see ParallelSafetyAssertions}. It takes an explicit base path so it
 * is fully unit-testable against fixtures without booting Laravel.
 *
 * Paths are reported relative to the base path (e.g. `tests/Unit/FooTest.php`)
 * which is also the format the `$allowlist` arguments expect.
 */
final class AntiPatternScanner
{
    /** @var list<string> */
    private const TAUTOLOGICAL_PATTERNS = [
        '/expect\(\s*(?:true|false|null)\s*\)\s*->\s*(?:toBeTrue|toBeFalse|toBeNull)\(/',
        '/->assertTrue\(\s*true\s*\)/',
        '/->assertFalse\(\s*false\s*\)/',
        '/->assertNull\(\s*null\s*\)/',
    ];

    /** @var list<string> */
    private const ELOQUENT_MOCK_PATTERNS = [
        '/Mockery::mock\(\s*[\'"]alias:[A-Z]/',
    ];

    /** @var list<string> */
    private const BARE_ASSERT_NOT_NULL_PATTERNS = [
        '/->assertNotNull\(\$[a-zA-Z_]+\)\s*;/',
    ];

    /** @var list<string> */
    private const TRUNCATE_PATTERNS = [
        '/->truncate\(\s*\)/',
    ];

    /** @var list<string> */
    private const FORCE_DELETE_PATTERNS = [
        '/->forceDelete\(\s*\)/',
    ];

    /** @var list<string> */
    private const FACTORY_DB_QUERY_PATTERNS = [
        '/\bDB::(?:table|select|raw|connection|statement|insert|update)\(/',
        '/[A-Z][a-zA-Z]+::query\(\)/',
        '/[A-Z][a-zA-Z]+::where\(/',
        '/[A-Z][a-zA-Z]+::find\(/',
        '/[A-Z][a-zA-Z]+::first(?:OrCreate)?\(/',
    ];

    /** @var list<string> */
    private const FACTORY_EAGER_CREATE_PATTERNS = [
        '/::factory\(\)\s*->\s*create\(/',
    ];

    /**
     * @param  string  $basePath  Project root (e.g. Laravel `base_path()`).
     * @param  string  $testsDir  Tests directory relative to the base path.
     * @param  list<string>  $excludeDirs  Directory names under `$testsDir` to skip
     *                                     (the Arch test dir is excluded by default so
     *                                     these very patterns, written as string literals
     *                                     in the arch test, do not self-match).
     * @param  string  $factoriesDir  Model factories directory relative to the base path.
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $testsDir = 'tests',
        private readonly array $excludeDirs = ['Arch'],
        private readonly string $factoriesDir = 'database/factories',
    ) {}

    /**
     * Assertions that can never fail (e.g. `expect(true)->toBeTrue()`).
     *
     * @param  list<string>  $allowlist
     * @return list<string>
     */
    public function tautologicalAssertions(array $allowlist = []): array
    {
        return $this->scanTestFiles(
            self::TAUTOLOGICAL_PATTERNS,
            'tautological assertion — assert real state instead',
            $allowlist,
        );
    }

    /**
     * Mockery alias-mocking of an Eloquent model.
     *
     * @param  list<string>  $allowlist
     * @return list<string>
     */
    public function eloquentModelMocking(array $allowlist = []): array
    {
        return $this->scanTestFiles(
            self::ELOQUENT_MOCK_PATTERNS,
            "Mockery::mock('alias:Model') — use a partial mock or container injection",
            $allowlist,
        );
    }

    /**
     * `assertNotNull($var)` used as the only assertion on a value.
     *
     * @param  list<string>  $allowlist
     * @return list<string>
     */
    public function bareAssertNotNull(array $allowlist = []): array
    {
        return $this->scanTestFiles(
            self::BARE_ASSERT_NOT_NULL_PATTERNS,
            'bare assertNotNull — follow with a behavioural assertion (or use expect()->not->toBeNull())',
            $allowlist,
        );
    }

    /**
     * `->truncate()` in tests — leaks across parallel workers.
     *
     * @param  list<string>  $allowlist
     * @return list<string>
     */
    public function truncateInTests(array $allowlist = []): array
    {
        return $this->scanTestFiles(
            self::TRUNCATE_PATTERNS,
            'truncate() in test — corrupts parallel workers + resets auto-increment',
            $allowlist,
        );
    }

    /**
     * `->forceDelete()` in tests — commits state outside the test transaction.
     *
     * @param  list<string>  $allowlist
     * @return list<string>
     */
    public function forceDeleteInTests(array $allowlist = []): array
    {
        return $this->scanTestFiles(
            self::FORCE_DELETE_PATTERNS,
            'forceDelete() in test — use ->delete() (soft-delete) for parallel safety',
            $allowlist,
        );
    }

    /**
     * DB queries inside `Factory::definition()` — runs on every make()/create().
     *
     * @param  list<string>  $allowlist
     * @return list<string>
     */
    public function dbQueriesInFactoryDefinition(array $allowlist = []): array
    {
        return $this->scanFactoryDefinitions(
            self::FACTORY_DB_QUERY_PATTERNS,
            'DB query inside Factory::definition() — runs every make()/create(); pass via state instead',
            $allowlist,
        );
    }

    /**
     * Eager `->create()` inside `Factory::definition()` — use lazy `Model::factory()`.
     *
     * @param  list<string>  $allowlist
     * @return list<string>
     */
    public function eagerCreateInFactoryDefinition(array $allowlist = []): array
    {
        return $this->scanFactoryDefinitions(
            self::FACTORY_EAGER_CREATE_PATTERNS,
            'eager create() inside Factory::definition() — use Model::factory() lazy (no ->create())',
            $allowlist,
        );
    }

    /**
     * @param  list<string>  $patterns
     * @param  list<string>  $allowlist
     * @return list<string>
     */
    private function scanTestFiles(array $patterns, string $message, array $allowlist): array
    {
        $violations = [];

        foreach ($this->testFiles() as $file) {
            $relative = $this->testsDir.'/'.$this->normalizeSlashes($file->getRelativePathname());
            if (in_array($relative, $allowlist, true)) {
                continue;
            }

            if ($this->matchesAny($patterns, $this->readFile($file->getPathname()))) {
                $violations[] = sprintf('%s: %s', $relative, $message);
            }
        }

        return $violations;
    }

    /**
     * @param  list<string>  $patterns
     * @param  list<string>  $allowlist
     * @return list<string>
     */
    private function scanFactoryDefinitions(array $patterns, string $message, array $allowlist): array
    {
        $violations = [];

        foreach ($this->factoryFiles() as $file) {
            $relative = $this->factoriesDir.'/'.$this->normalizeSlashes($file->getRelativePathname());
            if (in_array($relative, $allowlist, true)) {
                continue;
            }

            $body = $this->extractDefinitionBody($this->readFile($file->getPathname()));
            if ($body === null) {
                continue;
            }

            if ($this->matchesAny($patterns, $body)) {
                $violations[] = sprintf('%s: %s', $relative, $message);
            }
        }

        return $violations;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function testFiles(): iterable
    {
        $dir = $this->basePath.'/'.$this->testsDir;
        if (! is_dir($dir)) {
            return [];
        }

        $finder = Finder::create()->files()->in($dir)->name('*.php')->sortByName();

        if ($this->excludeDirs !== []) {
            $finder->exclude($this->excludeDirs);
        }

        return $finder;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function factoryFiles(): iterable
    {
        $dir = $this->basePath.'/'.$this->factoriesDir;
        if (! is_dir($dir)) {
            return [];
        }

        return Finder::create()->files()->in($dir)->name('*Factory.php')->sortByName();
    }

    /**
     * Extracts the body of a Factory's `definition()` method, stripping
     * comments and string literals so explanatory text or string content
     * cannot trip a pattern. Returns null when there is no `definition()`.
     */
    private function extractDefinitionBody(string $content): ?string
    {
        if (preg_match('/public function definition\(\)[^{]*\{(.+?)^\s*\}/sm', $content, $matches) !== 1) {
            return null;
        }

        $body = $matches[1];
        $body = preg_replace('/\/\*.*?\*\//s', '', $body) ?? $body;
        $body = preg_replace('/\/\/[^\n]*/', '', $body) ?? $body;
        $body = preg_replace('/(?<!\\\\)#[^\n]*/', '', $body) ?? $body;
        $body = preg_replace("/'(?:\\\\'|[^'])*'/", "''", $body) ?? $body;
        $body = preg_replace('/"(?:\\\\"|[^"])*"/', '""', $body) ?? $body;

        return $body;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(array $patterns, string $haystack): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSlashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read file: %s', $path));
        }

        return $content;
    }
}
