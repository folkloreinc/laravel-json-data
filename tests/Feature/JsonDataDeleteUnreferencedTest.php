<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\Exceptions\InvalidJsonReference;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Tests\Feature\Fixtures\Contest;
use Folklore\JsonData\Tests\Feature\Fixtures\Offer;
use Folklore\JsonData\Tests\Feature\Fixtures\Page;
use Folklore\JsonData\Tests\Feature\Fixtures\Prize;
use Folklore\JsonData\Tests\Feature\Fixtures\SchemaContest;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `deletesUnreferenced()`, the `delete` option of 1.x on a reference node: the sync deletes
 * the rows of a relation that owns them when the JSON no longer references them
 * (docs/json-data.md, "Syncing", #26). The cases run on a contest with a schema and on its
 * twin declared through the hook.
 */
class JsonDataDeleteUnreferencedTest extends JsonDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SchemaContest::$schema = fn () => Json::object([
            'featured' => Json::list(Json::relation('prizes')->deletesUnreferenced()),
        ]);
        Contest::$featuredOptions = ['delete' => true];
    }

    protected function tearDown(): void
    {
        SchemaContest::$schema = null;
        Contest::$featuredOptions = [];
        Page::$parentOptions = [];
        Offer::$schema = null;

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string<Contest>}>
     */
    public static function contests(): array
    {
        return ['a schema' => [SchemaContest::class], 'a 1.x declaration' => [Contest::class]];
    }

    public function test_the_option_compiles_into_the_delete_option_of_a_declaration()
    {
        $this->assertSame(
            [['path' => 'featured.*', 'relation' => 'prizes', 'delete' => true]],
            JsonData::declarations(new SchemaContest, 'data', null, [])['references'],
        );
    }

    public function test_the_option_needs_a_relation()
    {
        Offer::$schema = fn () => Json::object(['source' => Json::reference('medias')->deletesUnreferenced()]);

        $this->expectException(InvalidJsonData::class);

        JsonData::declarations(new Offer, 'data', null, []);
    }

    /**
     * @param  class-string<Contest>  $class
     */
    #[DataProvider('contests')]
    public function test_the_sync_deletes_the_rows_no_longer_referenced_and_keeps_the_others($class)
    {
        $contest = $class::create();
        $kept = Prize::create(['contest_id' => $contest->id, 'handle' => 'kept']);
        $dropped = Prize::create(['contest_id' => $contest->id, 'handle' => 'dropped']);
        $orphan = Prize::create(['handle' => 'orphan']);
        $other = Prize::create(['contest_id' => Contest::create()->id, 'handle' => 'other']);

        $contest->data = ['featured' => [$kept, $orphan]];
        $contest->save();

        $this->assertNotNull($kept->fresh());
        $this->assertNull($dropped->fresh());
        $this->assertEquals($contest->id, $orphan->fresh()?->contest_id);
        $this->assertNotNull($other->fresh());
    }

    /**
     * @param  class-string<Contest>  $class
     */
    #[DataProvider('contests')]
    public function test_the_dry_run_of_the_sync_command_reports_the_rows_to_delete($class)
    {
        $contest = $class::create();
        $kept = Prize::create(['contest_id' => $contest->id, 'handle' => 'kept']);
        $dropped = Prize::create(['contest_id' => $contest->id, 'handle' => 'dropped']);
        DB::table('contests')->where('id', $contest->id)->update(['data' => json_encode(['featured' => ['prizes://'.$kept->id]])]);

        $this->artisan('json-data:sync-references', ['--model' => [$class], '--dry-run' => true, '-v' => true])
            ->expectsOutputToContain('delete '.$dropped->id)
            ->expectsTable(
                ['Model', 'Rows', 'Rows to change', 'To attach', 'To detach', 'To delete', 'Synced by callbacks, not compared', 'Unresolved references'],
                [[$class, 1, 1, 0, 0, 1, 0, 0]],
            )
            ->assertSuccessful();

        $this->assertNotNull($dropped->fresh());
    }

    public function test_the_option_is_refused_on_a_relation_that_doesnt_own_its_rows()
    {
        Offer::$schema = fn () => Json::object(['prize' => Json::relation('prizes')->deletesUnreferenced()]);
        $offer = new Offer(['handle' => 'offer']);
        $offer->data = ['prize' => Prize::create(['handle' => 'grand-prize'])];

        $this->expectException(InvalidJsonReference::class);
        $this->expectExceptionMessage("doesn't own its rows");

        $offer->save();
    }

    public function test_the_option_is_refused_on_a_belongs_to()
    {
        Page::$parentOptions = ['delete' => true];
        $parent = Page::create(['handle' => 'parent']);

        $this->expectException(InvalidJsonReference::class);

        Page::create(['handle' => 'child', 'data' => ['parent' => $parent]]);
    }
}
