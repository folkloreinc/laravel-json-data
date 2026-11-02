<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\Exceptions\InvalidJsonReference;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\JsonReferenceEntry;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Tests\Feature\Fixtures\LegacyOffer;
use Folklore\JsonData\Tests\Feature\Fixtures\Offer;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Folklore\JsonData\Tests\Feature\Fixtures\PrizesExceptSecret;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `Json::relation()`: a reference node named by its relation, without a key, which does what
 * the same declaration of laravel-folklore 1.x does (docs/json-data.md, "References", #25).
 * Each case runs on an Offer with a schema and on its twin declared through the hook.
 */
class JsonDataRelationNodeTest extends JsonDataTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('json-data.locales', ['fr', 'en']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // `prizes` writes the first key of its model; `blocks` has no key, as on 1.1 sites
        JsonData::map(['prize' => [Prize::class, 'handle'], 'prizeid' => Prize::class]);
        Offer::$schema = fn () => Json::object([
            'credits' => Json::list(Json::object([
                'block' => Json::relation('blocks'),
                'role' => Json::string(),
            ])),
            'prize' => Json::relation('prizes'),
        ]);
        LegacyOffer::$references = ['credits.*.block' => 'blocks', 'prize' => 'prizes'];
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

    /**
     * @param  class-string<Offer>  $class
     * @param  array<string, mixed>  $data
     */
    private function storedOffer(string $class, array $data): Offer
    {
        $offer = $class::create(['handle' => 'offer']);
        DB::table('offers')->where('id', $offer->id)->update(['data' => json_encode($data)]);

        return $class::findOrFail($offer->id);
    }

    public function test_a_relation_node_compiles_into_the_declaration_of_1_x()
    {
        $this->assertSame([
            ['path' => 'credits.*.block', 'relation' => 'blocks'],
            ['path' => 'prize', 'relation' => 'prizes'],
        ], JsonData::declarations(new Offer, 'data', null, [])['references']);
    }

    /**
     * @param  class-string<Offer>  $class
     */
    #[DataProvider('models')]
    public function test_it_writes_the_first_mapped_key_else_the_relation_s_name_and_syncs($class)
    {
        $block = $this->makeBlock();
        $prize = Prize::create(['handle' => 'grand-prize']);

        $offer = new $class(['handle' => 'offer']);
        $offer->data = ['credits' => [['block' => $block, 'role' => 'host']], 'prize' => $prize];
        $offer->save();

        $raw = $this->rawData($offer);
        $this->assertSame('blocks://'.$block->id, $raw['credits'][0]['block']);
        $this->assertSame('prize://grand-prize', $raw['prize']);
        $this->assertSame([$block->id], $offer->blocks()->pluck('blocks.id')->all());
        $this->assertSame([$prize->id], $offer->prizes()->pluck('prizes.id')->all());

        $fresh = $class::findOrFail($offer->id);
        $this->assertTrue($fresh->data['credits'][0]['block']->is($block));
        $this->assertTrue($fresh->data['prize']->is($prize));
        $this->assertEqualsCanonicalizing(
            [['blocks', 'credits.0.block', 'blocks'], ['prize', 'prize', 'prizes']],
            JsonData::references($fresh)->map(fn (JsonReferenceEntry $entry) => [$entry->key, $entry->path, $entry->relation])->all(),
        );
    }

    /**
     * @param  class-string<Offer>  $class
     */
    #[DataProvider('models')]
    public function test_an_unmapped_key_resolves_only_through_the_owner_s_relation($class)
    {
        $block = $this->makeBlock();
        $offer = $this->storedOffer($class, ['credits' => [['block' => 'blocks://'.$block->id]]]);

        $this->assertNull($offer->data['credits'][0]['block']);
        $plan = JsonDataCast::syncRelations($offer, pretend: true);
        $this->assertSame([$block->id], $plan['blocks']['attach'] ?? null);

        JsonDataCast::syncRelations($offer);

        $this->assertTrue($class::findOrFail($offer->id)->data['credits'][0]['block']->is($block));
    }

    /**
     * @param  class-string<Offer>  $class
     */
    #[DataProvider('models')]
    public function test_a_key_mapped_later_reads_by_its_column($class)
    {
        $prize = Prize::create(['handle' => 'grand-prize']);
        $offer = $this->storedOffer($class, ['prize' => 'prizeid://'.$prize->id]);

        // Not attached: a mapped key resolves by its column, attached or not
        $this->assertSame(0, $offer->prizes()->count());
        $this->assertTrue($offer->data['prize']->is($prize));
    }

    /**
     * @param  class-string<Offer>  $class
     */
    #[DataProvider('models')]
    public function test_under_enforce_map_an_unmapped_related_model_cant_be_written($class)
    {
        JsonData::enforceMap();

        $offer = new $class(['handle' => 'offer']);
        $offer->data = ['credits' => [['block' => $this->makeBlock()]]];

        $this->expectException(InvalidJsonReference::class);

        $offer->save();
    }

    public function test_the_rule_checks_the_key_it_writes_or_the_related_models_of_an_unmapped_one()
    {
        $block = $this->makeBlock();
        Prize::create(['handle' => 'grand-prize']);
        $errors = fn (array $input) => array_keys(Validator::make($input, Json::rules(new Offer, 'data'))->errors()->toArray());

        $this->assertSame([], $errors(['credits' => [['block' => $block->id], ['block' => 'blocks://'.$block->id], ['block' => $block]], 'prize' => 'grand-prize']));
        $this->assertEqualsCanonicalizing(['credits.0.block', 'prize'], $errors(['credits' => [['block' => 999]], 'prize' => 'missing']));
        $this->assertSame(['credits.0.block'], $errors(['credits' => [['block' => 'prize://grand-prize']]]));
    }

    public function test_under_enforce_map_the_rule_refuses_an_unmapped_related_model()
    {
        JsonData::enforceMap();

        $validator = Validator::make(['credits' => [['block' => $this->makeBlock()->id]]], Json::rules(new Offer, 'data'));

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('can\'t be checked', (string) $validator->errors()->first('credits.0.block'));
    }

    public function test_a_relation_node_has_no_handler_of_its_own()
    {
        $this->expectException(InvalidJsonData::class);

        Json::relation('prizes')->using(PrizesExceptSecret::class);
    }

    public function test_a_document_s_references_have_a_key()
    {
        $this->expectException(InvalidJsonData::class);

        Json::document()->references(Json::relation('prizes'));
    }
}
