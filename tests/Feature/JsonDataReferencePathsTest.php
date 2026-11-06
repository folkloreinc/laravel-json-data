<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Tests\Feature\Fixtures\Game;
use Folklore\JsonData\Tests\Feature\Fixtures\LegacyOffer;
use Folklore\JsonData\Tests\Feature\Fixtures\Offer;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * JsonData::referencePaths(): the reference paths declared for a JSON attribute, whatever
 * declares them (docs/json-data.md, "Reading the declared paths", #42).
 */
class JsonDataReferencePathsTest extends JsonDataTestCase
{
    protected function tearDown(): void
    {
        LegacyOffer::$references = [];

        parent::tearDown();
    }

    public function test_it_reads_the_paths_of_a_schema()
    {
        JsonData::map(['prize' => [Prize::class, 'handle']]);

        $this->assertSame([
            ['relation' => 'medias', 'key' => 'medias', 'paths' => ['image.*', 'gallery.*', 'body.**']],
            ['relation' => 'prizes', 'key' => 'prize', 'paths' => ['prizes.*.prize']],
            ['relation' => null, 'key' => 'prize', 'paths' => ['source', 'body.**']],
        ], JsonData::referencePaths(new Offer, 'data'));
    }

    public function test_it_reads_the_paths_of_the_declarations_hook()
    {
        $this->assertSame([
            ['relation' => 'medias', 'key' => null, 'paths' => ['image.*', 'questions.*.choices.*.image']],
            ['relation' => 'blocks', 'key' => null, 'paths' => ['blocks.*']],
            ['relation' => 'prizes', 'key' => null, 'paths' => ['prizes.*.prize']],
        ], JsonData::referencePaths(new Game, 'data'));
    }

    public function test_the_paths_of_a_relation_are_grouped_by_key()
    {
        JsonData::map(['prize' => [Prize::class, 'handle'], 'prizes' => Prize::class]);
        LegacyOffer::$references = [
            'source' => ['relation' => 'prizes', 'key' => 'prize'],
            'prizes.*.prize' => ['relation' => 'prizes', 'key' => 'prizes'],
            'winner' => ['relation' => 'prizes', 'key' => 'prize', 'lazy' => true],
            'related' => ['key' => 'prize'],
            'image' => ['relation' => fn () => 'medias'],
        ];

        $this->assertSame([
            ['relation' => 'prizes', 'key' => 'prize', 'paths' => ['source', 'winner']],
            ['relation' => 'prizes', 'key' => 'prizes', 'paths' => ['prizes.*.prize']],
            ['relation' => null, 'key' => 'prize', 'paths' => ['related']],
            ['relation' => null, 'key' => null, 'paths' => ['image']],
        ], JsonData::referencePaths(new LegacyOffer, 'data'));
    }

    public function test_a_column_without_declarations_has_no_paths()
    {
        $this->assertSame([], JsonData::referencePaths(new Offer, 'meta'));
    }

    public function test_the_hook_is_given_the_stored_value_or_the_given_one()
    {
        $model = new class extends Model
        {
            protected $casts = ['data' => JsonDataCast::class];
        };
        JsonData::declareUsing(fn (Model $declared, string $attribute, mixed $value) => $declared::class === $model::class
            ? ['references' => is_array($value) && ($value['type'] ?? null) === 'video' ? ['poster' => 'medias'] : ['image' => 'medias']]
            : null);

        $this->assertSame([['relation' => 'medias', 'key' => null, 'paths' => ['image']]], JsonData::referencePaths($model, 'data'));

        $model->setRawAttributes(['data' => json_encode(['type' => 'video'])]);
        $this->assertSame([['relation' => 'medias', 'key' => null, 'paths' => ['poster']]], JsonData::referencePaths($model, 'data'));
        $this->assertSame(
            [['relation' => 'medias', 'key' => null, 'paths' => ['image']]],
            JsonData::referencePaths($model, 'data', ['type' => 'image']),
        );
    }

    public function test_an_attribute_that_is_not_cast_throws()
    {
        $this->expectException(InvalidArgumentException::class);

        JsonData::referencePaths(new Game, 'title_fr');
    }
}
