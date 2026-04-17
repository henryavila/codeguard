<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Bind the base Testbench TestCase to Feature tests (which boot Laravel)
| while keeping Unit tests free of the framework boot overhead.
|
*/

use Henryavila\Codeguard\Tests\TestCase;

uses(TestCase::class)->in('Feature');
