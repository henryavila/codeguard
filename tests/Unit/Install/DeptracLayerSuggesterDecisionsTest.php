<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\DeptracLayerSuggester;
use Henryavila\Codeguard\Install\DetectedNamespace;
use Henryavila\Codeguard\Install\LayerDecision;
use Henryavila\Codeguard\Install\LayerSuggestion;
use Henryavila\Codeguard\Install\WizardResult;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->suggester = new DeptracLayerSuggester(new Filesystem);
});

function makeBaseSuggestion(): LayerSuggestion
{
    return new LayerSuggestion(
        detectedNamespaces: [
            new DetectedNamespace('app/Domain', 'App\\Domain', 12, 'Domain'),
            new DetectedNamespace('app/Services', 'App\\Services', 8, 'Application'),
            new DetectedNamespace('app/Upgrades', 'App\\Upgrades', 5, null),
            new DetectedNamespace('app/Features', 'App\\Features', 2, null),
            new DetectedNamespace('app/Integrations', 'App\\Integrations', 4, null),
        ],
        layers: [
            'Domain' => ['^App\\\\Domain\\\\.*'],
            'Application' => ['^App\\\\Services\\\\.*'],
        ],
        ruleset: [
            'Domain' => [],
            'Application' => ['Domain'],
        ],
    );
}

it('returns the original suggestion when wizard result is empty', function (): void {
    $original = makeBaseSuggestion();
    $emptyResult = new WizardResult(decisions: [], customLayers: []);

    $enriched = $this->suggester->withDecisions($original, $emptyResult);

    expect($enriched)->toBe($original);
});

it('merges skipped namespaces without adding them to any layer', function (): void {
    $original = makeBaseSuggestion();
    $result = new WizardResult(
        decisions: [LayerDecision::skip('App\\Features')],
        customLayers: [],
    );

    $enriched = $this->suggester->withDecisions($original, $result);

    foreach ($enriched->layers as $layerName => $patterns) {
        foreach ($patterns as $pattern) {
            expect($pattern)->not->toContain('App\\\\Features');
        }
    }
});

it('assigns an unclassified namespace to an existing layer', function (): void {
    $original = makeBaseSuggestion();
    $result = new WizardResult(
        decisions: [LayerDecision::assign('App\\Upgrades', 'Domain')],
        customLayers: [],
    );

    $enriched = $this->suggester->withDecisions($original, $result);

    expect($enriched->layers)->toHaveKey('Domain')
        ->and(implode(' ', $enriched->layers['Domain']))->toContain('App\\\\Upgrades');
});

it('introduces a custom layer with no outbound dependencies by default', function (): void {
    $original = makeBaseSuggestion();
    $result = new WizardResult(
        decisions: [LayerDecision::assign('App\\Integrations', 'Integration')],
        customLayers: ['Integration'],
    );

    $enriched = $this->suggester->withDecisions($original, $result);

    expect($enriched->layers)->toHaveKey('Integration')
        ->and($enriched->ruleset)->toHaveKey('Integration')
        ->and($enriched->ruleset['Integration'])->toBe([]);
});

it('preserves existing ruleset entries for built-in layers after merging', function (): void {
    $original = makeBaseSuggestion();
    $result = new WizardResult(
        decisions: [
            LayerDecision::assign('App\\Upgrades', 'Domain'),
            LayerDecision::assign('App\\Integrations', 'Infrastructure'),
        ],
        customLayers: [],
    );

    $enriched = $this->suggester->withDecisions($original, $result);

    expect($enriched->ruleset)->toHaveKey('Application')
        ->and($enriched->ruleset['Application'])->toContain('Domain');
});

it('produces YAML that contains custom layer names in the ruleset', function (): void {
    $original = makeBaseSuggestion();
    $result = new WizardResult(
        decisions: [LayerDecision::assign('App\\Integrations', 'Integration')],
        customLayers: ['Integration'],
    );

    $enriched = $this->suggester->withDecisions($original, $result);
    $yaml = $this->suggester->toYaml($enriched);

    expect($yaml)->toContain('name: Integration');
});
