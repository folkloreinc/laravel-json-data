<?php

namespace Folklore\Tests\Feature\Eloquent;

use Folklore\Contracts\Repositories\Pages;
use Folklore\Eloquent\JsonDataCast;
use Folklore\Models\Page as PageModel;
use Folklore\Tests\Feature\Eloquent\Fixtures\Contest;
use Folklore\Tests\Feature\Eloquent\Fixtures\Game;
use Folklore\Tests\Feature\Eloquent\Fixtures\Games;
use Folklore\Tests\Feature\Eloquent\Fixtures\Prize;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Bugs of JsonDataCast on 1.1 (#59). Each test asserts the current behaviour,
 * and says what the right one is: the fix flips the assertions marked "Bug".
 */
class JsonDataCastBugsTest extends JsonDataTestCase
{
    /**
     * Fixed: syncRelations() associated the parent, which only set the foreign
     * key, then the repository refreshed the model, which discarded it.
     */
    public function test_the_repository_saves_a_belongs_to_reference()
    {
        $pages = $this->app->make(Pages::class);
        $parent = $pages->create(['handle' => 'parent', 'title' => ['fr' => 'Parent']]);

        $child = $pages->create(['handle' => 'child', 'title' => ['fr' => 'Enfant'], 'parent' => $parent]);

        $model = PageModel::find($child->id());
        $this->assertSame('parent://'.$parent->id(), $this->rawData($model)['parent']);
        $this->assertEquals($parent->id(), $model->parent_id);
        $this->assertTrue($model->data['parent']->is($parent->getModel()));
    }

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
     * The sync still only dissociates in memory: a parent_id set through its
     * column, without a reference in the data, is kept.
     */
    public function test_a_parent_set_through_its_column_is_kept_without_a_reference()
    {
        $pages = $this->app->make(Pages::class);
        $parent = $pages->create(['handle' => 'parent', 'title' => ['fr' => 'Parent']]);

        $child = $pages->create(['handle' => 'child', 'title' => ['fr' => 'Enfant'], 'parent_id' => $parent->id()]);

        $this->assertEquals($parent->id(), PageModel::find($child->id())->parent_id);
    }

    /**
     * Fixed: the repository merged the update into the resolved data, where a
     * lazy relation that isn't loaded reads as null, and wrote it back.
     */
    public function test_a_partial_update_keeps_the_references_that_dont_resolve()
    {
        $prize = Prize::create(['handle' => 'grand-prize']);
        $games = $this->app->make(Games::class);
        $game = $games->create(['handle' => 'quiz', 'prizes' => [['prize' => $prize, 'quantity' => 1]]]);
        $this->assertSame('prizes://'.$prize->id, $this->rawData($game->getModel())['prizes'][0]['prize']);

        $games->update($game->id(), ['title' => ['fr' => 'Le quiz']]);

        $this->assertEquals(
            [['prize' => 'prizes://'.$prize->id, 'quantity' => 1]],
            $this->rawData($game->getModel())['prizes'],
        );
        $this->assertTrue(Game::with('prizes')->find($game->id())->data['prizes'][0]['prize']->is($prize));
    }

    public function test_a_partial_update_still_clears_a_reference_it_sets_to_null()
    {
        $prize = Prize::create(['handle' => 'grand-prize']);
        $games = $this->app->make(Games::class);
        $game = $games->create(['handle' => 'quiz', 'prizes' => [['prize' => $prize, 'quantity' => 1]]]);

        $games->update($game->id(), ['prizes' => [['prize' => null, 'quantity' => 2]]]);

        $this->assertEquals(
            [['prize' => null, 'quantity' => 2]],
            $this->rawData($game->getModel())['prizes'],
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

    public function test_a_before_set_macro_doesnt_receive_a_stored_reference()
    {
        $media = $this->makeMedia();
        $received = [];
        JsonDataCast::macro('medias:before_set', function ($item) use (&$received) {
            $received[] = $item;

            return $item;
        });

        $game = new Game;
        $game->data = ['image' => ['fr' => 'medias://'.$media->id, 'en' => $media]];
        $game->save();

        $this->assertCount(1, $received);
        $this->assertTrue($received[0]->is($media));
    }

    /**
     * Another string at a reference path is still taken as an id, and the
     * sync attaches it: SQLite stores it, MySQL refuses it. 2.0 checks the
     * references it writes (#56).
     */
    public function test_another_string_at_a_reference_path_is_still_taken_as_an_id()
    {
        $game = new Game;
        $game->data = ['image' => ['fr' => 'https://example.com/image.png', 'en' => 'blocks://1']];
        $game->save();

        $this->assertSame('medias://https://example.com/image.png', $this->rawData($game)['image']['fr']);
        $this->assertSame('medias://blocks://1', $this->rawData($game)['image']['en']);

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->expectException(QueryException::class);
        }
        JsonDataCast::syncRelations($game);

        $this->assertEqualsCanonicalizing(
            ['https://example.com/image.png', 'blocks://1'],
            DB::table($game->medias()->getTable())->pluck('media_id')->all(),
        );
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
