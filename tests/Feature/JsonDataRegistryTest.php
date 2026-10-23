<?php

namespace Folklore\Tests\Feature\Eloquent;

use Folklore\Eloquent\Exceptions\InvalidJsonReference;
use Folklore\Eloquent\Exceptions\ReferenceNotAllowed;
use Folklore\Eloquent\JsonData;
use Folklore\Eloquent\JsonDataCast;
use Folklore\Eloquent\JsonReference;
use Folklore\Models\Block;
use Folklore\Tests\Feature\Eloquent\Fixtures\Prize;
use Folklore\Tests\Feature\Eloquent\Fixtures\PrizesExceptSecret;
use Folklore\Tests\Feature\Eloquent\Fixtures\RegistryGame;
use Illuminate\Support\Facades\DB;

/**
 * JsonDataCast on the registry of keys (docs/json-data.md, docs/decisions/0005 to 0007,
 * 0013 and 0016).
 */
class JsonDataRegistryTest extends JsonDataTestCase
{
    protected function tearDown(): void
    {
        JsonData::flush();
        RegistryGame::$prizeOptions = [];

        parent::tearDown();
    }

    private function prize(string $handle): Prize
    {
        return Prize::create(['handle' => $handle]);
    }

    public function test_a_relation_writes_the_first_key_mapped_to_its_model()
    {
        JsonData::map(['prize' => [Prize::class, 'handle'], 'prizeid' => Prize::class]);
        $prize = $this->prize('grand-prize');

        $game = new RegistryGame;
        $game->data = ['prizes' => [['prize' => $prize]]];
        $game->save();

        $this->assertSame('prize://grand-prize', $this->rawData($game)['prizes'][0]['prize']);
        $this->assertTrue($game->fresh()?->data['prizes'][0]['prize']->is($prize));
    }

    public function test_a_relation_can_choose_its_key()
    {
        JsonData::map(['prize' => [Prize::class, 'handle'], 'prizeid' => Prize::class]);
        RegistryGame::$prizeOptions = ['key' => 'prizeid'];
        $prize = $this->prize('grand-prize');

        $game = new RegistryGame;
        $game->data = ['prizes' => [['prize' => $prize]]];
        $game->save();

        $this->assertSame('prizeid://'.$prize->id, $this->rawData($game)['prizes'][0]['prize']);
    }

    public function test_a_key_of_another_model_cant_be_chosen()
    {
        JsonData::map(['block' => Block::class]);
        RegistryGame::$prizeOptions = ['key' => 'block'];

        $game = new RegistryGame;
        $game->data = ['prizes' => [['prize' => $this->prize('grand-prize')]]];

        $this->expectException(InvalidJsonReference::class);

        $game->save();
    }

    public function test_every_mapped_key_of_the_related_model_is_read()
    {
        JsonData::map(['prize' => [Prize::class, 'handle'], 'prizeid' => Prize::class]);
        $first = $this->prize('first');
        $second = $this->prize('second');
        $game = $this->storedGame(['prizes' => [['prize' => 'prize://first'], ['prize' => 'prizeid://'.$second->id]]]);

        $this->assertTrue($game->data['prizes'][0]['prize']->is($first));
        $this->assertTrue($game->data['prizes'][1]['prize']->is($second));
    }

    public function test_a_mapped_key_resolves_by_its_column_without_being_attached()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $prize = $this->prize('grand-prize');
        $game = $this->storedGame(['prizes' => [['prize' => 'prize://grand-prize'], ['prize' => 'prize://missing']]]);

