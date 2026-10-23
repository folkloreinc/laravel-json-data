<?php

namespace Folklore\Tests\Feature\Eloquent;

use Folklore\Eloquent\JsonDataCast;
use Folklore\Tests\Feature\Eloquent\Fixtures\FixtureEntity;
use Folklore\Tests\Feature\Eloquent\Fixtures\Game;
use Folklore\Tests\Feature\Eloquent\Fixtures\Games;
use Folklore\Tests\Feature\Eloquent\Fixtures\Prize;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Characterization tests: they pin how JsonDataCast behaves on 1.1, with cases
 * taken from the contests platform, before 2.0 reworks it (#56, #59).
 */
class JsonDataCastTest extends JsonDataTestCase
{
    public function test_it_stores_references_and_reads_back_the_related_models()
    {
        $imageFr = $this->makeMedia();
        $imageEn = $this->makeMedia();
        $choiceImage = $this->makeMedia();

        $game = new Game;
        $game->data = [
            'image' => ['fr' => $imageFr, 'en' => $imageEn],
            'questions' => [
                ['choices' => [['label' => 'A', 'image' => $choiceImage], ['label' => 'B']]],
            ],
        ];
        $game->save();
        JsonDataCast::syncRelations($game);

        $raw = $this->rawData($game);
        $this->assertEquals(
            ['fr' => 'medias://'.$imageFr->id, 'en' => 'medias://'.$imageEn->id],
            $raw['image'],
        );
        $this->assertSame('medias://'.$choiceImage->id, $raw['questions'][0]['choices'][0]['image']);
        $this->assertSame(['label' => 'B'], $raw['questions'][0]['choices'][1]);

        $data = Game::find($game->id)->data;
        $this->assertTrue($data['image']['fr']->is($imageFr));
        $this->assertTrue($data['image']['en']->is($imageEn));
        $this->assertTrue($data['questions'][0]['choices'][0]['image']->is($choiceImage));
        $this->assertSame('A', $data['questions'][0]['choices'][0]['label']);
    }

    public function test_it_accepts_an_id_an_array_or_an_entity_when_writing()
    {
        $first = $this->makeMedia();
        $second = $this->makeMedia();
        $third = $this->makeMedia();

        $game = new Game;
        $game->data = [
            'image' => ['fr' => $first->id, 'en' => ['id' => $second->id, 'name' => 'ignored']],
            'questions' => [['choices' => [['image' => new FixtureEntity($third)]]]],
        ];
        $game->save();

        $raw = $this->rawData($game);
        $this->assertSame('medias://'.$first->id, $raw['image']['fr']);
        $this->assertSame('medias://'.$second->id, $raw['image']['en']);
        $this->assertSame('medias://'.$third->id, $raw['questions'][0]['choices'][0]['image']);
    }

    public function test_it_leaves_the_values_outside_the_declared_paths_alone()
    {
        $game = new Game;
        $game->data = [
            'description' => ['fr' => 'Un quiz'],
            'cover' => 'medias://12',
            'image' => ['fr' => null],
        ];
        $game->save();

        // MySQL reorders the keys of a JSON column
        $this->assertEquals(
            ['description' => ['fr' => 'Un quiz'], 'cover' => 'medias://12', 'image' => ['fr' => null]],
            $this->rawData($game),
        );
        $this->assertSame('medias://12', Game::find($game->id)->data['cover']);
    }

    /**
     * A key that isn't mapped resolves through the owner's relation, as in 1.x:
     * only once the row is attached (docs/decisions/0016).
     */
    public function test_a_reference_resolves_only_through_the_synced_relation()
    {
        $block = $this->makeBlock();

        $game = new Game;
        $game->data = ['blocks' => [$block]];
        $game->save();

        $this->assertSame('blocks://'.$block->id, $this->rawData($game)['blocks'][0]);
        $this->assertNull(Game::find($game->id)->data['blocks'][0]);

        JsonDataCast::syncRelations($game);

        $this->assertTrue(Game::find($game->id)->data['blocks'][0]->is($block));
    }

    /**
     * The medias module maps the `medias` key: a media reference resolves by its id,
     * attached or not, and MediaReferences::allowUsing() scopes it (decisions/0008).
     */
    public function test_a_media_reference_resolves_by_its_key_before_it_is_synced()
    {
        $media = $this->makeMedia();

        $game = new Game;
        $game->data = ['image' => ['fr' => $media]];
        $game->save();

        $this->assertSame('medias://'.$media->id, $this->rawData($game)['image']['fr']);
        $this->assertTrue(Game::find($game->id)->data['image']['fr']->is($media));
    }

    public function test_a_lazy_relation_resolves_only_once_loaded()
    {
        $prize = Prize::create(['handle' => 'grand-prize']);

        $game = new Game;
        $game->data = ['prizes' => [['prize' => $prize, 'quantity' => 1]]];
        $game->save();
        JsonDataCast::syncRelations($game);

        $this->assertSame('prizes://'.$prize->id, $this->rawData($game)['prizes'][0]['prize']);
        $this->assertNull(Game::find($game->id)->data['prizes'][0]['prize']);
        $this->assertTrue(Game::with('prizes')->find($game->id)->data['prizes'][0]['prize']->is($prize));
        $this->assertSame(1, Game::find($game->id)->data['prizes'][0]['quantity']);
    }

    public function test_a_macro_of_the_model_takes_precedence_over_a_global_one()
    {
        $media = $this->makeMedia();
        $game = new Game;
        $game->data = ['image' => ['fr' => $media]];
        $game->save();

        JsonDataCast::macro('medias:get', fn ($item) => 'global '.$item);
        $this->assertSame('global medias://'.$media->id, Game::find($game->id)->data['image']['fr']);

        JsonDataCast::macro(Game::class.':medias:get', fn ($item) => 'game '.$item);
        $this->assertSame('game medias://'.$media->id, Game::find($game->id)->data['image']['fr']);
    }

    public function test_a_before_set_macro_prepares_the_value_before_it_is_serialized()
    {
        $media = $this->makeMedia();
        JsonDataCast::macro('medias:before_set', fn ($item) => $item === 'folklore.png' ? $media : $item);

        $game = new Game;
        $game->data = ['image' => ['fr' => 'folklore.png']];
        $game->save();

        $this->assertSame('medias://'.$media->id, $this->rawData($game)['image']['fr']);
    }

    /**
     * The media of JSON data are synced under their own handle of the
     * mediatheque's pivot: a media attached otherwise stays (#56).
     */
    public function test_sync_keeps_the_media_attached_otherwise()
    {
        $cover = $this->makeMedia();
        $image = $this->makeMedia();

        $game = new Game;
        $game->data = ['image' => ['fr' => $image]];
        $game->save();
        $game->attachMedia($cover, 'cover');
        JsonDataCast::syncRelations($game);

        $this->assertTrue($game->fresh()?->media('cover')?->is($cover));
        $this->assertSame([$image->id], $game->fresh()?->medias(JsonDataCast::MEDIAS_HANDLE)->map->getKey()->all());

        $game->data = ['image' => []];
        $game->save();
        JsonDataCast::syncRelations($game);

        $this->assertTrue($game->fresh()?->media('cover')?->is($cover));
        $this->assertSame([], $game->fresh()?->medias(JsonDataCast::MEDIAS_HANDLE)->map->getKey()->all());
    }

    public function test_sync_attaches_and_detaches_the_referenced_models()
    {
        $imageFr = $this->makeMedia();
        $imageEn = $this->makeMedia();
        $firstBlock = $this->makeBlock();
        $secondBlock = $this->makeBlock();

        $game = new Game;
        $game->data = [
            'image' => ['fr' => $imageFr, 'en' => $imageEn],
            'blocks' => [$firstBlock, $secondBlock],
        ];
        $game->save();
        $map = JsonDataCast::syncRelations($game);

        $this->assertEqualsCanonicalizing([$imageFr->id, $imageEn->id], $map['medias']['ids']);
        $this->assertEqualsCanonicalizing([$imageFr->id, $imageEn->id], $game->medias()->get()->modelKeys());
        $this->assertEqualsCanonicalizing([$firstBlock->id, $secondBlock->id], $game->blocks()->pluck('blocks.id')->all());

        $game->data = ['image' => ['fr' => $imageFr], 'blocks' => [$secondBlock]];
        $game->save();
        JsonDataCast::syncRelations($game);

        $this->assertSame([$imageFr->id], $game->medias()->get()->modelKeys());
        $this->assertSame([$secondBlock->id], $game->blocks()->pluck('blocks.id')->all());
    }

    public function test_sync_gathers_the_references_of_every_path_of_a_relation_once()
    {
        $image = $this->makeMedia();
        $choiceImage = $this->makeMedia();

        $game = new Game;
        $game->data = [
            'image' => ['fr' => $image, 'en' => $image],
            'questions' => [['choices' => [['image' => $choiceImage], ['image' => $image]]]],
        ];
        $game->save();
        JsonDataCast::syncRelations($game);

        $this->assertEqualsCanonicalizing([$image->id, $choiceImage->id], $game->medias()->get()->modelKeys());
        $this->assertSame(2, DB::table($game->medias()->getTable())->count());
    }

    /**
     * 1.x synced a stored reference to the relation named by its key, whatever
     * the path: `blocks://1` under `image.*` was synced to `blocks`. In 2.0, only
     * the references of the relation a path is declared with count.
     */
    public function test_sync_only_counts_the_references_of_the_declared_relation()
    {
        $block = $this->makeBlock();
        $game = Game::create(['handle' => 'quiz']);
        DB::table('games')->where('id', $game->id)->update([
            'data' => json_encode(['image' => ['fr' => 'blocks://'.$block->id]]),
        ]);

        JsonDataCast::syncRelations(Game::find($game->id));

        $this->assertSame([], $game->blocks()->pluck('blocks.id')->all());
        $this->assertSame(0, $game->medias()->count());
    }

    public function test_it_extracts_columns_from_the_json_data()
    {
        $game = new Game;
        $game->data = ['title' => ['fr' => 'Le quiz', 'en' => 'The quiz']];
        $game->save();

        $this->assertSame(['Le quiz', 'The quiz'], [$game->title_fr, $game->title_en]);
        $this->assertSame(
            ['Le quiz', 'The quiz'],
            array_values((array) DB::table('games')->where('id', $game->id)->first(['title_fr', 'title_en'])),
        );

        $game->data = ['title' => ['fr' => 'Le quiz']];
        $game->save();

        $this->assertSame('Le quiz', $game->fresh()->title_fr);
        $this->assertNull($game->fresh()->title_en);
    }

    public function test_the_repository_writes_the_json_data_extracts_columns_and_syncs()
    {
        $image = $this->makeMedia();
        $block = $this->makeBlock();
        $games = $this->app->make(Games::class);

        $entity = $games->create([
            'handle' => 'quiz',
            'title' => ['fr' => 'Le quiz'],
            'image' => ['fr' => $image],
            'blocks' => [$block],
        ]);
        $game = $entity->getModel();

        $this->assertSame('quiz', $game->handle);
        $this->assertSame('Le quiz', $game->title_fr);
        $this->assertEquals(
            ['title' => ['fr' => 'Le quiz'], 'image' => ['fr' => 'medias://'.$image->id], 'blocks' => ['blocks://'.$block->id]],
            $this->rawData($game),
        );
        $this->assertTrue($game->data['image']['fr']->is($image));
        $this->assertSame([$block->id], $game->blocks()->pluck('blocks.id')->all());
    }

    public function test_a_partial_update_through_the_repository_keeps_the_other_keys()
    {
        $image = $this->makeMedia();
        $games = $this->app->make(Games::class);
        $game = $games->create([
            'handle' => 'quiz',
            'title' => ['fr' => 'Le quiz', 'en' => 'The quiz'],
            'image' => ['fr' => $image],
        ]);

        $games->update($game->id(), ['title' => ['fr' => 'Le nouveau quiz']]);

        $raw = $this->rawData($game->getModel());
        $this->assertSame(['fr' => 'Le nouveau quiz'], $raw['title']);
        $this->assertSame(['fr' => 'medias://'.$image->id], $raw['image']);
    }

    public function test_the_fixtures_store_the_json_data_as_jsonb_on_postgresql()
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The fixtures use jsonb on PostgreSQL only.');
        }

        $this->assertSame('jsonb', Schema::getColumnType('games', 'data'));
    }

    public function test_reading_a_value_the_database_reformatted_leaves_the_model_clean()
    {
        $image = $this->makeMedia();
        $game = Game::create(['handle' => 'quiz']);
        // As jsonb and MySQL return it: keys reordered, spaces after separators
        DB::table('games')->where('id', $game->id)->update([
            'data' => '{"blocks": [], "image": {"fr": "medias://'.$image->id.'"}, "title": {"fr": "Le quiz"}}',
        ]);
        $game = Game::findOrFail($game->id);

        $this->assertTrue($game->data['image']['fr']->is($image));
        $this->assertFalse($game->isDirty('data'));
        $game->save();
        $this->assertFalse($game->wasChanged());
    }
}
