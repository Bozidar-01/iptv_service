<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private string $listCacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Retry pauses (e.g. StreamCheckService) shouldn't slow tests down; assert with Sleep::assertSlept()
        Sleep::fake();

        // Ready channel lists (ListCacheService) in a folder of this test only
        $this->listCacheDir = sys_get_temp_dir().'/nebotv-lists-'.uniqid('', true);
        config(['iptv.list_cache_dir' => $this->listCacheDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->listCacheDir);

        parent::tearDown();
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
