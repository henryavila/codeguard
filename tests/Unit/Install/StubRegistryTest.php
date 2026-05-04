<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\StubDefinition;
use Henryavila\Codeguard\Install\StubRegistry;
use Henryavila\Codeguard\Testing\Preset;

/**
 * @return list<string>
 */
function stubSources(array $stubs): array
{
    return array_map(static fn (StubDefinition $s): string => $s->stubRelativePath, $stubs);
}

/**
 * @return list<string>
 */
function stubTargets(array $stubs): array
{
    return array_map(static fn (StubDefinition $s): string => $s->targetRelativePath, $stubs);
}

it('publishes phpunit.xml.stub for the Default preset', function (): void {
    $stubs = (new StubRegistry)->stubsFor(Preset::Default);

    expect(stubSources($stubs))->toContain('phpunit.xml.stub');
    expect(stubTargets($stubs))->toContain('phpunit.xml');
});

it('publishes phpunit.xml.stub for the Full preset', function (): void {
    $stubs = (new StubRegistry)->stubsFor(Preset::Full);

    expect(stubSources($stubs))->toContain('phpunit.xml.stub');
    expect(stubTargets($stubs))->toContain('phpunit.xml');
});

it('phpunit.xml.stub enforces failOnRisky and strict-about-tests', function (): void {
    $stubPath = __DIR__.'/../../../resources/stubs/phpunit.xml.stub';

    expect(is_file($stubPath))->toBeTrue();

    $content = (string) file_get_contents($stubPath);
    expect($content)->toContain('failOnRisky="true"');
    expect($content)->toContain('beStrictAboutTestsThatDoNotTestAnything="true"');
});

it('Default preset stub set is a strict subset of the Full preset', function (): void {
    $registry = new StubRegistry;
    $defaultSources = stubSources($registry->stubsFor(Preset::Default));
    $fullSources = stubSources($registry->stubsFor(Preset::Full));

    foreach ($defaultSources as $source) {
        expect($fullSources)->toContain($source);
    }
});
