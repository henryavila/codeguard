<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Console\OutputStyle;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class DeptracLayerWizard
{
    private const MIN_FILES_FOR_BATCH_SKIP = 3;

    /**
     * Interactively classify unclassified (and auto-skip-suggested) namespaces.
     *
     * Returns a list of decisions (one per namespace surfaced to the user),
     * plus any custom layer names the user introduced along the way.
     *
     * @param  array<string, string>  $savedDecisions  namespace => layerName (null → skip) from prior run
     */
    public function classify(
        LayerSuggestion $suggestion,
        array $savedDecisions = [],
        ?OutputStyle $output = null,
    ): WizardResult {
        $unclassified = $this->extractPromptable($suggestion);

        if ($unclassified === []) {
            return new WizardResult(decisions: [], customLayers: []);
        }

        $this->renderIntro($unclassified, $output);

        $unclassified = $this->maybeBatchSkipTiny($unclassified);
        [$unclassified, $preDecided] = $this->maybeBatchCasingDuplicates($unclassified);

        $decisions = $preDecided;
        $customLayers = $this->collectCustomLayerNames($preDecided);

        foreach ($unclassified as $namespace) {
            $savedChoice = $savedDecisions[$namespace->namespace] ?? null;

            if ($savedChoice !== null) {
                $decisions[] = $savedChoice === ''
                    ? LayerDecision::skip($namespace->namespace)
                    : LayerDecision::assign($namespace->namespace, $savedChoice);

                continue;
            }

            $decision = $this->promptForNamespace($namespace, $customLayers);
            $decisions[] = $decision;

            if ($decision->layerName !== null
                && ! in_array($decision->layerName, $customLayers, strict: true)
                && ! in_array($decision->layerName, DeptracLayerSuggester::builtInLayers(), strict: true)) {
                $customLayers[] = $decision->layerName;
            }
        }

        return new WizardResult(decisions: $decisions, customLayers: $customLayers);
    }

    /**
     * Run the review step. Returns 'confirm', 'edit', or 'restart'.
     *
     * @param  list<LayerDecision>  $decisions
     * @param  list<string>  $customLayers
     */
    public function review(LayerSuggestion $suggestion, array $decisions, array $customLayers): string
    {
        $this->renderReview($suggestion, $decisions, $customLayers);

        /** @var string $choice */
        $choice = select(
            label: 'Final layer assignment — what next?',
            options: [
                'confirm' => 'Confirm and write deptrac.yaml',
                'edit' => 'Write, then open deptrac.yaml in $EDITOR',
                'restart' => 'Restart the wizard (reclassify everything)',
            ],
            default: 'confirm',
        );

        return $choice;
    }

    /**
     * Namespaces the wizard should prompt for:
     *   - completely unclassified (null)
     *   - auto-suggested Skip (so the user confirms and sees why)
     *
     * Namespaces auto-classified to a concrete layer are NOT prompted —
     * they appear in the review screen only.
     *
     * @return list<DetectedNamespace>
     */
    private function extractPromptable(LayerSuggestion $suggestion): array
    {
        return array_values(array_filter(
            $suggestion->detectedNamespaces,
            static fn (DetectedNamespace $ns): bool => $ns->suggestedLayer === null
                || $ns->suggestedLayer === LayerOption::Skip->value,
        ));
    }

    /**
     * @param  list<DetectedNamespace>  $unclassified
     */
    private function renderIntro(array $unclassified, ?OutputStyle $output): void
    {
        $count = count($unclassified);
        $lines = array_map(
            static function (DetectedNamespace $ns): string {
                $marker = $ns->suggestedLayer === LayerOption::Skip->value ? '  (auto: Skip)' : '';

                return sprintf('  • %s (%d files)%s', $ns->relativePath, $ns->fileCount, $marker);
            },
            $unclassified,
        );

        note(
            sprintf(
                "Deptrac wizard — %d namespace%s need confirmation.\n\n%s\n\nUse ↑/↓ + Enter to choose. Auto-suggested choices are pre-selected.",
                $count,
                $count === 1 ? '' : 's',
                implode("\n", $lines),
            ),
        );

        unset($output);
    }

    /**
     * @param  list<DetectedNamespace>  $unclassified
     * @return list<DetectedNamespace>
     */
    private function maybeBatchSkipTiny(array $unclassified): array
    {
        $tiny = array_values(array_filter(
            $unclassified,
            static fn (DetectedNamespace $ns): bool => $ns->fileCount < self::MIN_FILES_FOR_BATCH_SKIP
                && $ns->suggestedLayer !== LayerOption::Skip->value,
        ));

        if (count($tiny) < 3) {
            return $unclassified;
        }

        $names = implode(
            ', ',
            array_map(static fn (DetectedNamespace $ns): string => $ns->relativePath, $tiny),
        );

        $skipAll = confirm(
            label: sprintf(
                'Skip classifying %d namespaces with fewer than %d files?',
                count($tiny),
                self::MIN_FILES_FOR_BATCH_SKIP,
            ),
            default: true,
            hint: "Tiny namespaces rarely warrant Deptrac enforcement: {$names}",
        );

        if (! $skipAll) {
            return $unclassified;
        }

        $tinyKeys = array_map(static fn (DetectedNamespace $ns): string => $ns->namespace, $tiny);

        return array_values(array_filter(
            $unclassified,
            static fn (DetectedNamespace $ns): bool => ! in_array($ns->namespace, $tinyKeys, strict: true),
        ));
    }

    /**
     * Detects casing duplicates (e.g., App\Dto + App\DTOs) and asks once.
     *
     * @param  list<DetectedNamespace>  $unclassified
     * @return array{0: list<DetectedNamespace>, 1: list<LayerDecision>}
     */
    private function maybeBatchCasingDuplicates(array $unclassified): array
    {
        $groups = [];
        foreach ($unclassified as $namespace) {
            $key = strtolower($namespace->namespace);
            $groups[$key][] = $namespace;
        }

        $remaining = [];
        $preDecided = [];

        foreach ($groups as $members) {
            if (count($members) < 2) {
                $remaining[] = $members[0];

                continue;
            }

            $names = implode(
                ' + ',
                array_map(static fn (DetectedNamespace $ns): string => $ns->relativePath, $members),
            );

            $batch = confirm(
                label: "Apply the same layer to both {$names} (casing duplicate)?",
                default: true,
                hint: 'These likely should share a single layer assignment.',
            );

            if (! $batch) {
                foreach ($members as $member) {
                    $remaining[] = $member;
                }

                continue;
            }

            $decision = $this->promptForNamespace($members[0], []);

            foreach ($members as $member) {
                $preDecided[] = new LayerDecision(
                    namespace: $member->namespace,
                    layerName: $decision->layerName,
                );
            }
        }

        return [$remaining, $preDecided];
    }

    /**
     * @param  list<string>  $customLayersSoFar
     */
    private function promptForNamespace(DetectedNamespace $namespace, array $customLayersSoFar): LayerDecision
    {
        $options = $this->buildOptions($customLayersSoFar);
        $default = $this->defaultChoiceFor($namespace);
        $hint = $this->hintFor($namespace);

        /** @var string $choice */
        $choice = select(
            label: sprintf('%s (%d files) — which layer?', $namespace->relativePath, $namespace->fileCount),
            options: $options,
            default: $default,
            hint: $hint,
        );

        if ($choice === LayerOption::Skip->value) {
            return LayerDecision::skip($namespace->namespace);
        }

        if ($choice === LayerOption::Custom->value) {
            $customName = $this->promptCustomLayerName($customLayersSoFar);

            return LayerDecision::assign($namespace->namespace, $customName);
        }

        return LayerDecision::assign($namespace->namespace, $choice);
    }

    /**
     * @param  list<string>  $customLayersSoFar
     * @return array<string, string>
     */
    private function buildOptions(array $customLayersSoFar): array
    {
        $options = [];

        foreach (LayerOption::concreteLayers() as $builtIn) {
            $options[$builtIn->value] = sprintf(
                '%s — %s · ex: %s',
                $builtIn->value,
                $builtIn->description(),
                $builtIn->example(),
            );
        }

        foreach ($customLayersSoFar as $custom) {
            $options[$custom] = "{$custom} — (custom layer defined earlier in wizard)";
        }

        $options[LayerOption::Skip->value] = sprintf(
            '%s — %s',
            LayerOption::Skip->displayName(),
            LayerOption::Skip->description(),
        );

        $options[LayerOption::Custom->value] = sprintf(
            '%s — %s',
            LayerOption::Custom->displayName(),
            LayerOption::Custom->description(),
        );

        return $options;
    }

    /**
     * Pre-select the heuristic's suggestion as the default, otherwise
     * Application (safe default for unclassified namespaces).
     */
    private function defaultChoiceFor(DetectedNamespace $namespace): string
    {
        if ($namespace->suggestedLayer === LayerOption::Skip->value) {
            return LayerOption::Skip->value;
        }

        if ($namespace->suggestedLayer !== null) {
            return $namespace->suggestedLayer;
        }

        return LayerOption::Application->value;
    }

    /**
     * Educational hint rendered below the prompt. For Skip-auto namespaces,
     * shows the full "when to Skip" explanation so the user understands why.
     */
    private function hintFor(DetectedNamespace $namespace): string
    {
        if ($namespace->suggestedLayer === LayerOption::Skip->value) {
            return LayerOption::skipGuidance();
        }

        if ($namespace->suggestedLayer !== null) {
            $layer = LayerOption::tryFrom($namespace->suggestedLayer);

            if ($layer !== null) {
                return $layer->typicalHint($namespace->namespace).'  Press Enter to accept.';
            }
        }

        return 'Use ↑/↓ to navigate · Enter to select · pick Skip only for cross-cutting code.';
    }

    /**
     * @param  list<string>  $existingCustom
     */
    private function promptCustomLayerName(array $existingCustom): string
    {
        while (true) {
            /** @var string $name */
            $name = text(
                label: 'New layer name (e.g., Integration, Reporting, Jobs)',
                placeholder: 'Integration',
                required: true,
                validate: static function (string $value): ?string {
                    $trimmed = trim($value);

                    if ($trimmed === '') {
                        return 'Layer name cannot be empty.';
                    }

                    if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $trimmed) !== 1) {
                        return 'Use PascalCase: letters only, starting with uppercase (e.g., Integration).';
                    }

                    return null;
                },
                hint: 'Must be PascalCase. Examples: Integration, Reporting, Jobs.',
            );

            $name = trim($name);

            if (in_array($name, DeptracLayerSuggester::builtInLayers(), strict: true)) {
                note("'{$name}' is already a built-in layer — use the built-in option from the previous prompt.");

                continue;
            }

            if (in_array($name, $existingCustom, strict: true)) {
                $reuse = confirm(
                    label: "'{$name}' is already defined as a custom layer. Reuse it?",
                    default: true,
                );

                if ($reuse) {
                    return $name;
                }

                continue;
            }

            return $name;
        }
    }

    /**
     * @param  list<LayerDecision>  $decisions
     * @return list<string>
     */
    private function collectCustomLayerNames(array $decisions): array
    {
        $customs = [];

        foreach ($decisions as $decision) {
            if ($decision->layerName === null) {
                continue;
            }

            if (in_array($decision->layerName, DeptracLayerSuggester::builtInLayers(), strict: true)) {
                continue;
            }

            if (! in_array($decision->layerName, $customs, strict: true)) {
                $customs[] = $decision->layerName;
            }
        }

        return $customs;
    }

    /**
     * @param  list<LayerDecision>  $decisions
     * @param  list<string>  $customLayers
     */
    private function renderReview(LayerSuggestion $suggestion, array $decisions, array $customLayers): void
    {
        $grouped = $this->groupByLayer($suggestion, $decisions);

        $lines = [];

        foreach ($grouped as $layer => $namespaces) {
            if ($namespaces === []) {
                continue;
            }

            $label = $layer === null ? '(skipped)' : $layer;
            $custom = in_array((string) $layer, $customLayers, strict: true) ? ' [custom]' : '';
            $lines[] = sprintf('  %s%s', $label.$custom, ':');

            foreach ($namespaces as $namespace) {
                $lines[] = "    • {$namespace}";
            }
        }

        note("Final layer assignment:\n\n".implode("\n", $lines));
    }

    /**
     * @param  list<LayerDecision>  $decisions
     * @return array<string|null, list<string>>
     */
    private function groupByLayer(LayerSuggestion $suggestion, array $decisions): array
    {
        $grouped = [
            'Domain' => [],
            'Application' => [],
            'Presentation' => [],
            'Infrastructure' => [],
        ];

        foreach ($suggestion->detectedNamespaces as $namespace) {
            if ($namespace->suggestedLayer !== null
                && $namespace->suggestedLayer !== LayerOption::Skip->value) {
                $grouped[$namespace->suggestedLayer][] = $namespace->relativePath;
            }
        }

        foreach ($decisions as $decision) {
            $relative = $this->relativePathForNamespace($suggestion, $decision->namespace);

            $layer = $decision->layerName;

            if (! isset($grouped[$layer])) {
                $grouped[$layer] = [];
            }

            $grouped[$layer][] = $relative;
        }

        return $grouped;
    }

    private function relativePathForNamespace(LayerSuggestion $suggestion, string $namespace): string
    {
        foreach ($suggestion->detectedNamespaces as $detected) {
            if ($detected->namespace === $namespace) {
                return $detected->relativePath;
            }
        }

        return $namespace;
    }
}
