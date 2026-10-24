<?php

namespace Folklore\JsonData\Tests\Unit\Support;

use Folklore\JsonData\Support\Paths;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * The path patterns of the cast's declarations (docs/json-data.md, "Paths").
 */
class PathsTest extends TestCase
{
    public function test_an_exact_path_matches_only_itself()
    {
        $data = ['logo' => 'medias://1', 'share' => ['image' => 'medias://2', 'title' => 'Share']];

        $this->assertSame(['logo'], Paths::matching(['logo'], $data));
        $this->assertSame(['share.image'], Paths::matching(['share.image'], $data));
        $this->assertSame([], Paths::matching(['missing'], $data));
    }

    public function test_a_wildcard_matches_one_segment()
    {
        $data = ['image' => ['fr' => 'medias://1', 'en' => ['nested' => 'medias://2']]];

        $this->assertSame(['image.fr', 'image.en'], Paths::matching(['image.*'], $data));
        $this->assertSame(['image.en.nested'], Paths::matching(['image.*.nested'], $data));
    }

    public function test_wildcards_match_the_items_of_lists()
    {
        $data = ['questions' => [
            ['choices' => [['image' => 'medias://1'], ['label' => 'B']]],
            ['choices' => [['image' => 'medias://2']]],
        ]];

        $this->assertSame(
            ['questions.0.choices.0.image', 'questions.1.choices.0.image'],
            Paths::matching(['questions.*.choices.*.image'], $data),
        );
    }

    public function test_a_recursive_wildcard_matches_every_path_under_it_branches_included()
    {
        $data = ['body' => ['type' => 'doc', 'content' => [['ref' => 'prize://a']]], 'title' => 'Quiz'];

        $this->assertSame(
            ['body.type', 'body.content', 'body.content.0', 'body.content.0.ref'],
            Paths::matching(['body.**'], $data),
        );
    }

    public function test_a_recursive_wildcard_doesnt_match_its_prefix_itself()
    {
        $this->assertSame([], Paths::matching(['body.**'], ['body' => 'text']));
    }

    public function test_the_rest_of_a_pattern_matches_literally()
    {
        $data = ['a.b' => 1, 'aXb' => 2, 'a+b' => 3, 'aab' => 4];

        $this->assertSame([], Paths::matching(['a.b'], ['aXb' => 1]));
        $this->assertSame(['a+b'], Paths::matching(['a+b'], $data));
        $this->assertSame('/^logo\.\(fr\)$/', Paths::expression('logo.(fr)'));
    }

    public function test_a_path_that_several_patterns_match_is_given_once()
    {
        $data = ['image' => ['fr' => 'medias://1', 'en' => 'medias://2']];

        $this->assertSame(['image.fr', 'image.en'], Paths::matching(['image.*', 'image.fr'], $data));
        $this->assertSame(['image', 'image.fr', 'image.en'], Paths::matching(['image.*', '**'], $data));
    }

    public function test_the_keys_of_lists_and_of_objects_keyed_by_integers_are_kept()
    {
        $this->assertSame([0, 1], Paths::matching(['*'], ['a', 'b']));

        // 1.x renumbered the integer keys of branches: these were 0 and 1
        $this->assertSame([12, 40], Paths::matching(['*'], [12 => ['title' => 'a'], 40 => ['title' => 'b']]));
        $this->assertSame(['12.title', '40.title'], Paths::matching(['*.title'], [12 => ['title' => 'a'], 40 => ['title' => 'b']]));
    }

    public function test_empty_branches_are_leaves()
    {
        $data = ['gallery' => [], 'blocks' => new Collection];

        $this->assertSame(['gallery', 'blocks'], Paths::matching(['*'], $data));
        $this->assertSame([], Paths::matching(['gallery.*'], $data));
    }

    public function test_collections_and_arrayable_values_are_walked_as_arrays()
    {
        $model = new class implements Arrayable
        {
            public function toArray(): array
            {
                return ['id' => 12, 'name' => 'Folklore'];
            }
        };
        $data = ['image' => ['fr' => $model], 'blocks' => new Collection(['blocks://1'])];

        $this->assertSame(['image.fr.id', 'image.fr.name'], Paths::matching(['image.*.*'], $data));
        $this->assertSame(['blocks.0'], Paths::matching(['blocks.*'], $data));
        $this->assertSame([0], Paths::matching(['*'], new Collection(['a'])));
    }

    public function test_data_that_isnt_iterable_has_no_paths()
    {
        $this->assertSame([], Paths::matching(['*', '**'], null));
        $this->assertSame([], Paths::matching(['*'], 'medias://1'));
    }

    public function test_reduce_gives_each_matching_path_with_the_data_so_far()
    {
        $data = ['image' => ['fr' => 'medias://1', 'en' => 'medias://2'], 'title' => 'Quiz'];
        $calls = [];

        $result = Paths::reduce(['image.*'], $data, function (mixed $current, int|string $path, mixed $value) use (&$calls) {
            $calls[] = [$path, $value, $current['image']['fr']];
            data_set($current, $path, strtoupper($value));

            return $current;
        });

        $this->assertSame([
            ['image.fr', 'medias://1', 'medias://1'],
            ['image.en', 'medias://2', 'MEDIAS://1'],
        ], $calls);
        $this->assertSame(['image' => ['fr' => 'MEDIAS://1', 'en' => 'MEDIAS://2'], 'title' => 'Quiz'], $result);
    }

    public function test_reduce_visits_only_the_paths_of_the_data_it_is_given()
    {
        $visited = [];

        $result = Paths::reduce(['image.*'], ['image' => ['fr' => 1]], function (mixed $current, int|string $path) use (&$visited) {
            $visited[] = $path;
            data_set($current, 'image.en', 2);

            return $current;
        });

        $this->assertSame(['image.fr'], $visited);
        $this->assertSame(['image' => ['fr' => 1, 'en' => 2]], $result);
    }

    public function test_reduce_returns_an_array_when_nothing_matches()
    {
        $called = false;
        $reducer = function (mixed $current) use (&$called) {
            $called = true;

            return $current;
        };

        $this->assertSame([], Paths::reduce(['image.*'], null, $reducer));
        $this->assertSame(['title' => 'Quiz'], Paths::reduce(['image.*'], ['title' => 'Quiz'], $reducer));
        $this->assertFalse($called);
    }
}
