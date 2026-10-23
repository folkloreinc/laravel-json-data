<?php

namespace Folklore\Tests\Feature\Eloquent;

use Cviebrock\EloquentSluggable\ServiceProvider as SluggableServiceProvider;
use Folklore\Eloquent\JsonDataCast;
use Folklore\Models\Block;
use Folklore\Models\Media;
use Folklore\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The schema and helpers shared by the JsonDataCast characterization tests.
 */
abstract class JsonDataTestCase extends TestCase
{
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [SluggableServiceProvider::class]);
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('locale.locales', ['fr', 'en']);
        $app['config']->set('app.fallback_locale', 'fr');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testbench']);
        $this->migrateMediatheque();
        foreach (['000000_create_pages_table', '000001_create_blocks_table', '000002_create_blocks_pivot'] as $migration) {
            $this->artisan('migrate', [
                '--database' => 'testbench',
                '--path' => __DIR__.'/../../../src/migrations/2021_01_01_'.$migration.'.php',
                '--realpath' => true,
            ]);
        }
        $this->artisan('migrate', [
            '--database' => 'testbench',
            '--path' => __DIR__.'/Fixtures/migrations',
            '--realpath' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $filesPath = public_path('files');
        if (app('files')->exists($filesPath)) {
            app('files')->deleteDirectory($filesPath);
        }
        JsonDataCast::flushMacros();

        parent::tearDown();
    }

    protected function makeMedia(): Media
    {
        $media = new Media;
        $media->forceFill([
            'type' => 'image',
            'name' => 'folklore',
            'status' => 'ready',
            'visibility' => 'public',
        ])->save();

        return $media;
    }

    protected function makeBlock(string $type = 'text'): Block
    {
        return Block::create(['type' => $type]);
    }

    /**
     * The JSON column as stored, without the cast.
     */
    protected function rawData(Model $model, string $key = 'data'): ?array
    {
        $raw = DB::table($model->getTable())->where($model->getKeyName(), $model->getKey())->value($key);

        return isset($raw) ? json_decode($raw, true) : null;
    }
}
