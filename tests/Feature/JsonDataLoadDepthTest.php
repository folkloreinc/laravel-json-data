<?php

namespace Folklore\JsonData\Tests\Feature;

use Folklore\JsonData\JsonData;
use Folklore\JsonData\Tests\Feature\Fixtures\Media;
use Folklore\JsonData\Tests\Feature\Fixtures\NestedBlock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * JsonData::load() with a depth: the models that references resolve to are loaded in
 * turn, as the pages of a 1.x site load their blocks, the blocks' blocks and their media
 * (docs/json-data.md, "Batch resolution", #24).
 */
class JsonDataLoadDepthTest extends JsonDataTestCase
{
    /**
     * Two pages, each with a block that holds a block with an image.
     *
     * @return array{Collection<int, NestedBlock>, list<Media>}
     */
    private function pages(): array
    {
        $pages = [];
        $images = [];
        foreach (['first', 'second'] as $name) {
            $image = Media::create(['name' => $name]);
            $leaf = $this->block(['image' => ['fr' => $image]]);
            $middle = $this->block(['blocks' => [$leaf]]);
            $pages[] = $this->block(['blocks' => [$middle]], 'page')->getKey();
            $images[] = $image;
        }

        return [NestedBlock::query()->whereKey($pages)->orderBy('id')->get(), $images];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function block(array $data, string $type = 'block'): NestedBlock
    {
        $block = new NestedBlock(['type' => $type]);
        $block->data = $data;
        $block->save();

        return $block;
    }

    /**
     * @return list<string>
     */
    private function queriesOf(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return array_map(fn (array $query) => (string) $query['query'], DB::getQueryLog());
    }

    private function image(NestedBlock $page): mixed
    {
        return $page->data['blocks'][0]->data['blocks'][0]->data['image']['fr'];
    }

    public function test_a_depth_loads_the_models_that_the_references_resolve_to()
    {
        [$pages, $images] = $this->pages();

        $loading = $this->queriesOf(fn () => JsonData::load($pages, depth: 3));
        $reading = $this->queriesOf(function () use ($pages, &$read) {
            $read = [$this->image($pages[0]), $this->image($pages[1])];
        });

        $this->assertSame([], $reading);
        $this->assertTrue($read[0]->is($images[0]));
        $this->assertTrue($read[1]->is($images[1]));
        // One query per level for the blocks of an unmapped key, one for the media of both pages
        $this->assertCount(2, array_filter($loading, fn (string $sql) => str_contains($sql, 'nested_blocks_pivot')));
        $this->assertCount(1, array_filter($loading, fn (string $sql) => preg_match('/from [`"]?medias[`"]?/', $sql) === 1));
    }

    public function test_the_default_depth_loads_the_given_models_only()
    {
        [$pages, $images] = $this->pages();

        JsonData::load($pages);
        $reading = $this->queriesOf(fn () => $this->image($pages[0]));

        $this->assertNotSame([], $reading);
        $this->assertTrue($this->image($pages[0])->is($images[0]));
    }

    public function test_a_model_reached_at_several_levels_reads_without_a_query()
    {
        $image = Media::create(['name' => 'shared']);
        $shared = $this->block(['image' => ['fr' => $image]]);
        $page = $this->block(['blocks' => [$shared, $this->block(['blocks' => [$shared]])]], 'page');
        $pages = NestedBlock::query()->whereKey($page->getKey())->get();

        JsonData::load($pages, depth: 5);
        $reading = $this->queriesOf(function () use ($pages, $image) {
            $this->assertTrue($pages[0]->data['blocks'][0]->data['image']['fr']->is($image));
            $this->assertTrue($pages[0]->data['blocks'][1]->data['blocks'][0]->data['image']['fr']->is($image));
        });

        $this->assertSame([], $reading);
    }

    public function test_the_depth_is_at_least_one()
    {
        $this->expectException(InvalidArgumentException::class);

        JsonData::load([], depth: 0);
    }
}
