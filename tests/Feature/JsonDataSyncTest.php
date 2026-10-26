<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Tests\Feature\Fixtures\BrandGame;
use Folklore\JsonData\Tests\Feature\Fixtures\Game;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Folklore\JsonData\Tests\Feature\Fixtures\RegistryGame;
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

    public function test_pretend_plans_the_sync_without_writing()
    {
        $media = $this->makeMedia();
        $kept = $this->makeBlock();
        $removed = $this->makeBlock();
        $game = new Game;
        $game->data = ['blocks' => [$kept, $removed]];
        $game->save();
        // References, since saveQuietly() doesn't prepare models
        $game->data = ['image' => ['fr' => 'medias://'.$media->id], 'blocks' => ['blocks://'.$kept->id]];
        $game->saveQuietly();
        $game->load('blocks');

        $writes = $this->queriesOn('', function () use ($game, &$plan) {
            $plan = JsonDataCast::syncRelations($game, null, true);
        }, 'insert') + $this->queriesOn('', fn () => JsonDataCast::syncRelations($game, null, true), 'delete');

        $this->assertSame(0, $writes);
        $this->assertTrue($game->relationLoaded('blocks'));
        $this->assertTrue($plan['medias']['changed']);
        $this->assertEquals([$media->id], $plan['medias']['attach']);
        $this->assertSame([], $plan['medias']['detach']);
        $this->assertTrue($plan['blocks']['changed']);
        $this->assertSame([], $plan['blocks']['attach']);
        $this->assertEquals([$removed->id], $plan['blocks']['detach']);
        $this->assertEqualsCanonicalizing([$kept->id, $removed->id], $plan['blocks']['current']);
        $this->assertFalse($plan['prizes']['changed']);
        $this->assertSame([], $plan['blocks']['unresolved']);
        $this->assertSame([$kept->id, $removed->id], $game->blocks()->pluck('blocks.id')->sort()->values()->all());

        JsonDataCast::syncRelations($game);
        $after = JsonDataCast::syncRelations($game, null, true);

        $this->assertFalse($after['medias']['changed']);
        $this->assertFalse($after['blocks']['changed']);
    }

    public function test_pretend_plans_only_the_given_attributes()
    {
        $game = new BrandGame(['handle' => 'quiz']);
        $game->data = ['image' => ['fr' => 'medias://'.$this->makeMedia()->id]];
        $game->images = ['logo' => 'prizes://'.Prize::create(['handle' => 'first'])->id];
        $game->saveQuietly();

        $plan = JsonDataCast::syncRelations($game, ['images'], true);

        $this->assertSame(['prizes'], array_keys($plan));
        $this->assertTrue($plan['prizes']['changed']);
    }

    public function test_pretend_cant_compare_a_relation_with_a_sync_callable()
    {
        $game = new class extends Game
        {
            public function jsonDataReferences(string $attribute, mixed $value, array $attributes): array
            {
                return ['image.*' => ['relation' => 'medias', 'sync' => fn () => null]];
            }
        };
        $game->data = ['image' => ['fr' => 'medias://'.$this->makeMedia()->id]];
        $game->saveQuietly();

        $plan = JsonDataCast::syncRelations($game, null, true);

        $this->assertNull($plan['medias']['changed']);
        $this->assertNull($plan['medias']['current']);
    }

    public function test_pretend_lists_the_references_that_dont_resolve()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $id = DB::table('games')->insertGetId(['data' => json_encode([
            'prizes' => [['prize' => 'prize://missing'], ['prize' => 'prizes://999']],
            'image' => ['fr' => 'medias://998'],
        ])]);
        $game = RegistryGame::findOrFail($id);

        $plan = JsonDataCast::syncRelations($game, null, true);

        $this->assertEqualsCanonicalizing(
            [['prize://missing', 'data', 'prizes.0.prize', 'prizes'], ['prizes://999', 'data', 'prizes.1.prize', 'prizes']],
            array_map(fn ($entry) => [(string) $entry, $entry->attribute, $entry->path, $entry->relation], $plan['prizes']['unresolved']),
        );
        $this->assertSame(['medias://998'], array_map('strval', $plan['medias']['unresolved']));
        // As a save does, a reference by the related model's key is synced, found or not
        $this->assertEquals(['999'], $plan['prizes']['attach']);
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
