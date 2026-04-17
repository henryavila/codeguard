<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use function Laravel\Prompts\multiselect;

final class PhpstanExtensionSelector
{
    /**
     * Interactive multiselect — returns the set of PhpstanExtension cases the
     * user wants active. TestQuality auto-enables DisallowedCalls since it
     * depends on it.
     *
     * @param  list<PhpstanExtension>  $preselected
     * @return list<PhpstanExtension>
     */
    public function prompt(array $preselected): array
    {
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
     * Non-interactive resolver: respects saved choice or falls back to all on.
     *
     * @param  list<PhpstanExtension>  $saved
     * @return list<PhpstanExtension>
     */
    public function autoResolve(array $saved): array
    {
        return $saved === []
            ? PhpstanExtension::defaultEnabled()
            : $this->resolveDependencies($saved);
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
