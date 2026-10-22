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
     * The repository merges the update into the resolved data, where a lazy
     * relation that isn't loaded reads as null, and writes it back. The
     * reference should be kept.
     */
    public function test_bug_a_partial_update_rewrites_unresolved_references_as_null()
    {
        $prize = Prize::create(['handle' => 'grand-prize']);
        $games = $this->app->make(Games::class);
        $game = $games->create(['handle' => 'quiz', 'prizes' => [['prize' => $prize, 'quantity' => 1]]]);
        $this->assertSame('prizes://'.$prize->id, $this->rawData($game->getModel())['prizes'][0]['prize']);

        $games->update($game->id(), ['title' => ['fr' => 'Le quiz']]);

        // Bug: should still be prizes://{id}
        $this->assertEquals(
            [['prize' => null, 'quantity' => 1]],
            $this->rawData($game->getModel())['prizes'],
        );
    }

    /**
     * to_id() returns strings as they are, so a reference read raw and written
     * back gets a second prefix and no longer resolves. The sync then attaches
     * `medias://{id}` as an id: SQLite stores it, MySQL refuses it. The
     * reference should be kept as is.
     */
    public function test_bug_writing_a_stored_reference_doubles_its_prefix()
    {
        $media = $this->makeMedia();

        $game = new Game;
        $game->data = ['image' => ['fr' => 'medias://'.$media->id, 'en' => 'https://example.com/image.png']];
        $game->save();

        // Bug: should be medias://{id}
        $this->assertSame('medias://medias://'.$media->id, $this->rawData($game)['image']['fr']);
        $this->assertSame('medias://https://example.com/image.png', $this->rawData($game)['image']['en']);

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->expectException(QueryException::class);
        }
        JsonDataCast::syncRelations($game);

        $this->assertEqualsCanonicalizing(
            ['medias://'.$media->id, 'https://example.com/image.png'],
            DB::table($game->medias()->getTable())->pluck('media_id')->all(),
        );
        $this->assertNull(Game::find($game->id)->data['image']['fr']);
    }

    /**
     * The sync of a HasMany sets the foreign key of every referenced row, so a
     * row of another parent moves. It should only resolve rows of this parent.
     */
    public function test_bug_sync_of_a_has_many_moves_rows_from_another_parent()
    {
        $first = Contest::create();
        $second = Contest::create();
        $prize = Prize::create(['contest_id' => $first->id, 'handle' => 'grand-prize']);

        $second->data = ['featured' => [$prize]];
        $second->save();
        JsonDataCast::syncRelations($second);

        // Bug: should still belong to the first contest
        $this->assertSame($second->id, $prize->fresh()->contest_id);
        $this->assertSame(0, $first->prizes()->count());
    }
}
