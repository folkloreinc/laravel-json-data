<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Tests\Feature\Fixtures\Block;
use Folklore\JsonData\Tests\Feature\Fixtures\BrandGame;
use Folklore\JsonData\Tests\Feature\Fixtures\Game;
use Folklore\JsonData\Tests\Feature\Fixtures\Media;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Folklore\JsonData\Tests\Feature\Fixtures\RegistryGame;
use Folklore\JsonData\Tests\Feature\Fixtures\SluggedGame;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    protected function tearDown(): void
    {
        SluggedGame::$withEvents = false;

        parent::tearDown();
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

    /**
     * A `saved` listener that runs before the cast's and saves the model again doesn't hide
     * what the save changed (#22).
     */
    public function test_a_listener_that_saves_the_model_again_doesnt_skip_the_sync()
    {
        $block = $this->makeBlock();

        $game = new SluggedGame;
        $game->data = ['blocks' => [$block]];
        $game->save();

        $this->assertSame('game-'.$game->id, $game->fresh()?->handle);
        $this->assertSame([$block->id], $game->blocks()->pluck('blocks.id')->all());
    }

    public function test_a_listener_that_saves_the_model_again_with_events_syncs_once()
    {
        SluggedGame::$withEvents = true;
        $block = $this->makeBlock();

        $game = new SluggedGame;
        $game->data = ['blocks' => [$block]];
        $writes = $this->queriesOn('blocks_pivot', fn () => $game->save(), 'insert');

        $this->assertSame([$block->id], $game->blocks()->pluck('blocks.id')->all());
        $this->assertSame(1, $writes);
    }

    public function test_a_model_saved_without_events_is_not_synced()
    {
        $block = $this->makeBlock();

        $game = new Game;
        $game->data = ['blocks' => ['blocks://'.$block->id]];
        $game->saveQuietly();

        $this->assertSame([], $game->blocks()->pluck('blocks.id')->all());
    }

    /**
     * A JSON attribute written raw, without `set()`, is synced when the model is saved,
     * even when no model of its class went through `set()` before in the process (#37).
     */
    public function test_a_json_attribute_written_raw_is_synced_when_the_model_is_saved()
    {
        $block = $this->makeBlock();
        $game = $this->insertGame();
        $this->assertFalse($this->castListensTo(Game::class));

        $queries = $this->queriesOn('blocks_pivot', function () use ($game, $block) {
            $game->setRawAttributes([...$game->getAttributes(), 'data' => json_encode(['blocks' => ['blocks://'.$block->id]])]);
            $game->save();
        }, 'insert');

        $this->assertSame([$block->id], $game->blocks()->pluck('blocks.id')->all());
        $this->assertSame(1, $queries);

        $game->setRawAttributes([...$game->getAttributes(), 'data' => json_encode(['blocks' => []])]);
        $game->save();

        $this->assertSame([], $game->blocks()->pluck('blocks.id')->all());
    }

    public function test_a_json_attribute_written_raw_is_synced_when_the_model_is_created()
    {
        $block = $this->makeBlock();

        $game = new Game;
        $game->setRawAttributes(['handle' => 'quiz', 'data' => json_encode(['blocks' => ['blocks://'.$block->id]])]);
        $game->save();

        $this->assertSame([$block->id], $game->blocks()->pluck('blocks.id')->all());
    }

    public function test_a_json_attribute_written_raw_and_saved_without_events_is_not_synced()
    {
        $block = $this->makeBlock();
        $game = $this->insertGame();

        $game->setRawAttributes([...$game->getAttributes(), 'data' => json_encode(['blocks' => ['blocks://'.$block->id]])]);
        $game->saveQuietly();

        $this->assertSame([], $game->blocks()->pluck('blocks.id')->all());
        $this->assertFalse($this->castListensTo(Game::class));
    }

    /**
     * The cast listens to the classes that have it, not to every model saved.
     */
    public function test_saving_a_model_without_the_cast_registers_no_listener()
    {
        $this->makeMedia()->update(['name' => 'renamed']);

        $this->assertFalse($this->castListensTo(Media::class));
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
        // `medias` is also declared at questions.*.choices.*.image, without the option
        Game::$imageOptions = ['sync' => fn () => null];
        $game = new Game;
        $game->data = ['image' => ['fr' => 'medias://'.$this->makeMedia()->id]];
        $game->saveQuietly();

        $plan = JsonDataCast::syncRelations($game, null, true);

        $this->assertNull($plan['medias']['changed']);
        $this->assertNull($plan['medias']['current']);
    }

    /**
     * #18: the options of a relation declared at several paths were those of its last
     * declaration only.
     */
    public function test_an_option_given_at_one_path_applies_to_the_relation()
    {
        $synced = [];
        Game::$imageOptions = ['sync' => function ($relation, array $ids) use (&$synced) {
            $synced[] = $ids;
        }];
        $image = $this->makeMedia();
        $choiceImage = $this->makeMedia();

        $game = new Game;
        $game->data = [
            'image' => ['fr' => $image],
            'questions' => [['choices' => [['image' => $choiceImage]]]],
        ];
        $game->save();

        $this->assertCount(1, $synced);
        $this->assertEqualsCanonicalizing([$image->id, $choiceImage->id], $synced[0]);
        $this->assertSame(0, $game->medias()->count());
    }

    public function test_the_first_declaration_of_an_option_wins()
    {
        $game = new class extends Game
        {
            public function jsonDataReferences(string $attribute, mixed $value, array $attributes): array
            {
                return [
                    'image.*' => ['relation' => 'blocks', 'delete' => false],
                    'blocks.*' => ['relation' => 'blocks', 'delete' => true],
                ];
            }
        };
        $kept = $this->makeBlock();
        $removed = $this->makeBlock();
        $game->data = ['blocks' => [$kept, $removed]];
        $game->save();
        $game->data = ['blocks' => [$kept]];
        $game->save();

        // `delete` is false at image.*, declared first: the block is detached, not deleted
        $this->assertSame([$kept->id], $game->blocks()->pluck('blocks.id')->all());
        $this->assertNotNull(Block::find($removed->id));
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
        // A reference to a row that doesn't exist isn't synced
        $this->assertSame([], $plan['prizes']['attach']);
        $this->assertSame([], $plan['medias']['attach']);
        $this->assertFalse($plan['medias']['changed']);
    }

    public function test_a_save_doesnt_sync_the_references_to_rows_that_dont_exist()
    {
        $block = $this->makeBlock();
        $media = $this->makeMedia();

        $game = new Game;
        $game->data = [
            'image' => ['fr' => 'medias://'.$media->id, 'en' => 'medias://999'],
            'blocks' => ['blocks://'.$block->id, 'blocks://998', 'blocks://not-a-key'],
        ];
        $game->save();

        $this->assertSame([$media->id], DB::table('mediables')->where('mediable_id', $game->id)->pluck('media_id')->map(fn (mixed $id) => (int) $id)->all());
        $this->assertSame([$block->id], DB::table('blocks_pivot')->where('blockable_id', $game->id)->pluck('block_id')->map(fn (mixed $id) => (int) $id)->all());
    }

    /**
     * The number of queries on a table, or of one kind, that a callback runs.
     */
    /**
     * A game inserted without Eloquent, so that no model of its class was assigned or
     * saved before in the application, as in a fresh process.
     */
    private function insertGame(): Game
    {
        return Game::query()->findOrFail(DB::table('games')->insertGetId(['handle' => 'quiz']));
    }

    /**
     * Whether a model class has listeners of its own for `saving` or `saved`, as the cast
     * registers them: the service provider's listener is a wildcard.
     *
     * @param  class-string  $class
     */
    private function castListensTo(string $class): bool
    {
        $listeners = Event::getRawListeners();

        return isset($listeners['eloquent.saving: '.$class]) || isset($listeners['eloquent.saved: '.$class]);
    }

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
