<?php

namespace Folklore\Tests\Feature\Eloquent;

use Folklore\Eloquent\JsonData;
use Folklore\Eloquent\JsonDataCast;
use Folklore\Eloquent\JsonReference;
use Folklore\Eloquent\JsonReferenceEntry;
use Folklore\Tests\Feature\Eloquent\Fixtures\BrandGame;
use Folklore\Tests\Feature\Eloquent\Fixtures\Game;
use Folklore\Tests\Feature\Eloquent\Fixtures\Prize;
use Folklore\Tests\Feature\Eloquent\Fixtures\PrizesExceptSecret;
use Folklore\Tests\Feature\Eloquent\Fixtures\RegistryGame;
use Folklore\Tests\Feature\Eloquent\Fixtures\RegistryGames;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch resolution, the reference listing, several JSON attributes per repository, and the
 * rewrite command (docs/json-data.md).
 */
class JsonDataBatchesTest extends JsonDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('games', function (Blueprint $table) {
            $table->json('images')->nullable();
        });
    }

    protected function tearDown(): void
    {
        RegistryGame::$prizeOptions = [];

        parent::tearDown();
    }

    private function prize(string $handle): Prize
    {
        return Prize::create(['handle' => $handle]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $images
     */
    private function storedGame(array $data, ?array $images = null): BrandGame
    {
        $game = BrandGame::create(['handle' => 'quiz']);
        DB::table('games')->where('id', $game->id)->update([
            'data' => json_encode($data),
            'images' => is_null($images) ? null : json_encode($images),
        ]);

        $fresh = BrandGame::find($game->id);
        $this->assertInstanceOf(BrandGame::class, $fresh);

        return $fresh;
    }

    /**
     * @return list<string>
     */
    private function queriesOf(callable $callback): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $callback();
        DB::flushQueryLog();

        return $queries;
    }

    public function test_load_resolves_the_references_of_a_collection_in_one_query_per_key()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        foreach (['a', 'b', 'c'] as $handle) {
            $this->prize($handle);
        }
        $games = collect([
            $this->storedGame(['prizes' => [['prize' => 'prize://a'], ['prize' => 'prize://b']]]),
            $this->storedGame(['prizes' => [['prize' => 'prize://c']]]),
            $this->storedGame(['prizes' => [['prize' => 'prize://a']]]),
        ]);

        $loading = $this->queriesOf(fn () => JsonData::load($games));
        $reading = $this->queriesOf(function () use ($games) {
            foreach ($games as $game) {
                $game->data;
            }
        });

        $this->assertCount(1, array_filter($loading, fn (string $sql) => str_contains($sql, 'prizes')));
        $this->assertSame([], $reading);
        $this->assertSame('c', $games[1]->data['prizes'][0]['prize']->handle);
        $this->assertSame('a', $games[2]->data['prizes'][0]['prize']->handle);
    }

    public function test_load_eager_loads_the_relations_of_unmapped_keys()
    {
        $block = $this->makeBlock();
        $games = collect([
            Game::create(['handle' => 'first']),
            Game::create(['handle' => 'second']),
        ]);
        foreach ($games as $game) {
            $game->data = ['blocks' => [$block]];
            $game->save();
            JsonDataCast::syncRelations($game);
        }
        $games = Game::query()->whereKey($games->map->getKey()->all())->get();

        JsonData::load($games);
        $reading = $this->queriesOf(function () use ($games) {
            foreach ($games as $game) {
                $game->data;
            }
        });

        $this->assertSame([], $reading);
        $this->assertTrue($games[0]->data['blocks'][0]->is($block));
    }

    public function test_load_resolves_a_lazy_relation()
    {
        $prize = $this->prize('grand-prize');
        $game = new Game;
        $game->data = ['prizes' => [['prize' => $prize]]];
        $game->save();
        JsonDataCast::syncRelations($game);

        $stored = Game::find($game->id);
        $this->assertNull($stored?->data['prizes'][0]['prize']);

        JsonData::load([$stored]);

        $this->assertTrue($stored?->data['prizes'][0]['prize']->is($prize));
    }

    public function test_load_checks_each_owner_and_can_be_limited_to_some_attributes()
    {
        JsonData::map(['prize' => JsonReference::to(Prize::class)->by('handle')->using(PrizesExceptSecret::class)]);
        $this->prize('secret');
        $this->prize('public');
        $game = $this->storedGame(['prizes' => [['prize' => 'prize://secret']]], ['logo' => 'prize://public']);

        JsonData::load([$game], ['images']);
        $queries = $this->queriesOf(fn () => $game->images);

        $this->assertSame([], $queries);
        $this->assertSame('public', $game->images['logo']->handle);
        $this->assertNull($game->data['prizes'][0]['prize']);
    }

    public function test_the_listing_gives_the_stored_references_with_where_they_are()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $game = $this->storedGame(
            ['prizes' => [['prize' => 'prize://missing'], ['prize' => 'https://example.com']], 'body' => ['ref' => 'prize://deep', 'text' => 'prizes://1']],
            ['logo' => 'prize://logo', 'gallery' => ['prizes://12']],
        );

        $entries = JsonData::references($game)
            ->map(fn (JsonReferenceEntry $entry) => [(string) $entry, $entry->attribute, $entry->path, $entry->relation, $entry->isMapped()])
            ->all();

        $this->assertEqualsCanonicalizing([
            ['prize://missing', 'data', 'prizes.0.prize', 'prizes', true],
            ['prize://deep', 'data', 'body.ref', 'prizes', true],
            ['prize://logo', 'images', 'logo', 'prizes', true],
            ['prizes://12', 'images', 'gallery.0', 'prizes', false],
        ], $entries);
        $this->assertCount(1, JsonData::references($game, ['data'])->filter(fn (JsonReferenceEntry $entry) => $entry->path === 'body.ref'));
        $this->assertCount(0, JsonData::references($game, ['other']));
    }

    public function test_with_json_references_returns_a_copy_that_loads_its_results()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $this->prize('a');
        $this->prize('b');
        $this->storedGame(['prizes' => [['prize' => 'prize://a']]]);
        $this->storedGame(['prizes' => [['prize' => 'prize://b']]]);

        $games = new RegistryGames;
        $loading = $games->withJsonReferences();
        $results = $loading->get();
        $reading = $this->queriesOf(function () use ($results) {
            foreach ($results as $game) {
                $game->getModel()->data;
            }
        });

        $this->assertFalse($games->loadsJsonReferences());
        $this->assertTrue($loading->loadsJsonReferences());
        $this->assertSame([], $reading);
        $this->assertCount(2, $results);
    }

    public function test_a_repository_merges_an_update_into_its_other_json_attributes()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $logo = $this->prize('logo');
        $favicon = $this->prize('favicon');
        $game = $this->storedGame(['title' => ['fr' => 'Le quiz']], ['logo' => 'prize://logo', 'favicon' => 'prize://gone', 'alt' => 'Logo']);
        $logo->delete();

        $updated = (new RegistryGames)->update((string) $game->id, ['images' => ['logo' => $favicon], 'title' => ['en' => 'The quiz']]);

        $stored = json_decode((string) DB::table('games')->where('id', $game->id)->value('images'), true);
        // MySQL's JSON type orders the keys of an object
        $this->assertEquals(['logo' => 'prize://favicon', 'favicon' => 'prize://gone', 'alt' => 'Logo'], $stored);
        $this->assertNotNull($updated);
        // The first JSON attribute keeps its 1.x merge: each key given replaces its value
        $data = json_decode((string) DB::table('games')->where('id', $game->id)->value('data'), true);
        $this->assertSame(['title' => ['en' => 'The quiz']], $data);
    }

    public function test_the_rewrite_command_rewrites_one_key_to_another()
    {
        JsonData::map(['prize' => [Prize::class, 'handle'], 'prizeid' => Prize::class]);
        $first = $this->prize('first');
        $second = $this->prize('second');
        $game = $this->storedGame(
            ['prizes' => [['prize' => 'prizeid://'.$first->id], ['prize' => 'prizeid://999']], 'title' => 'prizeid://'.$first->id],
            ['logo' => 'prizeid://'.$second->id],
        );

        $this->artisan('folklore:json-references:rewrite', ['--from' => 'prizeid', '--to' => 'prize', '--model' => [BrandGame::class], '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
        $this->assertSame('prizeid://'.$first->id, json_decode((string) DB::table('games')->where('id', $game->id)->value('data'), true)['prizes'][0]['prize']);

        $this->artisan('folklore:json-references:rewrite', ['--from' => 'prizeid', '--to' => 'prize', '--model' => [BrandGame::class]])
            ->assertSuccessful();

        $data = json_decode((string) DB::table('games')->where('id', $game->id)->value('data'), true);
        $images = json_decode((string) DB::table('games')->where('id', $game->id)->value('images'), true);
        $this->assertSame('prize://first', $data['prizes'][0]['prize']);
        $this->assertSame('prizeid://999', $data['prizes'][1]['prize']);
        $this->assertSame('prizeid://'.$first->id, $data['title']);
        $this->assertSame('prize://second', $images['logo']);
    }

    public function test_the_rewrite_command_needs_two_keys_of_the_same_model()
    {
        JsonData::map(['prize' => [Prize::class, 'handle'], 'game' => Game::class]);

        $this->artisan('folklore:json-references:rewrite', ['--from' => 'prize', '--to' => 'game'])
            ->expectsOutputToContain('not mapped to the same model')
            ->assertFailed();
        $this->artisan('folklore:json-references:rewrite', ['--from' => 'prize', '--to' => 'missing'])
            ->expectsOutputToContain('must be mapped')
            ->assertFailed();
        $this->artisan('folklore:json-references:rewrite', ['--from' => 'prize'])
            ->assertFailed();
    }
}
