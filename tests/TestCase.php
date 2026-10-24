<?php

namespace Folklore\JsonData\Tests;

use Folklore\JsonData\Console\JsonReferencesRewriteCommand;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonDataServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', $this->getTestDatabaseConnection());
    }

    protected function getPackageProviders($app)
    {
        return [JsonDataServiceProvider::class];
    }

    /**
     * The registry of keys and the hooks are static: a new application sets them again.
     */
    protected function refreshApplication()
    {
        JsonData::flush();
        JsonReferencesRewriteCommand::flushDiscoverers();

        parent::refreshApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The fixtures declare their JSON attributes through the declarations hook, as
        // laravel-folklore's adapter of its 1.x declarations does.
        JsonData::declareUsing(fn (Model $model, string $attribute, mixed $value, array $attributes) => $model instanceof DeclaresJsonData
            ? [
                'references' => $model->jsonDataReferences($attribute, $value, $attributes),
                'columns' => $model->jsonDataColumns($attribute, $value, $attributes),
            ]
            : null);

        // Unlike SQLite in memory, a database server keeps its tables from one
        // test to the next.
        if ($this->app['config']->get('database.connections.testbench.driver') !== 'sqlite') {
            $this->artisan('db:wipe', ['--database' => 'testbench']);
        }
    }

    protected function tearDown(): void
    {
        JsonData::flush();
        JsonReferencesRewriteCommand::flushDiscoverers();

        parent::tearDown();
    }

    /**
     * SQLite in memory by default. Set DB_DRIVER to `mysql` or `pgsql`, with
     * DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD, to run the
     * tests against a MySQL or PostgreSQL server, as CI does.
     *
     * @return array<string, mixed>
     */
    protected function getTestDatabaseConnection(): array
    {
        $driver = env('DB_DRIVER', 'sqlite');
        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        if ($driver === 'pgsql') {
            return [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ];
        }

        return [
            'driver' => $driver,
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'testing'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }
}
