<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Sleep;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Retry pauses (e.g. StreamCheckService) shouldn't slow tests down; assert with Sleep::assertSlept()
        Sleep::fake();
    }

    /**
     * Safety net: RefreshDatabase drops every table, so never let it run against a real database
     * (e.g. when environment variables from Docker point at the dev MySQL).
     */
    protected function beforeRefreshingDatabase()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException("Refusing to refresh database [{$connection}:{$database}] in tests; expected sqlite :memory:.");
        }
    }
}
