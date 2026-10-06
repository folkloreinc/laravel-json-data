<?php

namespace Folklore\Tests\Unit\Eloquent;

use Folklore\Eloquent\JsonDataCast;
use PHPUnit\Framework\TestCase;

class JsonDataCastPathsTest extends TestCase
{
    public function test_get_relation_and_id_from_path()
    {
        $this->assertSame(['medias', '12'], JsonDataCast::getRelationAndIdFromPath('medias://12'));
        $this->assertSame(['blocks', 'abc'], JsonDataCast::getRelationAndIdFromPath('blocks://abc'));
    }

    public function test_get_relation_and_id_from_invalid_path()
    {
        $this->assertNull(JsonDataCast::getRelationAndIdFromPath('medias:/12'));
        $this->assertNull(JsonDataCast::getRelationAndIdFromPath('12'));
        $this->assertNull(JsonDataCast::getRelationAndIdFromPath(''));
        $this->assertNull(JsonDataCast::getRelationAndIdFromPath(null));
        $this->assertNull(JsonDataCast::getRelationAndIdFromPath(['id' => 1]));
    }

    public function test_get_path_from_item()
    {
        $this->assertSame('medias://12', JsonDataCast::getPathFromItem(12, 'medias'));
        $this->assertSame('blocks://5', JsonDataCast::getPathFromItem(['id' => 5], 'blocks'));
        $this->assertNull(JsonDataCast::getPathFromItem(null, 'medias'));
        $this->assertNull(JsonDataCast::getPathFromItem(['name' => 'no id'], 'medias'));
    }

    public function test_normalize_merges_paths_of_the_same_relation()
    {
        $relations = JsonDataCast::normalizeJsonDataRelations([
            'image' => 'medias',
            'images.*' => 'medias',
            'blocks.*' => 'blocks',
        ]);

        $this->assertSame(
            [
                ['relation' => 'medias', 'path' => ['image', 'images.*'], 'lazy' => false],
                ['relation' => 'blocks', 'path' => ['blocks.*'], 'lazy' => false],
            ],
            $relations->all(),
        );
    }

    public function test_normalize_keeps_relations_with_different_options_apart()
    {
        $relations = JsonDataCast::normalizeJsonDataRelations([
            'image' => 'medias',
            'cover' => ['relation' => 'medias', 'lazy' => true],
            'parent' => ['relation' => 'parent', 'sync' => false],
        ]);

        $this->assertSame(
            [
                ['relation' => 'medias', 'path' => ['image'], 'lazy' => false],
                ['path' => ['cover'], 'relation' => 'medias', 'lazy' => true],
                ['path' => ['parent'], 'relation' => 'parent', 'sync' => false],
            ],
            $relations->all(),
        );
    }

    public function test_normalize_accepts_an_explicit_list_of_paths()
    {
        $relations = JsonDataCast::normalizeJsonDataRelations([
            ['relation' => 'medias', 'path' => ['image', 'gallery.*']],
        ]);

        $this->assertSame(
            [['path' => ['image', 'gallery.*'], 'relation' => 'medias']],
            $relations->all(),
        );
    }
}
