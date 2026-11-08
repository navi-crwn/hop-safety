<?php

namespace Tests;

use Hop\Safety\SafetyServiceProvider;
use Orchestra\Testbench\TestCase as Base;

/** The package under test, on a bare testbench application. */
abstract class TestCase extends Base
{
    protected function getPackageProviders($app): array
    {
        return [SafetyServiceProvider::class];
    }
}
