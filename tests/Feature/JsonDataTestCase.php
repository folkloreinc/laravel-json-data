<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\JsonData;
use Folklore\JsonData\Tests\Feature\Fixtures\Block;
use Folklore\JsonData\Tests\Feature\Fixtures\Game;
use Folklore\JsonData\Tests\Feature\Fixtures\Media;
use Folklore\JsonData\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The schema and helpers shared by the JsonDataCast characterization tests.
 */
abstract class JsonDataTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('locale.locales', ['fr', 'en']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // As laravel-folklore's medias module on a site upgraded from 1.x: `medias://12`
        JsonData::map(['medias' => Media::class]);

        $this->artisan('migrate', [
            '--database' => 'testbench',
            '--path' => __DIR__.'/Fixtures/migrations',
            '--realpath' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Game::$imageOptions = [];

        parent::tearDown();
    }

    protected function makeMedia(): Media
    {
        return Media::create(['name' => 'folklore']);
    }

    protected function makeBlock(string $type = 'text'): Block
    {
        return Block::create(['type' => $type]);
    }

    /**
     * The JSON column as stored, without the cast.
     *
     * @return array<array-key, mixed>|null
     */
    protected function rawData(Model $model, string $key = 'data'): ?array
    {
        $raw = DB::table($model->getTable())->where($model->getKeyName(), $model->getKey())->value($key);

        return isset($raw) ? json_decode($raw, true) : null;
    }
}
