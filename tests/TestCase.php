<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // createApplication runs before RefreshDatabase and its migration/seed hooks.
        TestDatabaseGuard::check($app);

        return $app;
    }
}
