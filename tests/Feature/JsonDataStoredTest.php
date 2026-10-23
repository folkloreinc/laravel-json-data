<?php

namespace Folklore\Tests\Feature\Eloquent;

use Folklore\Eloquent\JsonData;
use Folklore\Tests\Feature\Eloquent\Fixtures\Game;
use Folklore\Tests\Feature\Eloquent\Fixtures\Prize;
use Folklore\Tests\Feature\Eloquent\Fixtures\RegistryGame;
use InvalidArgumentException;

/**
 * JsonData::stored(): a JSON attribute with its references as stored (docs/json-data.md,
 * "Reading", #88).
 */
class JsonDataStoredTest extends JsonDataTestCase
{
    public function test_a_resolved_attribute_reads_with_its_references_as_stored()
    {
        $media = $this->makeMedia();
        $block = $this->makeBlock();
        $game = new Game;
        $game->data = ['title' => ['fr' => 'Le quiz'], 'image' => ['fr' => $media], 'blocks' => [$block]];
        $game->save();
        $game = Game::findOrFail($game->id);

        $this->assertTrue($game->data['image']['fr']->is($media));
        $this->assertEquals([
            'title' => ['fr' => 'Le quiz'],
            'image' => ['fr' => 'medias://'.$media->id],
            'blocks' => ['blocks://'.$block->id],
        ], JsonData::stored($game, 'data'));
    }

    public function test_a_pending_value_reads_as_the_reference_stored_before_until_it_is_saved()
    {
        $before = $this->makeMedia();
        $after = $this->makeMedia();
        $added = $this->makeMedia();
        $game = new Game;
        $game->data = ['image' => ['fr' => $before]];
        $game->save();

        $game->data = ['image' => ['fr' => $after, 'en' => $added]];

        $this->assertTrue($game->data['image']['fr']->is($after));
        $this->assertEquals(['image' => ['fr' => 'medias://'.$before->id, 'en' => null]], JsonData::stored($game, 'data'));

        $game->save();

        $this->assertEquals(['image' => ['fr' => 'medias://'.$after->id, 'en' => 'medias://'.$added->id]], JsonData::stored($game, 'data'));
    }

    public function test_references_under_a_recursive_path_read_as_stored()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);
        $prize = Prize::create(['handle' => 'grand-prize']);
        $game = new RegistryGame;
        $game->data = ['body' => ['sections' => [['blocks' => [['prize' => $prize, 'text' => 'Win']]]]]];
        $game->save();
        $game = RegistryGame::findOrFail($game->id);

        $this->assertTrue($game->data['body']['sections'][0]['blocks'][0]['prize']->is($prize));
        $this->assertEquals(
            ['body' => ['sections' => [['blocks' => [['prize' => 'prize://grand-prize', 'text' => 'Win']]]]]],
            JsonData::stored($game, 'data'),
        );
    }

    public function test_an_empty_attribute_reads_as_null()
    {
        $this->assertNull(JsonData::stored(new Game, 'data'));
    }

    public function test_an_attribute_that_isnt_cast_is_an_error()
    {
        $this->expectException(InvalidArgumentException::class);

        JsonData::stored(new Game, 'handle');
    }
}
