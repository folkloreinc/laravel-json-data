<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\Tests\Feature\Fixtures\BrandGame;
use Folklore\JsonData\Tests\Feature\Fixtures\Game;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cast syncs the relations of a model's references when the model is saved, however
 * it is saved (docs/json-data.md, "Syncing", folkloreinc/laravel-folklore#97).
 */
class JsonDataSyncTest extends JsonDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('games', function (Blueprint $table) {
            $table->jsonb('images')->nullable();
        });
    }

    public function test_saving_the_model_syncs_its_references()
    {
        $media = $this->makeMedia();
        $block = $this->makeBlock();

        $game = new Game;
        $game->data = ['image' => ['fr' => $media], 'blocks' => [$block]];
        $game->save();

        $this->assertSame([$media->id], $game->medias()->get()->modelKeys());
        $this->assertSame([$block->id], $game->blocks()->pluck('blocks.id')->all());

        $game->data = ['image' => []];
        $game->save();

        $this->assertSame([], $game->medias()->get()->modelKeys());
        $this->assertSame([], $game->blocks()->pluck('blocks.id')->all());
    }

    public function test_a_model_saved_without_events_is_not_synced()
    {
        $block = $this->makeBlock();

        $game = new Game;
        $game->data = ['blocks' => ['blocks://'.$block->id]];
        $game->saveQuietly();

        $this->assertSame([], $game->blocks()->pluck('blocks.id')->all());
    }

    public function test_a_save_that_doesnt_change_the_json_attributes_doesnt_sync()
    {
        $game = new Game;
        $game->data = ['blocks' => [$this->makeBlock()]];
        $game->save();

        $queries = $this->queriesOn('blocks_pivot', function () use ($game) {
            $game->handle = 'renamed';
            $game->save();
        });

        $this->assertSame(0, $queries);
    }

    public function test_only_the_relations_of_the_attributes_that_changed_are_synced()
    {
        $media = $this->makeMedia();
        $first = Prize::create(['handle' => 'first']);
        $second = Prize::create(['handle' => 'second']);

        $game = new BrandGame(['handle' => 'quiz']);
        $game->data = ['image' => ['fr' => $media]];
        $game->images = ['logo' => $first];
        $game->save();
        $this->assertSame([$first->id], $game->prizes()->pluck('prizes.id')->all());

        $queries = $this->queriesOn('mediables', function () use ($game, $second) {
            $game->images = ['logo' => $second];
            $game->save();
        });

        // `prizes` is declared in `images`, which changed; `medias` only in `data`
        $this->assertSame([$second->id], $game->prizes()->pluck('prizes.id')->all());
        $this->assertSame(0, $queries);
        $this->assertSame([$media->id], $game->medias()->get()->modelKeys());
    }

    public function test_the_synced_relations_are_unset_instead_of_refreshing_the_model()
    {
        $block = $this->makeBlock();
        $game = Game::create(['handle' => 'quiz']);
        $game->load('blocks');
        $this->assertSame([], $game->blocks->all());

        $game->data = ['blocks' => [$block]];
        $game->save();

        $this->assertFalse($game->relationLoaded('blocks'));
        $this->assertSame([$block->id], $game->blocks->map->getKey()->all());
    }

    /**
     * The number of queries on a table, or of one kind, that a callback runs.
     */
    private function queriesOn(string $table, callable $callback, ?string $kind = null): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], $table)
                && (is_null($kind) || str_starts_with(strtolower(ltrim($query['query'])), $kind)))
            ->count();
    }
}
