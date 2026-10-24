<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Tests\Feature\Fixtures\Contest;
use Folklore\JsonData\Tests\Feature\Fixtures\Game;
use Folklore\JsonData\Tests\Feature\Fixtures\Page as PageModel;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Illuminate\Support\Facades\DB;

/**
 * Regression tests for the bugs of JsonDataCast fixed in laravel-folklore 1.1.8
 * (folkloreinc/laravel-folklore#59), and the behaviours their fixes keep on purpose. The
 * ones that go through laravel-folklore's repositories stay in laravel-folklore.
 */
class JsonDataCastBugsTest extends JsonDataTestCase
{
    public function test_the_sync_saves_a_belongs_to_reference_without_a_refresh()
    {
        $parent = PageModel::create(['handle' => 'parent']);
        $child = PageModel::create(['handle' => 'child', 'data' => ['parent' => $parent]]);

        JsonDataCast::syncRelations($child);

        $this->assertFalse($child->isDirty('parent_id'));
        $this->assertEquals($parent->id, $child->parent_id);
        $this->assertEquals($parent->id, PageModel::find($child->id)->parent_id);
        $this->assertTrue($child->data['parent']->is($parent));
    }

    /**
     * Fixed: the repository merged the update into the resolved data, where a
     * lazy relation that isn't loaded reads as null, and wrote it back. It now merges
     * into getValueWithUnresolvedReferences().
     */
    public function test_a_partial_update_keeps_the_references_that_dont_resolve()
    {
        $prize = Prize::create(['handle' => 'grand-prize']);
        $game = new Game(['handle' => 'quiz']);
        $game->data = ['prizes' => [['prize' => $prize, 'quantity' => 1]]];
        $game->save();
        $this->assertSame('prizes://'.$prize->id, $this->rawData($game)['prizes'][0]['prize']);

        $game = Game::findOrFail($game->id);
        $game->data = array_merge(JsonDataCast::getValueWithUnresolvedReferences($game, 'data'), ['title' => ['fr' => 'Le quiz']]);
        $game->save();

        $this->assertEquals(
            [['prize' => 'prizes://'.$prize->id, 'quantity' => 1]],
            $this->rawData($game)['prizes'],
        );
        $this->assertTrue(Game::with('prizes')->find($game->id)->data['prizes'][0]['prize']->is($prize));
    }

    public function test_a_partial_update_still_clears_a_reference_it_sets_to_null()
    {
        $prize = Prize::create(['handle' => 'grand-prize']);
        $game = new Game(['handle' => 'quiz']);
        $game->data = ['prizes' => [['prize' => $prize, 'quantity' => 1]]];
        $game->save();

        $game = Game::findOrFail($game->id);
        $game->data = array_merge(JsonDataCast::getValueWithUnresolvedReferences($game, 'data'), ['prizes' => [['prize' => null, 'quantity' => 2]]]);
        $game->save();

        $this->assertEquals(
            [['prize' => null, 'quantity' => 2]],
            $this->rawData($game)['prizes'],
        );
    }

    /**
     * Fixed: to_id() returns strings as they are, so a reference read raw and
     * written back got a second prefix and no longer resolved, and the sync
     * attached `medias://{id}` as an id, which MySQL refuses.
     */
    public function test_writing_a_stored_reference_keeps_it_as_is()
    {
        $media = $this->makeMedia();

        $game = new Game;
        $game->data = ['image' => ['fr' => 'medias://'.$media->id]];
        $game->save();
        JsonDataCast::syncRelations($game);

        $this->assertSame('medias://'.$media->id, $this->rawData($game)['image']['fr']);
        $this->assertSame([$media->id], $game->medias()->get()->modelKeys());
        $this->assertTrue(Game::find($game->id)->data['image']['fr']->is($media));
    }

    public function test_a_before_set_callback_doesnt_receive_a_stored_reference()
    {
        $media = $this->makeMedia();
        $received = [];
        Game::$imageOptions = ['before_set' => function ($item) use (&$received) {
            $received[] = $item;

            return $item;
        }];

        $game = new Game;
        $game->data = ['image' => ['fr' => 'medias://'.$media->id, 'en' => $media]];
        $game->save();

        $this->assertCount(1, $received);
        $this->assertTrue($received[0]->is($media));
    }

    /**
     * Fixed in 2.0: another string at a reference path (a URL, a reference of
     * another relation) was taken for an id, written as `medias://https://…`,
     * and synced to the pivot, which SQLite stored and MySQL refused. It is not
     * a media, so it is written as null and not synced (docs/json-data.md).
     */
    public function test_another_string_at_a_reference_path_is_not_taken_as_an_id()
    {
        $game = new Game;
        $game->data = ['image' => ['fr' => 'https://example.com/image.png', 'en' => 'blocks://1']];
        $game->save();

        $this->assertNull($this->rawData($game)['image']['fr']);
        $this->assertNull($this->rawData($game)['image']['en']);

        JsonDataCast::syncRelations($game);

        $this->assertSame([], DB::table($game->medias()->getTable())->pluck('media_id')->all());
    }

    /**
     * Fixed: the sync of a HasMany set the foreign key of every referenced
     * row, so a row of another parent moved.
     */
    public function test_sync_of_a_has_many_leaves_the_rows_of_another_parent()
    {
        $first = Contest::create();
        $second = Contest::create();
        $prize = Prize::create(['contest_id' => $first->id, 'handle' => 'grand-prize']);

        $second->data = ['featured' => [$prize]];
        $second->save();
        JsonDataCast::syncRelations($second);

        $this->assertEquals($first->id, $prize->fresh()->contest_id);
        $this->assertSame(0, $second->prizes()->count());
        $this->assertNull(Contest::find($second->id)->data['featured'][0]);
    }

    public function test_sync_of_a_has_many_attaches_the_rows_without_a_parent()
    {
        $contest = Contest::create();
        $orphan = Prize::create(['handle' => 'grand-prize']);
        $own = Prize::create(['contest_id' => $contest->id, 'handle' => 'second-prize']);

        $contest->data = ['featured' => [$orphan, $own]];
        $contest->save();
        JsonDataCast::syncRelations($contest);

        $this->assertEquals($contest->id, $orphan->fresh()->contest_id);
        $this->assertEqualsCanonicalizing([$orphan->id, $own->id], $contest->prizes()->pluck('id')->all());
        $this->assertTrue(Contest::find($contest->id)->data['featured'][0]->is($orphan));
    }
}
