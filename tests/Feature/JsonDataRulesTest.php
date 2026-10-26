<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Schema\ObjectType;
use Folklore\JsonData\Tests\Feature\Fixtures\Media;
use Folklore\JsonData\Tests\Feature\Fixtures\Offer;
use Folklore\JsonData\Tests\Feature\Fixtures\OfferStatus;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Folklore\JsonData\Tests\Feature\Fixtures\PrizesExceptSecret;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * The schema of JSON columns, second slice: `Json::rules()`, the Laravel validation rules
 * of the flat input that `JsonData::fill()` reads (docs/json-data.md, "Validation rules",
 * #6).
 */
class JsonDataRulesTest extends JsonDataTestCase
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

        JsonData::map(['prize' => [Prize::class, 'handle'], 'prizeid' => Prize::class]);
    }

    protected function tearDown(): void
    {
        Offer::$schema = null;

        parent::tearDown();
    }

    public function test_each_node_compiles_into_the_rules_of_its_type()
    {
        $rules = $this->rulesOf(Json::object([
            'name' => Json::string()->required()->min(2)->max(120)->pattern('/^[A-Za-z ]+$/'),
            'rank' => Json::integer()->min(1)->max(10),
            'ratio' => Json::number()->max(1.5),
            'active' => Json::boolean()->nullable(),
            'starts_on' => Json::date(),
            'starts_at' => Json::datetime(),
            'benefits' => Json::list(Json::string()->max(200))->max(10),
            'settings' => Json::map(Json::boolean())->min(1),
            'seo' => Json::object(['title' => Json::string(), 'description' => Json::string()])->additional(false),
            'links' => Json::object(['url' => Json::string()])->additional(true),
            'body' => Json::document(),
            'extra' => Json::any(),
        ]));

        $this->assertSame(['required', 'string', 'min:2', 'max:120', 'regex:/^[A-Za-z ]+$/'], $rules['name']);
        $this->assertSame(['integer', 'min:1', 'max:10'], $rules['rank']);
        $this->assertSame(['numeric', 'max:1.5'], $rules['ratio']);
        $this->assertSame(['nullable', 'boolean'], $rules['active']);
        $this->assertSame(['date_format:Y-m-d'], $rules['starts_on']);
        $this->assertSame(['array', 'list', 'max:10'], $rules['benefits']);
        $this->assertSame(['string', 'max:200'], $rules['benefits.*']);
        $this->assertSame(['array', 'min:1'], $rules['settings']);
        $this->assertSame(['boolean'], $rules['settings.*']);
        $this->assertSame(['array:title,description'], $rules['seo']);
        $this->assertSame(['string'], $rules['seo.title']);
        $this->assertSame(['array'], $rules['links']);
        $this->assertSame(['array'], $rules['body']);
        $this->assertSame([], $rules['extra']);
    }

    public function test_the_rules_check_the_values_of_the_input()
    {
        Offer::$schema = fn () => Json::object([
            'name' => Json::string()->max(5)->pattern('/^[a-z]+$/'),
            'rank' => Json::integer()->min(1),
            'starts_on' => Json::date(),
            'starts_at' => Json::datetime(),
            'benefits' => Json::list(Json::string())->max(2),
            'seo' => Json::object(['title' => Json::string()])->additional(false),
        ]);

        $this->assertSame([], $this->errors([
            'name' => 'abc',
            'rank' => 3,
            'starts_on' => '2026-10-23',
            'starts_at' => '2026-10-23T14:30:00.000Z',
            'benefits' => ['one', 'two'],
            'seo' => ['title' => 'Title'],
        ]));
        $this->assertSame(['name', 'rank', 'starts_on', 'starts_at', 'benefits', 'seo'], array_keys($this->errors([
            'name' => 'Abcdef',
            'rank' => 0,
            'starts_on' => '23/10/2026',
            'starts_at' => '2026-10-23',
            'benefits' => ['one', 'two', 'three'],
            'seo' => ['title' => 'Title', 'keywords' => 'unknown'],
        ])));
        $this->assertArrayHasKey('benefits', $this->errors(['benefits' => ['a' => 'not a list']]));
        $this->assertSame([], $this->errors(['starts_at' => '2026-10-23T14:30:00Z']));
        $this->assertSame([], $this->errors(['starts_at' => '2026-10-23T14:30:00+02:00']));
    }

    public function test_a_required_node_is_required_for_a_new_model_only()
    {
        Offer::$schema = fn () => Json::object([
            'name' => Json::string()->required(),
            'status' => Json::enum(OfferStatus::class)->required()->default(OfferStatus::Draft),
        ]);

        $this->assertSame(['name'], array_keys($this->errors([])));
        $this->assertSame([], $this->errors(['name' => 'Offer']));

        $stored = Offer::create(['handle' => 'offer']);
        $this->assertSame(['sometimes', 'required', 'string'], Json::rules($stored, 'data')['name']);
        $this->assertSame([], $this->errors([], $stored));
        $this->assertSame(['name'], array_keys($this->errors(['name' => ''], $stored)));
    }

    public function test_null_is_refused_unless_the_node_is_nullable()
    {
        Offer::$schema = fn () => Json::object([
            'name' => Json::string(),
            'tagline' => Json::string()->nullable(),
        ]);

        $this->assertSame(['name'], array_keys($this->errors(['name' => null, 'tagline' => null])));
    }

    public function test_a_required_property_of_an_object_is_required_with_its_object()
    {
        Offer::$schema = fn () => Json::object([
            'seo' => Json::object([
                'title' => Json::string()->required()->input('seo_title'),
                'description' => Json::string()->required(),
            ]),
        ]);

        $rules = Json::rules(new Offer, 'data');
        $this->assertSame(['required_with:seo', 'string'], $rules['seo.description']);
        $this->assertSame(['required_without:seo.title', 'string'], $rules['seo_title']);
        $this->assertSame(['string'], $rules['seo.title']);

        $this->assertSame(['seo_title'], array_keys($this->errors([])));
        $this->assertSame([], $this->errors(['seo_title' => 'Title']));
        $this->assertSame([], $this->errors(['seo' => ['title' => 'Title', 'description' => 'Text']]));
        $this->assertSame(['seo.description'], array_keys($this->errors(['seo' => ['title' => 'Title']])));
    }

    public function test_the_items_of_a_list_are_checked_in_full()
    {
        Offer::$schema = fn () => Json::object([
            'prizes' => Json::list(Json::object([
                'label' => Json::string()->required(),
                'quantity' => Json::integer()->required()->default(1),
            ])),
        ]);

        $stored = Offer::create(['handle' => 'offer']);
        $this->assertSame(['required_with:prizes.*', 'string'], Json::rules($stored, 'data')['prizes.*.label']);
        $this->assertSame([], $this->errors(['prizes' => [['label' => 'First']]], $stored));
        $this->assertSame(['prizes.1.label'], array_keys($this->errors(['prizes' => [['label' => 'First'], ['quantity' => 2]]], $stored)));
    }

    public function test_a_translatable_node_takes_a_string_or_its_locales()
    {
        Offer::$schema = fn () => Json::object([
            'title' => Json::string()->translatable()->max(5),
        ]);

        $this->assertSame([], $this->errors(['title' => 'Short']));
        $this->assertSame([], $this->errors(['title' => ['fr' => 'Court', 'en' => null]]));
        $this->assertSame(['title.en'], array_keys($this->errors(['title' => ['fr' => 'Court', 'en' => 'Too long']])));

        $tooLong = $this->errors(['title' => 'Too long']);
        $this->assertSame(['title'], array_keys($tooLong));
        $this->assertStringContainsString('title', $tooLong['title'][0]);

        $unknown = $this->errors(['title' => ['de' => 'Titel']]);
        $this->assertSame(['title'], array_keys($unknown));
        $this->assertStringContainsString('de', $unknown['title'][0]);
    }

    public function test_an_enum_takes_its_values()
    {
        Offer::$schema = fn () => Json::object([
            'status' => Json::enum(OfferStatus::class),
            'size' => Json::enum(['small', 'large']),
        ]);

        $this->assertSame([], $this->errors(['status' => 'draft', 'size' => 'small']));
        $this->assertSame([], $this->errors(['status' => OfferStatus::Published]));
        $this->assertSame(['status', 'size'], array_keys($this->errors(['status' => 'archived', 'size' => 'medium'])));
    }

    public function test_a_reference_must_resolve_to_a_model_the_owner_may_refer_to()
    {
        $prize = Prize::create(['handle' => 'first']);
        Prize::create(['handle' => 'secret']);
        Offer::$schema = fn () => Json::object([
            'prize' => Json::reference('prize')->relation('prizes'),
            'source' => Json::reference('prize')->using(PrizesExceptSecret::class),
        ]);

        $this->assertSame([], $this->errors(['prize' => 'prize://first']));
        $this->assertSame([], $this->errors(['prize' => 'first']));
        $this->assertSame([], $this->errors(['prize' => 'prizeid://'.$prize->id]));
        $this->assertSame([], $this->errors(['prize' => $prize]));
        $this->assertSame(['prize'], array_keys($this->errors(['prize' => 'prize://missing'])));
        $this->assertSame(['prize'], array_keys($this->errors(['prize' => 'missing'])));
        $this->assertSame(['prize'], array_keys($this->errors(['prize' => 'medias://1'])));
        $this->assertSame(['prize'], array_keys($this->errors(['prize' => $this->makeMedia()])));
        $this->assertSame(['source'], array_keys($this->errors(['source' => 'prize://secret'])));
        $this->assertSame(['source'], array_keys($this->errors(['source' => Prize::where('handle', 'secret')->firstOrFail()])));
    }

    public function test_a_value_that_isnt_a_reference_or_an_identifier_is_left_to_the_handler()
    {
        Offer::$schema = fn () => Json::object([
            'image' => Json::reference('medias')->relation('medias')->translatable(),
            'gallery' => Json::list(Json::reference('medias')->relation('medias')),
        ]);
        $media = $this->makeMedia();

        $this->assertSame([], $this->errors([
            'image' => ['fr' => UploadedFile::fake()->create('image.jpg'), 'en' => 'medias://'.$media->id],
            'gallery' => [$media->id, ['id' => $media->id]],
        ]));
        $this->assertSame(['image.en', 'gallery.0'], array_keys($this->errors([
            'image' => ['en' => 'medias://999'],
            'gallery' => [999],
        ])));
        $this->assertSame(['image'], array_keys($this->errors(['image' => 'medias://999'])));
    }

    public function test_the_validated_input_fills_the_model()
    {
        $prize = Prize::create(['handle' => 'first']);
        $offer = new Offer(['handle' => 'offer']);
        $input = [
            'title' => ['fr' => 'Titre', 'en' => 'Title'],
            'subtitle' => 'Tagline',
            'seo_title' => 'SEO',
            'prizes' => [['prize' => 'prize://first', 'quantity' => 2]],
            'status' => 'published',
            'settings' => ['public' => true],
            'extra' => ['anything' => [1, 2]],
        ];

        $validated = Validator::make($input, Json::rules($offer, 'data'))->validated();
        JsonData::fill($offer, $validated);
        $offer->save();

        $this->assertEquals($input, $validated);
        $this->assertSame('Tagline', JsonData::stored($offer, 'data')['tagline']);
        $this->assertSame('SEO', JsonData::stored($offer, 'data')['seo']['title']);
        $this->assertSame([$prize->id], $offer->prizes()->pluck('prizes.id')->all());
    }

    public function test_the_rules_of_every_attribute_that_has_a_schema()
    {
        $offer = new Offer;

        $this->assertEquals(Json::rules($offer, 'data'), Json::rules($offer));
        $this->assertSame([], Json::rules($offer, 'meta'));
        $this->assertSame([], Json::rules(new Media, 'data'));
    }

    public function test_an_invalid_schema_is_refused()
    {
        Offer::$schema = fn () => Json::object([
            'items' => Json::list(Json::object(['label' => Json::string()->input('label')])),
        ]);

        $this->expectException(InvalidJsonData::class);

        Json::rules(new Offer, 'data');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rulesOf(ObjectType $schema): array
    {
        Offer::$schema = fn () => $schema;

        return Json::rules(new Offer, 'data');
    }

    /**
     * The validation errors of an input, by key.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>>
     */
    private function errors(array $input, ?Offer $offer = null): array
    {
        return Validator::make($input, Json::rules($offer ?? new Offer, 'data'))->errors()->toArray();
    }
}
