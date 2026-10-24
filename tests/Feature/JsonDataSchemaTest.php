<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\Contracts\LocaleResolver;
use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonReferenceEntry;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Tests\Feature\Fixtures\Media;
use Folklore\JsonData\Tests\Feature\Fixtures\Offer;
use Folklore\JsonData\Tests\Feature\Fixtures\OfferStatus;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The schema of JSON columns, first slice: the declaration, the references and columns it
 * compiles into, and `JsonData::fill()` (docs/json-data.md, "Schema", laravel-folklore's
 * decision 0020).
 */
class JsonDataSchemaTest extends JsonDataTestCase
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
        Json::flushMacros();

        parent::tearDown();
    }

    private function prize(string $handle): Prize
    {
        return Prize::create(['handle' => $handle]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storedOffer(array $data): Offer
    {
        $offer = Offer::create(['handle' => 'offer']);
        DB::table('offers')->where('id', $offer->id)->update(['data' => json_encode($data)]);

        $fresh = Offer::find($offer->id);
        $this->assertInstanceOf(Offer::class, $fresh);

        return $fresh;
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    private function filledOffer(array $input): Offer
    {
        $offer = new Offer(['handle' => 'offer']);
        JsonData::fill($offer, $input);
        $offer->save();

        return $offer;
    }

    public function test_the_schema_is_memoized_per_instance_and_attribute()
    {
        $media = $this->makeMedia();
        $offer = $this->filledOffer(['image' => $media, 'title' => 'Offre']);
        $offer->data;
        JsonData::references($offer);

        $this->assertSame(1, $offer->schemaCalls['data']);
        $this->assertSame(1, $offer->schemaCalls['meta']);

        $fresh = Offer::find($offer->id);
        $fresh?->data;
        $this->assertSame(1, $fresh?->schemaCalls['data']);
    }

    public function test_the_schema_can_depend_on_the_instance()
    {
        Offer::$schema = fn (Offer $offer) => Json::object($offer->handle === 'contest'
            ? ['rules' => Json::string()]
            : ['tagline' => Json::string()])->additional(false);

        $contest = new Offer(['handle' => 'contest']);
        JsonData::fill($contest, ['rules' => 'Be nice']);
        $this->assertSame(['rules' => 'Be nice'], $contest->data);

        $this->expectException(InvalidJsonData::class);
        JsonData::fill(new Offer(['handle' => 'offer']), ['rules' => 'Be nice']);
    }

    public function test_a_schema_that_reads_its_own_attribute_does_not_loop()
    {
        Offer::$schema = fn (Offer $offer) => Json::object(($offer->data['kind'] ?? null) === 'event'
            ? ['kind' => Json::string(), 'venue' => Json::string()]
            : ['kind' => Json::string()]);
        $offer = $this->storedOffer(['kind' => 'event']);

        JsonData::fill($offer, ['venue' => 'Montréal']);

        $this->assertSame(['kind' => 'event', 'venue' => 'Montréal'], $offer->data);
    }

    public function test_an_attribute_without_a_schema_is_declared_by_the_hook()
    {
        JsonData::declareUsing(fn (Model $model, string $attribute) => $model instanceof Offer && $attribute === 'meta'
            ? ['references' => ['logo' => 'medias']]
            : null);
        $media = $this->makeMedia();

        $offer = new Offer(['handle' => 'offer']);
        $offer->meta = ['logo' => $media];
        $offer->save();

        $this->assertSame('medias://'.$media->id, $this->rawData($offer, 'meta')['logo']);
        $this->assertTrue($offer->medias()->first()?->is($media));
    }

    public function test_a_schema_compiles_into_the_declarations_of_the_hook()
    {
        $offer = new Offer;

        $this->assertSame([
            'references' => [
                ['path' => 'image.*', 'key' => 'medias', 'relation' => 'medias'],
                ['path' => 'gallery.*', 'key' => 'medias', 'relation' => 'medias'],
                ['path' => 'prizes.*.prize', 'key' => 'prize', 'relation' => 'prizes'],
                ['path' => 'source', 'key' => 'prize'],
                ['path' => 'body.**', 'key' => 'medias', 'relation' => 'medias'],
                ['path' => 'body.**', 'key' => 'prize'],
            ],
            'columns' => [
                'title.fr' => 'title_fr',
                'title.en' => 'title_en',
                'sort_name' => 'name_sort',
            ],
        ], JsonData::declarations($offer, 'data', null, []));
    }

    public function test_references_are_written_resolved_and_synced()
    {
        $media = $this->makeMedia();
        $gallery = $this->makeMedia();
        $prize = $this->prize('grand-prize');

        $offer = $this->filledOffer([
            'image' => $media,
            'gallery' => [$gallery->id],
            'prizes' => [['prize' => $prize]],
        ]);

        $raw = $this->rawData($offer);
        $this->assertSame(['fr' => 'medias://'.$media->id], $raw['image']);
        $this->assertSame(['medias://'.$gallery->id], $raw['gallery']);
        // Databases may reorder the keys of an object
        $this->assertEquals([['prize' => 'prize://grand-prize', 'quantity' => 1]], $raw['prizes']);
        $this->assertEqualsCanonicalizing([$media->id, $gallery->id], $offer->medias()->pluck('medias.id')->all());
        $this->assertSame([$prize->id], $offer->prizes()->pluck('prizes.id')->all());

        $fresh = Offer::find($offer->id);
        $this->assertTrue($fresh?->data['image']['fr']->is($media));
        $this->assertTrue($fresh?->data['prizes'][0]['prize']->is($prize));
    }

    public function test_a_reference_without_a_relation_is_resolved_and_listed_but_not_synced()
    {
        $prize = $this->prize('grand-prize');

        $offer = $this->filledOffer(['source' => $prize]);

        $this->assertSame('prize://grand-prize', $this->rawData($offer)['source']);
        $this->assertSame(0, DB::table('prizes_pivot')->count());
        $this->assertTrue(Offer::find($offer->id)?->data['source']->is($prize));
        $this->assertEquals(
            [new JsonReferenceEntry('prize', 'grand-prize', JsonData::target('prize'), 'data', 'source', null)],
            JsonData::references($offer)->all(),
        );
    }

    public function test_a_reference_without_a_relation_accepts_every_key_of_its_model()
    {
        $prize = $this->prize('grand-prize');
        $offer = $this->storedOffer(['source' => 'prizeid://'.$prize->id]);

        $this->assertTrue($offer->data['source']->is($prize));

        JsonData::fill($offer, ['subtitle' => 'Win']);
        $offer->save();
        $this->assertSame('prizeid://'.$prize->id, $this->rawData($offer)['source']);
    }

    public function test_references_without_a_relation_resolve_in_batches()
    {
        $first = $this->prize('first');
        $second = $this->prize('second');
        $this->storedOffer(['source' => 'prize://first']);
        $this->storedOffer(['source' => 'prize://second']);
        $offers = Offer::query()->orderBy('id')->get();

        DB::enableQueryLog();
        JsonData::load($offers);
        $queries = count(DB::getQueryLog());
        $this->assertTrue($offers[0]->data['source']->is($first));
        $this->assertTrue($offers[1]->data['source']->is($second));

        $this->assertSame(1, $queries);
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_the_rewrite_command_rewrites_references_without_a_relation()
    {
        $prize = $this->prize('grand-prize');
        $offer = $this->storedOffer(['source' => 'prizeid://'.$prize->id]);

        $this->artisan('folklore:json-references:rewrite', ['--from' => 'prizeid', '--to' => 'prize', '--model' => [Offer::class]])
            ->assertSuccessful();

        $this->assertSame('prize://grand-prize', $this->rawData($offer)['source']);
    }

    public function test_a_document_holds_references_at_any_depth()
    {
        $media = $this->makeMedia();
        $prize = $this->prize('grand-prize');
        $body = ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['src' => 'medias://'.$media->id]],
            ['type' => 'prize', 'attrs' => ['prize' => 'prize://grand-prize', 'url' => 'https://folklore.email']],
        ]];

        $offer = $this->filledOffer(['body' => $body]);

        $this->assertEquals($body, $this->rawData($offer)['body']);
        $this->assertSame([$media->id], $offer->medias()->pluck('medias.id')->all());
        $this->assertSame(0, DB::table('prizes_pivot')->count());
        $content = Offer::find($offer->id)?->data['body']['content'];
        $this->assertTrue($content[0]['attrs']['src']->is($media));
        $this->assertTrue($content[1]['attrs']['prize']->is($prize));
        $this->assertSame('https://folklore.email', $content[1]['attrs']['url']);
    }

    public function test_extracted_values_are_copied_into_columns()
    {
        $offer = $this->filledOffer(['title' => ['fr' => 'Offre', 'en' => 'Offer'], 'sort_name' => 'offer']);

        $row = DB::table('offers')->where('id', $offer->id)->first();
        $this->assertSame('Offre', $row?->title_fr);
        $this->assertSame('Offer', $row?->title_en);
        $this->assertSame('offer', $row?->name_sort);

        JsonData::fill($offer, ['title' => null]);
        $offer->save();
        $this->assertNull(DB::table('offers')->where('id', $offer->id)->value('title_fr'));
    }

    public function test_fill_reads_the_input_names()
    {
        $offer = $this->filledOffer(['subtitle' => 'Win', 'seo_title' => 'An offer', 'seo' => ['description' => 'All about it']]);

        $this->assertSame('Win', $offer->data['tagline']);
        $this->assertSame(['description' => 'All about it', 'title' => 'An offer'], $offer->data['seo']);
    }

    public function test_a_node_read_from_another_input_key_is_not_read_from_its_name()
    {
        $this->expectException(InvalidJsonData::class);
        $this->expectExceptionMessage('[tagline]');

        JsonData::fill(new Offer, ['tagline' => 'Win']);
    }

    public function test_a_partial_update_keeps_the_paths_it_does_not_give()
    {
        $offer = $this->filledOffer(['subtitle' => 'Win', 'seo' => ['title' => 'An offer', 'description' => 'All about it']]);

        JsonData::fill($offer, ['seo' => ['title' => 'The offer'], 'starts_on' => '2026-11-01']);
        $offer->save();

        $this->assertEquals([
            'tagline' => 'Win',
            'seo' => ['title' => 'The offer', 'description' => 'All about it'],
            'status' => 'draft',
            'starts_on' => '2026-11-01',
        ], $this->rawData($offer));
    }

    public function test_null_clears_a_path()
    {
        $offer = $this->filledOffer(['subtitle' => 'Win', 'seo' => ['title' => 'An offer']]);

        JsonData::fill($offer, ['subtitle' => null, 'seo_title' => null]);

        $this->assertNull($offer->data['tagline']);
        $this->assertSame(['title' => null], $offer->data['seo']);
    }

    public function test_lists_and_maps_replace_the_stored_ones()
    {
        $first = $this->makeMedia();
        $second = $this->makeMedia();
        $offer = $this->filledOffer(['gallery' => [$first, $second], 'settings' => ['featured' => true, 'archived' => false]]);

        JsonData::fill($offer, ['gallery' => [$second], 'settings' => ['featured' => false]]);
        $offer->save();

        $this->assertSame(['medias://'.$second->id], $this->rawData($offer)['gallery']);
        $this->assertSame(['featured' => false], $this->rawData($offer)['settings']);
        $this->assertSame([$second->id], $offer->medias()->pluck('medias.id')->all());
    }

    public function test_references_that_do_not_resolve_are_kept()
    {
        $offer = $this->storedOffer(['image' => ['fr' => 'medias://999'], 'source' => 'prize://gone']);
        $this->assertNull($offer->data['image']['fr']);

        JsonData::fill($offer, ['subtitle' => 'Win']);
        $offer->save();

        $raw = $this->rawData($offer);
        $this->assertSame(['fr' => 'medias://999'], $raw['image']);
        $this->assertSame('prize://gone', $raw['source']);
    }

    public function test_values_assigned_and_not_saved_yet_are_kept()
    {
        $media = $this->makeMedia();
        $offer = new Offer(['handle' => 'offer']);
        JsonData::fill($offer, ['image' => $media]);

        JsonData::fill($offer, ['subtitle' => 'Win']);
        $offer->save();

        $this->assertSame(['fr' => 'medias://'.$media->id], $this->rawData($offer)['image']);
    }

    public function test_a_string_given_to_a_translatable_node_goes_into_the_default_locale()
    {
        $offer = $this->filledOffer(['title' => ['fr' => 'Offre', 'en' => 'Offer']]);

        JsonData::fill($offer, ['title' => 'Nouvelle offre']);
        $this->assertSame(['fr' => 'Nouvelle offre', 'en' => 'Offer'], $offer->data['title']);

        JsonData::fill($offer, ['title' => ['en' => 'New offer']]);
        $this->assertSame(['fr' => 'Nouvelle offre', 'en' => 'New offer'], $offer->data['title']);
    }

    public function test_the_default_locale_comes_from_the_config()
    {
        config(['json-data.default_locale' => 'en']);

        $offer = $this->filledOffer(['title' => 'Offer']);

        $this->assertSame(['en' => 'Offer'], $offer->data['title']);
    }

    public function test_a_locale_that_is_not_configured_is_refused()
    {
        $this->expectException(InvalidJsonData::class);
        $this->expectExceptionMessage('[de] at [data.title]');

        JsonData::fill(new Offer, ['title' => ['de' => 'Angebot']]);
    }

    public function test_enums_and_dates_are_stored_as_their_values()
    {
        Offer::$schema = fn () => Json::object([
            'status' => Json::enum(OfferStatus::class),
            'kind' => Json::enum(['event', 'product']),
            'starts_on' => Json::date(),
            'starts_at' => Json::datetime(),
        ]);

        $offer = $this->filledOffer([
            'status' => OfferStatus::Published,
            'kind' => 'event',
            'starts_on' => Carbon::parse('2026-11-01 10:00:00', 'UTC'),
            'starts_at' => Carbon::parse('2026-11-01 10:00:00', 'UTC'),
        ]);

        $this->assertEquals([
            'status' => 'published',
            'kind' => 'event',
            'starts_on' => '2026-11-01',
            'starts_at' => '2026-11-01T10:00:00+00:00',
        ], $this->rawData($offer));
    }

    public function test_defaults_are_written_for_the_paths_that_are_missing()
    {
        $prize = $this->prize('grand-prize');

        $offer = $this->filledOffer(['prizes' => [['prize' => $prize], ['prize' => $prize, 'quantity' => 3]]]);

        $raw = $this->rawData($offer);
        $this->assertSame('draft', $raw['status']);
        $this->assertSame([1, 3], array_column($raw['prizes'], 'quantity'));

        JsonData::fill($offer, ['status' => null]);
        $this->assertNull($offer->data['status']);
    }

    public function test_defaults_are_not_written_into_a_stored_model_that_the_input_does_not_fill()
    {
        $offer = $this->storedOffer(['tagline' => 'Win']);

        JsonData::fill($offer, ['handle' => 'other']);

        $this->assertFalse($offer->isDirty('data'));
    }

    public function test_unknown_keys_are_refused_when_the_schema_does_not_allow_them()
    {
        $offer = $this->filledOffer(['subtitle' => 'Win']);

        try {
            JsonData::fill($offer, ['subtitle' => 'Lose', 'handle' => 'other', 'colour' => 'red', 'size' => 'xl']);
            $this->fail('The unknown keys were not refused.');
        } catch (InvalidJsonData $exception) {
            $this->assertStringContainsString('[colour, size]', $exception->getMessage());
        }

        // Nothing is filled
        $this->assertSame('Win', $offer->data['tagline']);
    }

    public function test_unknown_keys_of_a_nested_object_are_refused_when_it_does_not_allow_them()
    {
        Offer::$schema = fn () => Json::object([
            'seo' => Json::object(['title' => Json::string()])->additional(false),
            'extra' => Json::object(['title' => Json::string()])->additional(),
        ]);
        $offer = $this->filledOffer(['extra' => ['title' => 'Extra', 'colour' => 'red']]);
        $this->assertSame(['title' => 'Extra', 'colour' => 'red'], $offer->data['extra']);

        $this->expectException(InvalidJsonData::class);
        $this->expectExceptionMessage('[data.seo.colour]');

        JsonData::fill($offer, ['seo' => ['title' => 'SEO', 'colour' => 'red']]);
    }

    public function test_unknown_keys_are_ignored_when_the_schema_allows_them()
    {
        Offer::$schema = fn () => Json::object(['tagline' => Json::string()]);

        $offer = $this->filledOffer(['tagline' => 'Win', 'colour' => 'red']);

        $this->assertSame(['tagline' => 'Win'], $this->rawData($offer));
    }

    public function test_unknown_keys_are_refused_by_default_under_enforce_map()
    {
        JsonData::enforceMap(['medias' => Media::class]);
        Offer::$schema = fn () => Json::object([
            'tagline' => Json::string(),
            'seo' => Json::object(['title' => Json::string()]),
        ]);

        try {
            JsonData::fill(new Offer, ['colour' => 'red']);
            $this->fail('The unknown key was not refused.');
        } catch (InvalidJsonData) {
        }

        $this->expectException(InvalidJsonData::class);
        $this->expectExceptionMessage('[data.seo.colour]');

        JsonData::fill(new Offer, ['seo' => ['colour' => 'red']]);
    }

    public function test_the_locales_come_from_a_resolver_bound_in_the_container()
    {
        $this->app->bind(LocaleResolver::class, fn () => new class implements LocaleResolver
        {
            public function locales(): array
            {
                return ['en', 'es'];
            }

            public function defaultLocale(): string
            {
                return 'es';
            }
        });

        $offer = new Offer;
        JsonData::fill($offer, ['title' => 'Oferta']);

        $this->assertSame(['es' => 'Oferta'], $offer->data['title']);
    }

    public function test_json_is_macroable()
    {
        Json::macro('media', fn () => Json::reference('medias')->relation('medias'));
        Offer::$schema = fn () => Json::object(['logo' => Json::media()]);
        $media = $this->makeMedia();

        $offer = $this->filledOffer(['logo' => $media]);

        $this->assertSame('medias://'.$media->id, $this->rawData($offer)['logo']);
        $this->assertSame([$media->id], $offer->medias()->pluck('medias.id')->all());
    }

    public function test_a_model_without_a_schema_is_not_filled()
    {
        $prize = $this->prize('grand-prize');

        JsonData::fill($prize, ['colour' => 'red']);

        $this->assertFalse($prize->isDirty());
    }
}
