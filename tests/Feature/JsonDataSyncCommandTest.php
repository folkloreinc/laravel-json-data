<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\Console\JsonReferencesRewriteCommand;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\Tests\Feature\Fixtures\BrandGame;
use Folklore\JsonData\Tests\Feature\Fixtures\Game;
use Folklore\JsonData\Tests\Feature\Fixtures\Page;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Folklore\JsonData\Tests\Feature\Fixtures\RegistryGame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * `json-data:sync-references` syncs the relations of stored rows, as a save would, for rows
 * written without model events (docs/json-data.md, "Syncing stored rows", #5).
 */
class JsonDataSyncCommandTest extends JsonDataTestCase
{
    private const HEADERS = ['Model', 'Rows', 'Rows changed', 'Attached', 'Detached', 'Synced by callbacks, not compared', 'Unresolved references'];

    private const DRY_RUN_HEADERS = ['Model', 'Rows', 'Rows to change', 'To attach', 'To detach', 'Synced by callbacks, not compared', 'Unresolved references'];

    public function test_the_command_syncs_the_relations_of_a_row_inserted_without_the_cast()
    {
        $media = $this->makeMedia();
        $block = $this->makeBlock();
        $prize = Prize::create(['handle' => 'first']);
        $game = $this->insertGame([
            'image' => ['fr' => 'medias://'.$media->id],
            'blocks' => ['blocks://'.$block->id],
            'prizes' => [['prize' => 'prizes://'.$prize->id]],
        ]);

        $this->artisan('json-data:sync-references', ['--model' => [Game::class]])
            ->expectsTable(self::HEADERS, [[Game::class, 1, 1, 3, 0, 0, 0]])
            ->assertSuccessful();

        $this->assertSame([$media->id], $game->medias()->get()->modelKeys());
        $this->assertSame([$block->id], $game->blocks()->pluck('blocks.id')->all());
        $this->assertSame([$prize->id], $game->prizes()->pluck('prizes.id')->all());
    }

    public function test_a_second_run_changes_nothing()
    {
        $media = $this->makeMedia();
        $block = $this->makeBlock();
        $this->insertGame(['image' => ['fr' => 'medias://'.$media->id], 'blocks' => ['blocks://'.$block->id]]);
        $this->artisan('json-data:sync-references', ['--model' => [Game::class]])->run();

        $writes = $this->writesDuring(function () {
            $this->artisan('json-data:sync-references', ['--model' => [Game::class]])
                ->expectsTable(self::HEADERS, [[Game::class, 1, 0, 0, 0, 0, 0]])
                ->assertSuccessful()
                ->run();
        });

        $this->assertSame([], $writes);
    }

    public function test_the_dry_run_reports_the_changes_and_writes_nothing()
    {
        $media = $this->makeMedia();
        $block = $this->makeBlock();
        $game = $this->insertGame(['image' => ['fr' => 'medias://'.$media->id], 'blocks' => ['blocks://'.$block->id]]);

        $writes = $this->writesDuring(function () use ($media, $block, $game) {
            $this->artisan('json-data:sync-references', ['--model' => [Game::class], '--dry-run' => true, '--verbose' => true])
                ->expectsOutputToContain(Game::class.' #'.$game->id.' medias: attach '.$media->id)
                ->expectsOutputToContain(Game::class.' #'.$game->id.' blocks: attach '.$block->id)
                ->expectsTable(self::DRY_RUN_HEADERS, [[Game::class, 1, 1, 2, 0, 0, 0]])
                ->expectsOutputToContain('Dry run: nothing was written.')
                ->assertSuccessful()
                ->run();
        });

        $this->assertSame([], $writes);
        $this->assertSame(0, $game->medias()->count());
        $this->assertSame(0, $game->blocks()->count());
    }

    public function test_the_command_detaches_what_the_json_no_longer_references()
    {
        $kept = $this->makeBlock();
        $removed = $this->makeBlock();
        $game = new Game;
        $game->data = ['blocks' => [$kept, $removed]];
        $game->save();
        DB::table('games')->where('id', $game->id)->update(['data' => json_encode(['blocks' => ['blocks://'.$kept->id]])]);

        $this->artisan('json-data:sync-references', ['--model' => [Game::class]])
            ->expectsTable(self::HEADERS, [[Game::class, 1, 1, 0, 1, 0, 0]])
            ->assertSuccessful();

        $this->assertSame([$kept->id], $game->blocks()->pluck('blocks.id')->all());
    }

    public function test_the_command_saves_the_foreign_key_of_a_belongs_to()
    {
        $parent = Page::create(['handle' => 'parent']);
        $id = DB::table('pages')->insertGetId(['handle' => 'child', 'data' => json_encode(['parent' => 'parent://'.$parent->id])]);

        $this->artisan('json-data:sync-references', ['--model' => [Page::class]])
            ->expectsTable(self::HEADERS, [[Page::class, 2, 1, 1, 0, 0, 0]])
            ->assertSuccessful();
        $this->assertEquals($parent->id, DB::table('pages')->where('id', $id)->value('parent_id'));

        $this->artisan('json-data:sync-references', ['--model' => [Page::class]])
            ->expectsTable(self::HEADERS, [[Page::class, 2, 0, 0, 0, 0, 0]])
            ->assertSuccessful();
    }

    /**
     * As laravel-folklore syncs the media into laravel-mediatheque's usage graph, under the
     * `json` handle: a callback of JsonData::syncUsing() that says what it has synced.
     */
    public function test_a_sync_callback_with_a_reader_is_compared_and_called_only_when_it_changes()
    {
        $media = $this->makeMedia();
        $block = $this->makeBlock();
        $game = $this->insertGame(['image' => ['fr' => 'medias://'.$media->id], 'blocks' => ['blocks://'.$block->id]]);
        /** @var array<int|string, list<mixed>> $usages */
        $usages = [];
        $calls = 0;
        JsonData::syncUsing(
            function (Model $model, string $relationName, $relation, array $ids) use (&$usages, &$calls) {
                if ($relationName !== 'medias') {
                    return false;
                }
                $calls++;
                $usages[$model->getKey()] = $ids;

                return true;
            },
            function (Model $model, string $relationName) use (&$usages) {
                return $relationName === 'medias' ? ($usages[$model->getKey()] ?? []) : null;
            },
        );

        $this->artisan('json-data:sync-references', ['--model' => [Game::class], '--dry-run' => true])
            ->expectsTable(self::DRY_RUN_HEADERS, [[Game::class, 1, 1, 2, 0, 0, 0]])
            ->assertSuccessful();
        $this->assertSame(0, $calls);

        $this->artisan('json-data:sync-references', ['--model' => [Game::class]])
            ->expectsTable(self::HEADERS, [[Game::class, 1, 1, 2, 0, 0, 0]])
            ->assertSuccessful();
        $this->assertSame(1, $calls);
        $this->assertEquals([$media->id], $usages[$game->id]);
        $this->assertSame(0, $game->medias()->count());
        $this->assertSame([$block->id], $game->blocks()->pluck('blocks.id')->all());

        $this->artisan('json-data:sync-references', ['--model' => [Game::class]])
            ->expectsTable(self::HEADERS, [[Game::class, 1, 0, 0, 0, 0, 0]])
            ->assertSuccessful();
        $this->assertSame(1, $calls);
    }

    public function test_a_sync_callback_without_a_reader_is_called_on_every_run()
    {
        $media = $this->makeMedia();
        $this->insertGame(['image' => ['fr' => 'medias://'.$media->id]]);
        $calls = 0;
        JsonData::syncUsing(function (Model $model, string $relationName) use (&$calls) {
            $calls++;

            return true;
        });

        $this->artisan('json-data:sync-references', ['--model' => [Game::class], '--dry-run' => true])
            ->expectsTable(self::DRY_RUN_HEADERS, [[Game::class, 1, 0, 0, 0, 1, 0]])
            ->assertSuccessful();
        $this->assertSame(0, $calls);

        $this->artisan('json-data:sync-references', ['--model' => [Game::class]])->run();
        $this->artisan('json-data:sync-references', ['--model' => [Game::class]])
            ->expectsTable(self::HEADERS, [[Game::class, 1, 0, 0, 0, 1, 0]])
            ->assertSuccessful();

        // `medias`, `blocks` and `prizes` are declared: the callback may sync any of them
        $this->assertSame(6, $calls);
    }

    public function test_the_command_reports_the_references_that_dont_resolve()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $prize = Prize::create(['handle' => 'first']);
        $id = DB::table('games')->insertGetId(['data' => json_encode([
            'image' => ['fr' => 'medias://999'],
            'prizes' => [['prize' => 'prize://first'], ['prize' => 'prize://missing']],
        ])]);

        $this->artisan('json-data:sync-references', ['--model' => [RegistryGame::class], '--verbose' => true])
            ->expectsOutputToContain(RegistryGame::class.' #'.$id.' data.image.fr: medias://999 doesn\'t resolve')
            ->expectsOutputToContain(RegistryGame::class.' #'.$id.' data.prizes.1.prize: prize://missing doesn\'t resolve')
            ->expectsTable(self::HEADERS, [[RegistryGame::class, 1, 1, 1, 0, 0, 2]])
            ->assertSuccessful();

        // A reference to a row that doesn't exist is reported, and not synced
        $game = RegistryGame::findOrFail($id);
        $this->assertSame([$prize->id], $game->prizes()->pluck('prizes.id')->all());
        $this->assertSame(0, DB::table('mediables')->where('mediable_id', $game->id)->count());

        $this->artisan('json-data:sync-references', ['--model' => [RegistryGame::class]])
            ->expectsTable(self::HEADERS, [[RegistryGame::class, 1, 0, 0, 0, 0, 2]])
            ->assertSuccessful();
    }

    public function test_the_command_syncs_the_models_added_with_discover_using()
    {
        $block = $this->makeBlock();
        $game = $this->insertGame(['blocks' => ['blocks://'.$block->id]]);
        JsonReferencesRewriteCommand::discoverUsing(fn () => [Game::class]);

        $this->artisan('json-data:sync-references')->assertSuccessful();

        $this->assertSame([$block->id], $game->blocks()->pluck('blocks.id')->all());
    }

    public function test_the_command_skips_a_class_that_isnt_a_json_data_model()
    {
        $this->assertArrayHasKey('json-data:sync-references', Artisan::all());

        $this->artisan('json-data:sync-references', ['--model' => [self::class, BrandGame::class]])
            ->expectsOutputToContain('['.self::class.'] is not a model with a JSON attribute cast with JsonDataCast: skipped.')
            ->assertSuccessful();
    }

    public function test_the_command_reads_the_rows_in_chunks()
    {
        $blocks = [$this->makeBlock(), $this->makeBlock(), $this->makeBlock()];
        foreach ($blocks as $block) {
            $this->insertGame(['blocks' => ['blocks://'.$block->id]]);
        }

        $this->artisan('json-data:sync-references', ['--model' => [Game::class], '--chunk' => 2])
            ->expectsTable(self::HEADERS, [[Game::class, 3, 3, 3, 0, 0, 0]])
            ->assertSuccessful();

        $this->assertSame(3, DB::table('blocks_pivot')->count());
    }

    /**
     * A game written with SQL, without the cast nor model events.
     *
     * @param  array<string, mixed>  $data
     */
    private function insertGame(array $data): Game
    {
        $id = DB::table('games')->insertGetId(['handle' => 'quiz', 'data' => json_encode($data)]);

        return Game::findOrFail($id);
    }

    /**
     * The queries that write, during a callback.
     *
     * @return list<string>
     */
    private function writesDuring(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return collect(DB::getQueryLog())
            ->map(fn (array $query) => (string) $query['query'])
            ->filter(fn (string $query) => preg_match('/^\s*(insert|update|delete)\b/i', $query) === 1)
            ->values()
            ->all();
    }
}
