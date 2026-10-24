<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\Console\JsonReferencesRewriteCommand;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\JsonReference;
use Folklore\JsonData\JsonReferenceEntry;
use Folklore\JsonData\Tests\Feature\Fixtures\BrandGame;
use Folklore\JsonData\Tests\Feature\Fixtures\Game;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Folklore\JsonData\Tests\Feature\Fixtures\PrizesExceptSecret;
use Folklore\JsonData\Tests\Feature\Fixtures\RegistryGame;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch resolution, the reference listing, several JSON attributes per model, and the
 * rewrite command (docs/json-data.md). The repositories' JSON attributes and
 * `withJsonReferences()` are tested in laravel-folklore.
 */
class JsonDataBatchesTest extends JsonDataTestCase
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

    public function test_the_rewrite_command_rewrites_one_key_to_another()
    {
        JsonData::map(['prize' => [Prize::class, 'handle'], 'prizeid' => Prize::class]);
        $first = $this->prize('first');
        $second = $this->prize('second');
        $game = $this->storedGame(
            ['prizes' => [['prize' => 'prizeid://'.$first->id], ['prize' => 'prizeid://999']], 'title' => 'prizeid://'.$first->id],
            ['logo' => 'prizeid://'.$second->id],
        );

        $this->artisan('json-data:rewrite-references', ['--from' => 'prizeid', '--to' => 'prize', '--model' => [BrandGame::class], '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
        $this->assertSame('prizeid://'.$first->id, json_decode((string) DB::table('games')->where('id', $game->id)->value('data'), true)['prizes'][0]['prize']);

        $this->artisan('json-data:rewrite-references', ['--from' => 'prizeid', '--to' => 'prize', '--model' => [BrandGame::class]])
            ->assertSuccessful();

        $data = json_decode((string) DB::table('games')->where('id', $game->id)->value('data'), true);
        $images = json_decode((string) DB::table('games')->where('id', $game->id)->value('images'), true);
        $this->assertSame('prize://first', $data['prizes'][0]['prize']);
        $this->assertSame('prizeid://999', $data['prizes'][1]['prize']);
        $this->assertSame('prizeid://'.$first->id, $data['title']);
        $this->assertSame('prize://second', $images['logo']);
    }

    public function test_the_rewrite_command_rewrites_the_models_added_with_discover_using()
    {
        JsonData::map(['prize' => [Prize::class, 'handle'], 'prizeid' => Prize::class]);
        $prize = $this->prize('first');
        $game = $this->storedGame(['prizes' => [['prize' => 'prizeid://'.$prize->id]]]);
        JsonReferencesRewriteCommand::discoverUsing(fn () => [BrandGame::class]);

        $this->artisan('json-data:rewrite-references', ['--from' => 'prizeid', '--to' => 'prize'])
            ->assertSuccessful();

        $data = json_decode((string) DB::table('games')->where('id', $game->id)->value('data'), true);
        $this->assertSame('prize://first', $data['prizes'][0]['prize']);
    }

    public function test_the_rewrite_command_keeps_its_laravel_folklore_name_as_a_deprecated_alias()
    {
        JsonData::map(['prize' => [Prize::class, 'handle'], 'prizeid' => Prize::class]);
        $prize = $this->prize('first');
        $game = $this->storedGame(['prizes' => [['prize' => 'prizeid://'.$prize->id]]]);

        $this->artisan('folklore:json-references:rewrite', ['--from' => 'prizeid', '--to' => 'prize', '--model' => [BrandGame::class]])
            ->expectsOutputToContain('folklore:json-references:rewrite is deprecated: use json-data:rewrite-references.')
            ->assertSuccessful();

        $data = json_decode((string) DB::table('games')->where('id', $game->id)->value('data'), true);
        $this->assertSame('prize://first', $data['prizes'][0]['prize']);
    }

    public function test_the_rewrite_command_does_not_warn_under_its_name()
    {
        JsonData::map(['prize' => [Prize::class, 'handle'], 'prizeid' => Prize::class]);

        $this->artisan('json-data:rewrite-references', ['--from' => 'prizeid', '--to' => 'prize', '--model' => [BrandGame::class]])
            ->doesntExpectOutputToContain('deprecated')
            ->assertSuccessful();
    }

    public function test_the_rewrite_command_needs_two_keys_of_the_same_model()
    {
        JsonData::map(['prize' => [Prize::class, 'handle'], 'game' => Game::class]);

        $this->artisan('json-data:rewrite-references', ['--from' => 'prize', '--to' => 'game'])
            ->expectsOutputToContain('not mapped to the same model')
            ->assertFailed();
        $this->artisan('json-data:rewrite-references', ['--from' => 'prize', '--to' => 'missing'])
            ->expectsOutputToContain('must be mapped')
            ->assertFailed();
        $this->artisan('json-data:rewrite-references', ['--from' => 'prize'])
            ->assertFailed();
    }
}
