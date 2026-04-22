<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use function Laravel\Prompts\multiselect;

final class PhpstanExtensionSelector
{
    /**
     * Interactive multiselect — returns the set of PhpstanExtension cases the
     * user wants active. TestQuality auto-enables DisallowedCalls since it
     * depends on it. Peststan is preselected when Pest is detected so users
     * don't have to know what it does to benefit.
     *
     * @param  list<PhpstanExtension>  $preselected
     * @return list<PhpstanExtension>
     */
    public function prompt(array $preselected, bool $pestDetected = false): array
    {
        if ($pestDetected && ! in_array(PhpstanExtension::Peststan, $preselected, strict: true)) {
            $preselected[] = PhpstanExtension::Peststan;
        }

        $options = [];
        foreach (PhpstanExtension::all() as $extension) {
            $options[$extension->value] = sprintf(
                '%s — %s',
                $extension->displayName(),
                $extension->description(),
            );
        }

        $preselectedValues = array_map(
            static fn (PhpstanExtension $ext): string => $ext->value,
            $preselected,
        );

        /** @var array<int, string> $selected */
        $selected = multiselect(
            label: 'Which PHPStan extensions do you want active in phpstan.neon?',
            options: $options,
            default: $preselectedValues,
            hint: 'Space to toggle · Enter to confirm · All ship bundled — unchecked items stay commented in the stub',
        );

        return $this->resolveDependencies(
            array_values(array_map(
                static fn (string $value): PhpstanExtension => PhpstanExtension::from($value),
                $selected,
            )),
        );
    }

    /**
     * Non-interactive resolver: respects saved choice or falls back to the
     * default set. Adds Peststan when Pest is detected, regardless of the
     * branch taken — so CI runs on Pest projects get it without a prior
     * interactive install.
     *
     * @param  list<PhpstanExtension>  $saved
     * @return list<PhpstanExtension>
     */
    public function autoResolve(array $saved, bool $pestDetected = false): array
    {
        $base = $saved === []
            ? PhpstanExtension::defaultEnabled()
            : $saved;

        if ($pestDetected && ! in_array(PhpstanExtension::Peststan, $base, strict: true)) {
            $base[] = PhpstanExtension::Peststan;
        }

        return $this->resolveDependencies($base);
    }

    /**
     * Ensures dependent extensions auto-enable their deps (e.g., TestQuality
     * requires DisallowedCalls). Keeps user experience "just works".
     *
     * @param  list<PhpstanExtension>  $selected
     * @return list<PhpstanExtension>
     */
    private function resolveDependencies(array $selected): array
    {
        $resolved = [];
        foreach ($selected as $extension) {
            if (! in_array($extension, $resolved, strict: true)) {
                $resolved[] = $extension;
            }

            $dependency = $extension->isDependOn();
            if ($dependency !== null && ! in_array($dependency, $resolved, strict: true)) {
                $resolved[] = $dependency;
            }
        }

        return $resolved;
    }
}
