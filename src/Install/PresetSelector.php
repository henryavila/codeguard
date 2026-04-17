<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Henryavila\Codeguard\Testing\Preset;

use function Laravel\Prompts\select;

final class PresetSelector
{
    /**
     * Auto-select the preset based on environment signals without prompting.
     */
    public function autoSelect(EnvironmentInfo $env): Preset
    {
        return $env->usesNodeInProject() ? Preset::Full : Preset::Default;
    }

    /**
     * Prompt the user, pre-selecting the auto-detected preset.
     */
    public function promptWithDefault(EnvironmentInfo $env): Preset
    {
        $default = $this->autoSelect($env);

        $options = [
            Preset::Default->value => $this->decorateOption(Preset::Default, $default),
            Preset::Full->value => $this->decorateOption(Preset::Full, $default),
        ];

        /** @var string $choice */
        $choice = select(
            label: 'Which preset do you want to install?',
            options: $options,
            default: $default->value,
            hint: $this->hintForEnvironment($env),
        );

        return Preset::from($choice);
    }

    private function decorateOption(Preset $option, Preset $recommended): string
    {
        $label = $option->label();

        return $option === $recommended
            ? $label.' ⭐ recommended'
            : $label;
    }

    public function resolveFromFlag(string $flag): Preset
    {
        return match ($flag) {
            'default', 'codeguard' => Preset::Default,
            'full', 'codeguard-full' => Preset::Full,
            default => throw new \InvalidArgumentException(
                "Unknown preset '{$flag}'. Expected 'default' or 'full'."
            ),
        };
    }

    private function hintForEnvironment(EnvironmentInfo $env): string
    {
        return match ($env->nodeConfidence()) {
            'high' => 'Detected Node.js in project — codeguard-full is recommended.',
            'medium' => 'Node.js available globally but project does not use it — codeguard (PHP-only) recommended.',
            default => 'No Node.js detected — codeguard (PHP-only) is the only supported preset.',
        };
    }
}
