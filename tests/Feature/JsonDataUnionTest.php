<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\Schema\Compiler;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Schema\ObjectType;
use Folklore\JsonData\Schema\UnionType;
use Folklore\JsonData\Tests\Feature\Fixtures\Offer;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Illuminate\Support\Facades\Validator;

/**
 * Unions, objects whose schema is chosen by a discriminator (`Json::union()`): the typed
 * blocks of a page, nested down to a depth (docs/json-data.md, "Unions").
 */
class JsonDataUnionTest extends JsonDataTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.locale', 'fr');
        $app['config']->set('json-data.locales', ['fr', 'en']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        JsonData::map(['prize' => [Prize::class, 'handle']]);
        Offer::$schema = fn () => $this->schema();
    }

    protected function tearDown(): void
    {
        Offer::$schema = null;

        parent::tearDown();
    }

    /**
     * Typed blocks; a stack holds blocks, down to the depth.
     */
    private function blocks(int $depth = 2): UnionType
    {
        $variants = [
            'text' => Json::object(['body' => Json::string()->translatable()->required()])->additional(false),
            'image' => Json::object([
                'media' => Json::reference('medias')->relation('medias'),
                'caption' => Json::string(),
            ])->additional(false),
            'prize' => Json::object([
                'prize' => Json::reference('prize')->relation('prizes'),
                'quantity' => Json::integer()->default(1),
            ])->additional(false),
        ];
        if ($depth > 1) {
            $variants['stack'] = Json::object(['blocks' => Json::list($this->blocks($depth - 1))])->additional(false);
        }

        return Json::union('type', $variants);
    }

    private function schema(): ObjectType
    {
        return Json::object([
            'blocks' => Json::list($this->blocks()),
            'hero' => $this->blocks(1),
        ])->additional(false);
    }

    public function test_a_union_declares_the_references_of_its_variants_once()
    {
        $paths = array_map(
            fn (array $reference) => $reference['path'].' '.$reference['key'],
            Compiler::compile($this->schema())['references'],
        );

        $this->assertSame([
            'blocks.*.media medias',
            'blocks.*.prize prize',
            'blocks.*.blocks.*.media medias',
            'blocks.*.blocks.*.prize prize',
            'hero.media medias',
            'hero.prize prize',
        ], $paths);
    }

    public function test_each_object_is_stored_as_its_variant()
    {
        $prize = Prize::create(['handle' => 'grand-prize']);
        $media = $this->makeMedia();

        $offer = new Offer(['handle' => 'offer']);
        JsonData::fill($offer, ['blocks' => [
            ['type' => 'text', 'body' => 'Bonjour'],
            ['type' => 'prize', 'prize' => $prize],
            ['type' => 'stack', 'blocks' => [['caption' => 'Logo', 'type' => 'image', 'media' => $media]]],
        ]]);
        $offer->save();

        $this->assertSame([
            ['type' => 'text', 'body' => ['fr' => 'Bonjour']],
            ['type' => 'prize', 'prize' => 'prize://grand-prize', 'quantity' => 1],
            ['type' => 'stack', 'blocks' => [['type' => 'image', 'caption' => 'Logo', 'media' => 'medias://'.$media->id]]],
        ], $this->rawData($offer)['blocks']);
        $this->assertSame([$prize->id], $offer->prizes()->pluck('prizes.id')->all());
        $this->assertSame([$media->id], $offer->medias()->pluck('medias.id')->all());

        $fresh = Offer::query()->findOrFail($offer->id);
        $this->assertTrue($fresh->data['blocks'][1]['prize']->is($prize));
        $this->assertTrue($fresh->data['blocks'][2]['blocks'][0]['media']->is($media));
    }

    public function test_an_object_keeps_its_stored_properties_only_for_the_same_type()
    {
        $offer = new Offer(['handle' => 'offer']);
        JsonData::fill($offer, ['hero' => ['type' => 'text', 'body' => 'Bonjour']]);
        $offer->save();

        JsonData::fill($offer, ['hero' => ['body' => ['en' => 'Hello']]]);
        $offer->save();
        $this->assertSame(['type' => 'text', 'body' => ['fr' => 'Bonjour', 'en' => 'Hello']], $this->rawData($offer)['hero']);

        JsonData::fill($offer, ['hero' => ['type' => 'image', 'caption' => 'Logo']]);
        $offer->save();
        $this->assertSame(['type' => 'image', 'caption' => 'Logo'], $this->rawData($offer)['hero']);
    }

    public function test_an_unknown_type_is_refused()
    {
        $this->expectException(InvalidJsonData::class);
        $this->expectExceptionMessage('[video] at [data.blocks.0.type] is not one of the variants of its union.');

        JsonData::fill(new Offer, ['blocks' => [['type' => 'video']]]);
    }

    public function test_a_key_outside_of_a_closed_variant_is_refused()
    {
        $this->expectException(InvalidJsonData::class);
        $this->expectExceptionMessage('[data.blocks.0.extra]');

        JsonData::fill(new Offer, ['blocks' => [['type' => 'text', 'body' => 'Bonjour', 'extra' => true]]]);
    }

    public function test_the_rules_check_each_object_with_the_rules_of_its_variant()
    {
        Prize::create(['handle' => 'grand-prize']);
        $input = [
            'blocks' => [
                ['type' => 'text'],
                ['type' => 'image', 'caption' => 5],
                ['type' => 'video'],
                ['type' => 'stack', 'blocks' => [['type' => 'prize', 'prize' => 'prize://unknown']]],
                ['type' => 'text', 'body' => 'Bonjour', 'extra' => true],
                'not an object',
            ],
            'hero' => ['type' => 'prize', 'quantity' => 'many'],
        ];

        $validator = Validator::make($input, Json::rules(new Offer, 'data'));

        $this->assertSame([
            'blocks.0.body',
            'blocks.1.caption',
            'blocks.2',
            'blocks.3.blocks.0.prize',
            'blocks.4',
            'blocks.5',
            'hero.quantity',
        ], collect($validator->errors()->keys())->sort()->values()->all());
    }

    public function test_valid_objects_pass_and_are_validated_whole()
    {
        Prize::create(['handle' => 'grand-prize']);
        $input = [
            'blocks' => [
                ['type' => 'text', 'body' => ['fr' => 'Bonjour', 'en' => 'Hello']],
                ['type' => 'stack', 'blocks' => [['type' => 'prize', 'prize' => 'prize://grand-prize']]],
            ],
            'hero' => ['type' => 'image', 'caption' => 'Logo'],
        ];

        $validator = Validator::make($input, Json::rules(new Offer, 'data'));

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
        $this->assertEquals($input, $validator->validated());
    }

    public function test_a_union_needs_variants_without_the_discriminator()
    {
        try {
            Json::union('type', []);
            $this->fail('A union without variants was accepted.');
        } catch (InvalidJsonData $exception) {
            $this->assertStringContainsString('has no variant', $exception->getMessage());
        }

        $this->expectException(InvalidJsonData::class);
        $this->expectExceptionMessage('declares the discriminator [type]');
        Json::union('type', ['text' => Json::object(['type' => Json::string()])]);
    }

    public function test_variants_that_declare_a_shared_path_differently_are_refused()
    {
        $this->expectException(InvalidJsonData::class);
        $this->expectExceptionMessage('[hero.prize]');

        Compiler::compile(Json::object([
            'hero' => Json::union('type', [
                'won' => Json::object(['prize' => Json::reference('prize')->relation('prizes')]),
                'offered' => Json::object(['prize' => Json::reference('prize')]),
            ]),
        ]));
    }

    public function test_a_node_under_a_union_has_no_input_key_or_column()
    {
        $this->expectException(InvalidJsonData::class);
        $this->expectExceptionMessage('under a list, a map or a union');

        Compiler::compile(Json::object([
            'hero' => Json::union('type', ['text' => Json::object(['body' => Json::string()->extract('hero_body')])]),
        ]));
    }
}