        $this->assertTrue($game->data['prizes'][0]['prize']->is($prize));
        $this->assertNull($game->data['prizes'][1]['prize']);
    }

    public function test_a_mapped_key_of_another_model_is_an_error_on_read()
    {
        JsonData::map(['block' => Block::class]);
        $game = $this->storedGame(['prizes' => [['prize' => 'block://1']]]);

        $this->expectException(InvalidJsonReference::class);

        $game->data;
    }

    public function test_the_references_of_a_key_resolve_in_one_query_and_are_memoized()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        foreach (['a', 'b', 'c'] as $handle) {
            $this->prize($handle);
        }
        $game = $this->storedGame(['prizes' => [['prize' => 'prize://a'], ['prize' => 'prize://b'], ['prize' => 'prize://c']]]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $first = $game->data;
        $second = $game->data;

        $this->assertCount(1, array_filter($queries, fn (string $sql) => str_contains($sql, 'prizes')));
        $this->assertSame('a', $first['prizes'][0]['prize']->handle);
        $this->assertSame('c', $second['prizes'][2]['prize']->handle);
    }

    public function test_the_memoized_models_follow_the_raw_value()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $this->prize('a');
        $this->prize('b');
        $game = $this->storedGame(['prizes' => [['prize' => 'prize://a']]]);
        $this->assertSame('a', $game->data['prizes'][0]['prize']->handle);

        $game->data = ['prizes' => [['prize' => 'prize://b']]];

        $this->assertSame('b', $game->data['prizes'][0]['prize']->handle);
    }

    public function test_a_value_is_prepared_when_the_model_is_saved()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $prize = $this->prize('grand-prize');
        $game = $this->storedGame(['prizes' => [['prize' => 'prize://grand-prize']]]);
        $other = $this->prize('other');

        $game->data = ['prizes' => [['prize' => $other]]];

        $this->assertSame($other, $game->data['prizes'][0]['prize']);
        $this->assertSame('prize://grand-prize', json_decode($game->getAttributes()['data'], true)['prizes'][0]['prize']);

        $game->save();

        $this->assertSame('prize://other', $this->rawData($game)['prizes'][0]['prize']);
        $this->assertTrue($prize->exists);
    }

    public function test_a_model_saved_without_events_keeps_its_pending_values()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $game = $this->storedGame(['prizes' => [['prize' => 'prize://first']]]);
        $this->prize('first');
        $second = $this->prize('second');

        $game->data = ['prizes' => [['prize' => $second]]];
        $game->saveQuietly();

        $this->assertSame('prize://first', $this->rawData($game)['prizes'][0]['prize']);

        $game->save();

        $this->assertSame('prize://second', $this->rawData($game)['prizes'][0]['prize']);
    }

    public function test_an_identifier_is_prepared_by_the_key_s_column()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $this->prize('grand-prize');

        $game = new RegistryGame;
        $game->data = ['prizes' => [['prize' => 'grand-prize'], ['prize' => 'missing']]];
        $game->save();

        $this->assertSame('prize://grand-prize', $this->rawData($game)['prizes'][0]['prize']);
        $this->assertNull($this->rawData($game)['prizes'][1]['prize']);
    }

    public function test_a_handler_refusal_is_an_error_on_write_and_null_on_read()
    {
        JsonData::map(['prize' => JsonReference::to(Prize::class)->by('handle')->using(PrizesExceptSecret::class)]);
        $this->prize('secret');
        $this->prize('public');

        $read = $this->storedGame(['prizes' => [['prize' => 'prize://secret'], ['prize' => 'prize://public']]]);
        $this->assertNull($read->data['prizes'][0]['prize']);
        $this->assertSame('public', $read->data['prizes'][1]['prize']->handle);

        $game = new RegistryGame;
        $game->data = ['prizes' => [['prize' => 'secret']]];

        $this->expectException(ReferenceNotAllowed::class);

        $game->save();
    }

    public function test_a_path_can_use_another_handler()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        RegistryGame::$prizeOptions = ['using' => PrizesExceptSecret::class];
        $this->prize('secret');

        $game = $this->storedGame(['prizes' => [['prize' => 'prize://secret']]]);

        $this->assertNull($game->data['prizes'][0]['prize']);
    }

    public function test_under_enforce_map_an_unmapped_key_is_not_a_reference()
    {
        JsonData::enforceMap(['prize' => [Prize::class, 'handle']]);
        $game = $this->storedGame(['prizes' => [['prize' => 'prizes://1']], 'image' => ['fr' => 'medias://1']]);

        $this->assertSame('prizes://1', $game->data['prizes'][0]['prize']);
        $this->assertSame('medias://1', $game->data['image']['fr']);
    }

    public function test_under_enforce_map_a_model_without_a_key_cant_be_written()
    {
        JsonData::enforceMap(['prize' => [Prize::class, 'handle']]);

        $game = new RegistryGame;
        $game->data = ['image' => ['fr' => $this->makeMedia()]];

        $this->expectException(InvalidJsonReference::class);

        $game->save();
    }

    public function test_under_a_recursive_path_only_mapped_references_are_read()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $prize = $this->prize('grand-prize');
        $game = $this->storedGame(['body' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'prize', 'attrs' => ['ref' => 'prize://grand-prize', 'href' => 'https://example.com']],
                ['type' => 'text', 'text' => 'prizes://1'],
            ],
        ]]);

        $body = $game->data['body'];

        $this->assertTrue($body['content'][0]['attrs']['ref']->is($prize));
        $this->assertSame('https://example.com', $body['content'][0]['attrs']['href']);
        $this->assertSame('prizes://1', $body['content'][1]['text']);
        $this->assertSame('doc', $body['type']);
    }

    public function test_under_a_recursive_path_models_are_written_and_the_rest_is_left()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $prize = $this->prize('grand-prize');

        $game = new RegistryGame;
        $game->data = ['body' => ['content' => [['attrs' => ['ref' => $prize, 'id' => 12, 'title' => 'grand-prize']]]]];
        $game->save();

        // MySQL's JSON type orders the keys of an object
        $this->assertEquals(
            ['ref' => 'prize://grand-prize', 'id' => 12, 'title' => 'grand-prize'],
            $this->rawData($game)['body']['content'][0]['attrs'],
        );
    }

    public function test_the_sync_counts_the_mapped_keys_of_the_related_model()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $first = $this->prize('first');
        $second = $this->prize('second');

        $game = new RegistryGame;
        $game->data = ['prizes' => [['prize' => $first], ['prize' => 'prizes://'.$second->id]], 'body' => ['ref' => 'prize://second']];
        $game->save();
        JsonDataCast::syncRelations($game);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $game->prizes()->pluck('prizes.id')->all());
    }

    /**
     * A game whose data is stored as given, without the cast.
     *
     * @param  array<string, mixed>  $data
     */
    private function storedGame(array $data): RegistryGame
    {
        $game = RegistryGame::create(['handle' => 'quiz']);
        DB::table('games')->where('id', $game->id)->update(['data' => json_encode($data)]);

        $fresh = RegistryGame::find($game->id);
        $this->assertInstanceOf(RegistryGame::class, $fresh);

        return $fresh;
    }
}
