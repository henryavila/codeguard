<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Tests;

use Henryavila\Codeguard\CodeguardServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            CodeguardServiceProvider::class,
        ];
    }
}
