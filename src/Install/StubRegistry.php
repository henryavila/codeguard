<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Henryavila\Codeguard\Testing\Preset;

final class StubRegistry
{
    /**
     * @return list<StubDefinition>
     */
    public function stubsFor(Preset $preset): array
    {
        $default = [
            new StubDefinition('pint.json.stub', 'pint.json', 'pint'),
            new StubDefinition('phpstan.neon.stub', 'phpstan.neon', 'phpstan'),
            new StubDefinition('phpstan-test-quality.neon.stub', 'phpstan-test-quality.neon', 'phpstan'),
            new StubDefinition('deptrac.yaml.stub', 'deptrac.yaml', 'deptrac'),
            new StubDefinition('infection.json5.stub', 'infection.json5', 'infection'),
            new StubDefinition('captainhook.json.stub', 'captainhook.json', 'captainhook'),
            new StubDefinition('captainhook.json.README.md.stub', 'captainhook.json.README.md', 'captainhook'),
            new StubDefinition('phpunit.xml.stub', 'phpunit.xml', 'phpunit'),
        ];

        if ($preset === Preset::Default) {
            return $default;
        }

        return [
            ...$default,
            new StubDefinition('.jscpd.json.stub', '.jscpd.json', 'jscpd'),
            new StubDefinition(
                'tests/Arch/TestQualityTest.php.stub',
                'tests/Arch/TestQualityTest.php',
                'test-quality',
            ),
        ];
    }
}
