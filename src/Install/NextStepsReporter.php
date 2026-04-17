<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Henryavila\Codeguard\Testing\Preset;

final class NextStepsReporter
{
    /**
     * @return list<array{gate: string, action: string, command: string}>
     */
    public function nextSteps(Preset $preset): array
    {
        $default = [
            [
                'gate' => 'PHPStan',
                'action' => 'Review level in phpstan.neon (currently 5). Raise progressively.',
                'command' => 'composer codeguard:check',
            ],
            [
                'gate' => 'Deptrac',
                'action' => 'Verify layers in deptrac.yaml match your architecture.',
                'command' => './vendor/bin/deptrac analyse',
            ],
            [
                'gate' => 'Infection',
                'action' => 'Generate a baseline before enabling in CI.',
                'command' => './vendor/bin/infection --initial-tests-only',
            ],
            [
                'gate' => 'Lefthook',
                'action' => 'Install hooks and test with an empty commit.',
                'command' => 'lefthook install && git commit --allow-empty -m test',
            ],
            [
                'gate' => 'Pint',
                'action' => 'Zero config. Runs automatically on commit.',
                'command' => './vendor/bin/pint',
            ],
        ];

        if ($preset === Preset::Default) {
            return $default;
        }

        return [
            ...$default,
            [
                'gate' => 'jscpd',
                'action' => 'Review threshold in .jscpd.json (default 3%).',
                'command' => 'npx jscpd',
            ],
            [
                'gate' => 'Insights',
                'action' => 'Zero config. Run manually for metrics.',
                'command' => 'php artisan insights',
            ],
            [
                'gate' => 'TestQualityTest',
                'action' => 'Allowlist project-specific exceptions in tests/Arch/TestQualityTest.php.',
                'command' => './vendor/bin/pest --testsuite=Arch',
            ],
        ];
    }

    public function documentationUrl(): string
    {
        return 'https://github.com/henryavila/codeguard#configuration';
    }
}
