<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonReferenceEntry;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Schema\ObjectType;
use Folklore\JsonData\Tests\Feature\Fixtures\LegacyOffer;
use Folklore\JsonData\Tests\Feature\Fixtures\Offer;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `paths()`: reference nodes at paths under a node, without describing its structure, as a
 * declaration of laravel-folklore 1.x names only the paths of its references
 * (docs/json-data.md, "References", #27).
 */
class JsonDataPathsTest extends JsonDataTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('json-data.locales', ['fr', 'en']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Offer::$schema = fn () => Json::object([
            'blocks' => Json::any()->paths([
                '*.media' => Json::relation('medias'),
                '*.blocks.*.media' => Json::relation('medias'),
            ]),
            'cards' => Json::map(Json::relation('medias'))->paths(['fr.*' => Json::relation('medias')]),
        ]);
        LegacyOffer::$references = [
            'blocks.*.media' => 'medias',
            'blocks.*.blocks.*.media' => 'medias',
            'cards.*' => 'medias',
            'cards.fr.*' => 'medias',
        ];
    }

    protected function tearDown(): void
    {
        Offer::$schema = null;
        LegacyOffer::$references = [];

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string<Offer>}>
     */
    public static function models(): array
    {
        return ['a schema' => [Offer::class], 'a 1.x declaration' => [LegacyOffer::class]];
    }

    private function compile(ObjectType $schema): mixed
    {
        Offer::$schema = fn () => $schema;

        return JsonData::declarations(new Offer, 'data', null, [])['references'];
    }

    public function test_the_paths_compile_under_the_node_they_are_chained_on()
    {
        $this->assertSame([
            ['path' => 'blocks.*.media', 'relation' => 'medias'],
            ['path' => 'blocks.*.blocks.*.media', 'relation' => 'medias'],
            ['path' => 'cards.*', 'relation' => 'medias'],
            ['path' => 'cards.fr.*', 'relation' => 'medias'],
        ], JsonData::declarations(new Offer, 'data', null, [])['references']);

        $this->assertSame([
            ['path' => 'items.*.photo.*', 'key' => 'medias', 'relation' => 'medias'],
        ], $this->compile(Json::object([
            'items' => Json::list(Json::object(['title' => Json::string()]))->paths([
                '*.photo' => Json::reference('medias')->relation('medias')->translatable(),
            ]),
        ])));
    }

    /**
     * @param  class-string<Offer>  $class
     */
    #[DataProvider('models')]
    public function test_a_model_declared_with_paths_does_what_the_1_x_declarations_do($class)
    {
        $first = $this->makeMedia();
        $second = $this->makeMedia();
        $card = $this->makeMedia();

        $offer = new $class(['handle' => 'offer']);
        $offer->data = [
            'blocks' => [['media' => $first, 'blocks' => [['media' => $second, 'text' => 'Hello']]]],
            'cards' => ['fr' => [$card]],
        ];
        $offer->save();

        $raw = $this->rawData($offer);
        $this->assertSame('medias://'.$first->id, $raw['blocks'][0]['media']);
        // MySQL and jsonb reorder the keys of an object
        $this->assertEquals(['media' => 'medias://'.$second->id, 'text' => 'Hello'], $raw['blocks'][0]['blocks'][0]);
        $this->assertSame(['fr' => ['medias://'.$card->id]], $raw['cards']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id, $card->id], $offer->medias()->pluck('medias.id')->all());

        $fresh = $class::findOrFail($offer->id);
        $this->assertTrue($fresh->data['blocks'][0]['blocks'][0]['media']->is($second));
        $this->assertEqualsCanonicalizing(
            ['blocks.0.media', 'blocks.0.blocks.0.media', 'cards.fr.0'],
            JsonData::references($fresh)->map(fn (JsonReferenceEntry $entry) => $entry->path)->all(),
        );
    }

    public function test_paths_add_no_input_key_nor_rule()
    {
        $media = $this->makeMedia();

        $offer = new Offer(['handle' => 'offer']);
        JsonData::fill($offer, ['blocks' => [['media' => $media->id, 'anything' => true]]]);
        $offer->save();

        $this->assertEquals([['media' => 'medias://'.$media->id, 'anything' => true]], $this->rawData($offer)['blocks']);
        $rules = Json::rules(new Offer, 'data');
        $this->assertSame([], $rules['blocks'] ?? []);
        $this->assertArrayNotHasKey('blocks.*.media', $rules);
        $this->assertTrue(Validator::make(['blocks' => [['media' => 'not a media']]], $rules)->passes());
    }

    /**
     * @return array<string, array{\Closure(): mixed}>
     */
    public static function refused(): array
    {
        return [
            'a node that is not a reference' => [fn () => Json::any()->paths(['*.title' => Json::string()])],
            'a recursive wildcard' => [fn () => Json::any()->paths(['**.media' => Json::relation('medias')])],
            'a partial wildcard' => [fn () => Json::any()->paths(['item*.media' => Json::relation('medias')])],
            'an empty segment' => [fn () => Json::any()->paths(['blocks..media' => Json::relation('medias')])],
            'a property of the object' => [fn () => Json::object(['image' => Json::string()])->paths(['image.fr' => Json::relation('medias')])],
        ];
    }

    #[DataProvider('refused')]
    public function test_paths_refuse_what_they_cant_declare(\Closure $declare)
    {
        $this->expectException(InvalidJsonData::class);

        $declare();
    }

    public function test_a_node_of_paths_cant_be_extracted_nor_read_from_an_input_key()
    {
        foreach ([
            Json::relation('medias')->extract('media_id'),
            Json::relation('medias')->input('media'),
        ] as $node) {
            try {
                $this->compile(Json::object(['blocks' => Json::any()->paths(['*.media' => $node])]));
                $this->fail('The node was compiled.');
            } catch (InvalidJsonData $exception) {
                $this->assertStringContainsString('paths()', $exception->getMessage());
            }
        }
    }
}
