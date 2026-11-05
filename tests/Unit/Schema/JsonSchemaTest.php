<?php

namespace Folklore\JsonData\Tests\Unit\Schema;

use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Tests\Feature\Fixtures\Offer;
use Folklore\JsonData\Tests\Feature\Fixtures\OfferStatus;
use Folklore\JsonData\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

/**
 * `toJsonSchema()`: a JSON Schema (draft 2020-12) of a node's stored value (docs/json-data.md,
 * "JSON Schema").
 */
class JsonSchemaTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('json-data.locales', ['fr', 'en']);
    }

    public function test_scalars_carry_their_bounds_formats_and_values()
    {
        $this->assertSame(
            ['type' => 'string', 'minLength' => 2, 'maxLength' => 120, 'pattern' => '^[a-z-]+$', 'x-pattern-modifiers' => 'i'],
            Json::string()->min(2)->max(120)->pattern('/^[a-z-]+$/i')->toJsonSchema(),
        );
        $this->assertSame(['type' => 'string', 'pattern' => '^a#b$'], Json::string()->pattern('~^a#b$~')->toJsonSchema());
        $this->assertSame(['type' => 'integer', 'minimum' => 0], Json::integer()->min(0)->toJsonSchema());
        $this->assertSame(['type' => 'number', 'maximum' => 1], Json::number()->max(1)->toJsonSchema());
        $this->assertSame(['type' => 'boolean'], Json::boolean()->toJsonSchema());
        $this->assertSame(['type' => 'string', 'format' => 'date'], Json::date()->toJsonSchema());
        $this->assertSame(['type' => 'string', 'format' => 'date-time'], Json::datetime()->toJsonSchema());
        $this->assertSame(['enum' => ['draft', 'published']], Json::enum(OfferStatus::class)->toJsonSchema());
    }

    public function test_a_default_is_stored_as_it_would_be_and_null_joins_the_type()
    {
        $this->assertSame(
            ['enum' => ['draft', 'published', null], 'default' => 'draft'],
            Json::enum(OfferStatus::class)->default(OfferStatus::Draft)->nullable()->toJsonSchema(),
        );
        $this->assertSame(['type' => ['integer', 'null'], 'default' => 1], Json::integer()->default(1)->nullable()->toJsonSchema());
        $this->assertSame(
            ['anyOf' => [['oneOf' => [['type' => 'object', 'properties' => ['type' => ['const' => 'a']], 'required' => ['type'], 'additionalProperties' => true]], 'x-discriminator' => 'type'], ['type' => 'null']]],
            Json::union('type', ['a' => Json::object()])->nullable()->toJsonSchema(),
        );
    }

    public function test_a_reference_is_a_string_and_a_translatable_value_an_object_by_locale()
    {
        $this->assertSame(
            ['type' => 'string', 'pattern' => '^medias://.+$', 'x-reference' => ['key' => 'medias', 'relation' => 'medias']],
            Json::reference('medias')->relation('medias')->toJsonSchema(),
        );
        $this->assertSame(
            ['type' => 'string', 'pattern' => '^[^:/]+://.+$', 'x-reference' => ['relation' => 'authors']],
            Json::relation('authors')->toJsonSchema(),
        );
        $this->assertSame(
            [
                'type' => 'object',
                'properties' => ['fr' => ['type' => 'string', 'maxLength' => 80], 'en' => ['type' => 'string', 'maxLength' => 80]],
                'additionalProperties' => false,
                'x-translatable' => true,
            ],
            Json::string()->max(80)->translatable()->toJsonSchema(),
        );
        $this->assertSame(['de'], array_keys(Json::string()->translatable()->toJsonSchema(['de'])['properties']));
    }

    public function test_objects_lists_maps_unions_and_documents()
    {
        $this->assertSame(
            [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5]],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            Json::object([
                'name' => Json::string()->required(),
                'tags' => Json::list(Json::string())->max(5),
            ])->additional(false)->toJsonSchema(),
        );
        $this->assertSame(
            ['type' => 'object', 'additionalProperties' => ['type' => 'boolean'], 'minProperties' => 1],
            Json::map(Json::boolean())->min(1)->toJsonSchema(),
        );
        $this->assertSame(
            [
                'oneOf' => [
                    ['type' => 'object', 'properties' => ['type' => ['const' => 'video'], 'url' => ['type' => 'string']], 'required' => ['type', 'url'], 'additionalProperties' => false],
                    ['type' => 'object', 'properties' => ['type' => ['const' => 'text']], 'required' => ['type'], 'additionalProperties' => true],
                ],
                'x-discriminator' => 'type',
            ],
            Json::union('type', [
                'video' => Json::object(['url' => Json::string()->required()])->additional(false),
                'text' => Json::object(),
            ])->toJsonSchema(),
        );
        $this->assertSame(
            ['x-document' => true, 'x-references' => [['key' => 'medias', 'relation' => 'medias'], ['key' => 'prize']]],
            Json::document()->references(Json::reference('medias')->relation('medias'), 'prize')->toJsonSchema(),
        );
        $this->assertSame([], Json::any()->toJsonSchema());
        $this->assertSame(
            ['x-paths' => ['*.blocks.*.media' => ['key' => 'medias']]],
            Json::any()->paths(['*.blocks.*.media' => Json::reference('medias')])->toJsonSchema(),
        );
    }

    public function test_a_column_gets_the_dialect_and_its_whole_schema()
    {
        $schema = Json::toJsonSchema(new Offer, 'data');

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertTrue($schema['properties']['title']['x-translatable']);
        $this->assertSame('^prize://.+$', $schema['properties']['prizes']['items']['properties']['prize']['pattern']);
        $this->assertSame(1, $schema['properties']['prizes']['items']['properties']['quantity']['default']);
        $this->assertSame('draft', $schema['properties']['status']['default']);
        $this->assertSame(['type' => 'object', 'additionalProperties' => ['type' => 'boolean']], $schema['properties']['settings']);
        $this->assertSame(json_decode(json_encode($schema), true), $schema, 'The schema encodes to JSON as it is.');
    }

    public function test_a_column_without_a_schema_is_refused()
    {
        $this->expectException(InvalidJsonData::class);

        Json::toJsonSchema(new class extends Model {}, 'data');
    }
}
