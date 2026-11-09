<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonReferenceEntry;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Schema\ReferenceType;
use Folklore\JsonData\Tests\Feature\Fixtures\Media;
use Folklore\JsonData\Tests\Feature\Fixtures\Offer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `properties()`: a reference that keeps values of its own beside it, such as the edits of an
 * image for this use, stored as `{ "id": "{key}://{identifier}", …properties }` when it has one
 * of them, and as the reference string otherwise (docs/json-data.md, "References").
 */
class JsonDataReferencePropertiesTest extends JsonDataTestCase
{
    private const TRANSFORM = ['crop' => ['x' => 0, 'y' => 10, 'width' => 800, 'height' => 450], 'rotate' => 90];

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.locale', 'fr');
        $app['config']->set('json-data.locales', ['fr', 'en']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Offer::$schema = fn () => Json::object([
            'image' => self::media(),
            'gallery' => Json::list(self::media()),
            'cover' => self::media()->translatable(),
            'logo' => Json::reference('medias')->relation('medias'),
            'blocks' => Json::any()->paths(['*.media' => self::media()]),
        ])->additional(false);
    }

    protected function tearDown(): void
    {
        Offer::$schema = null;

        parent::tearDown();
    }

    private static function media(): ReferenceType
    {
        return Json::reference('medias')->relation('medias')->properties([
            'transform' => Json::object()->additional()->nullable(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function savedOffer(array $data): Offer
    {
        $offer = new Offer(['handle' => 'offer']);
        $offer->data = $data;
        $offer->save();

        return $offer;
    }

    public function test_a_reference_with_properties_is_declared_at_its_path_and_in_the_id_of_an_object()
    {
        $this->assertEquals([
            'references' => [
                ['path' => ['image', 'image.id'], 'key' => 'medias', 'relation' => 'medias', 'properties' => ['transform']],
                ['path' => ['gallery.*', 'gallery.*.id'], 'key' => 'medias', 'relation' => 'medias', 'properties' => ['transform']],
                ['path' => ['cover.*', 'cover.*.id'], 'key' => 'medias', 'relation' => 'medias', 'properties' => ['transform']],
                ['path' => 'logo', 'key' => 'medias', 'relation' => 'medias'],
                ['path' => ['blocks.*.media', 'blocks.*.media.id'], 'key' => 'medias', 'relation' => 'medias', 'properties' => ['transform']],
            ],
            'columns' => [],
        ], JsonData::declarations(new Offer, 'data', null, []));

        $this->assertEqualsCanonicalizing(
            ['image', 'image.id', 'gallery.*', 'gallery.*.id', 'cover.*', 'cover.*.id', 'logo', 'blocks.*.media', 'blocks.*.media.id'],
            JsonData::referencePaths(new Offer, 'data')[0]['paths'] ?? null,
        );
    }

    public function test_an_object_with_properties_keeps_them_and_resolves_its_reference()
    {
        $media = $this->makeMedia();
        $other = $this->makeMedia();

        $offer = $this->savedOffer([
            'image' => ['id' => $media->id, 'resource' => 'medias', 'transform' => self::TRANSFORM],
            'gallery' => [['id' => $other, 'transform' => self::TRANSFORM], $media],
            'blocks' => [['type' => 'image', 'media' => ['id' => 'medias://'.$other->id, 'transform' => self::TRANSFORM]]],
        ]);

        $raw = $this->rawData($offer);
        // The keys that aren't properties are left out; databases may reorder the keys of an object
        $this->assertEquals(['id' => 'medias://'.$media->id, 'transform' => self::TRANSFORM], $raw['image'] ?? null);
        $this->assertEquals([['id' => 'medias://'.$other->id, 'transform' => self::TRANSFORM], 'medias://'.$media->id], $raw['gallery'] ?? null);
        $this->assertEquals(['id' => 'medias://'.$other->id, 'transform' => self::TRANSFORM], $raw['blocks'][0]['media'] ?? null);
        $this->assertEqualsCanonicalizing([$media->id, $other->id], $offer->medias()->pluck('medias.id')->all());

        $data = Offer::findOrFail($offer->id)->data;
        $this->assertInstanceOf(Media::class, $data['image']['id']);
        $this->assertTrue($data['image']['id']->is($media));
        $this->assertEquals(self::TRANSFORM, $data['image']['transform']);
        $this->assertTrue($data['gallery'][0]['id']->is($other));
        $this->assertTrue($data['gallery'][1]->is($media));
        $this->assertTrue($data['blocks'][0]['media']['id']->is($other));
        $this->assertEquals(self::TRANSFORM, $data['blocks'][0]['media']['transform']);
    }

    public function test_a_value_without_properties_is_stored_as_its_reference()
    {
        $media = $this->makeMedia();

        $offer = $this->savedOffer([
            'image' => ['id' => $media->id, 'resource' => 'medias', 'transform' => null],
            'gallery' => [['id' => $media->id, 'transform' => []], ['id' => $media->id], $media->id],
            'cover' => ['fr' => ['id' => $media->id], 'en' => ['id' => $media->id, 'transform' => self::TRANSFORM]],
        ]);

        $raw = $this->rawData($offer);
        $this->assertSame('medias://'.$media->id, $raw['image'] ?? null);
        $this->assertSame(array_fill(0, 3, 'medias://'.$media->id), $raw['gallery'] ?? null);
        $this->assertSame('medias://'.$media->id, $raw['cover']['fr'] ?? null);
        $this->assertEquals(['id' => 'medias://'.$media->id, 'transform' => self::TRANSFORM], $raw['cover']['en'] ?? null);
    }

    public function test_stored_references_keep_reading_as_their_models()
    {
        $media = $this->makeMedia();
        $offer = Offer::create(['handle' => 'offer']);
        DB::table('offers')->where('id', $offer->id)->update(['data' => json_encode([
            'image' => 'medias://'.$media->id,
            'gallery' => ['medias://'.$media->id, ['id' => 'medias://'.$media->id, 'transform' => self::TRANSFORM]],
        ])]);

        $data = Offer::findOrFail($offer->id)->data;
        $this->assertTrue($data['image']->is($media));
        $this->assertTrue($data['gallery'][0]->is($media));
        $this->assertTrue($data['gallery'][1]['id']->is($media));
        $this->assertSame(
            ['image' => 'medias://'.$media->id, 'gallery' => ['medias://'.$media->id, ['id' => 'medias://'.$media->id, 'transform' => self::TRANSFORM]]],
            JsonData::stored(Offer::findOrFail($offer->id), 'data'),
        );
    }

    public function test_an_object_read_raw_and_written_back_is_kept()
    {
        $media = $this->makeMedia();
        $offer = $this->savedOffer(['image' => ['id' => $media, 'transform' => self::TRANSFORM]]);

        $fresh = Offer::findOrFail($offer->id);
        $fresh->data = JsonData::stored($fresh, 'data');
        $fresh->save();

        $this->assertEquals(['id' => 'medias://'.$media->id, 'transform' => self::TRANSFORM], $this->rawData($fresh)['image'] ?? null);
    }

    public function test_the_references_of_objects_are_listed()
    {
        $media = $this->makeMedia();
        $offer = $this->savedOffer(['image' => ['id' => $media->id, 'transform' => self::TRANSFORM]]);

        $this->assertEquals(
            [new JsonReferenceEntry('medias', (string) $media->id, JsonData::target('medias'), 'data', 'image.id', 'medias')],
            JsonData::references(Offer::findOrFail($offer->id))->all(),
        );
    }

    public function test_the_rules_check_the_reference_of_an_object_and_its_properties()
    {
        $media = $this->makeMedia();
        $errors = fn (array $input): array => collect(Validator::make($input, Json::rules(new Offer, 'data'))->errors()->keys())->sort()->values()->all();

        $this->assertSame([], $errors([
            'image' => ['id' => 'medias://'.$media->id, 'transform' => self::TRANSFORM],
            'gallery' => [$media->id, ['id' => $media->id, 'transform' => null]],
            'cover' => ['fr' => ['id' => $media->id, 'transform' => self::TRANSFORM]],
        ]));
        $this->assertSame(['cover.fr.transform', 'gallery.0', 'image', 'image.transform'], $errors([
            'image' => ['id' => 'medias://999', 'transform' => 'crop'],
            'gallery' => [['transform' => self::TRANSFORM]],
            'cover' => ['fr' => ['id' => $media->id, 'transform' => 'crop']],
        ]));
    }

    public function test_the_json_schema_gives_the_reference_or_an_object_with_properties()
    {
        $string = ['type' => 'string', 'pattern' => '^medias://.+$'];

        $this->assertSame([
            'oneOf' => [
                $string,
                [
                    'type' => 'object',
                    'properties' => ['id' => $string, 'transform' => ['type' => ['object', 'null'], 'additionalProperties' => true]],
                    'required' => ['id'],
                    'additionalProperties' => false,
                    'minProperties' => 2,
                ],
            ],
            'x-reference' => ['key' => 'medias', 'relation' => 'medias'],
        ], self::media()->toJsonSchema());
    }

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function invalidProperties(): array
    {
        return [
            'the id' => [['id' => Json::string()]],
            'a dotted name' => [['crop.x' => Json::integer()]],
            'a required property' => [['transform' => Json::object()->required()]],
            'an input key' => [['transform' => Json::object()->input('transform')]],
            'a reference' => [['credit' => Json::reference('medias')]],
            'a nested reference' => [['credit' => Json::object(['media' => Json::reference('medias')])]],
            'an extracted value' => [['caption' => Json::string()->extract('caption')]],
            'not a node' => [['transform' => 'object']],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $properties
     */
    #[DataProvider('invalidProperties')]
    public function test_properties_are_optional_plain_values(array $properties)
    {
        $this->expectException(InvalidJsonData::class);

        Json::reference('medias')->properties($properties);
    }
}
