<?php

namespace Folklore\JsonData\Tests\Unit\Schema;

use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\Schema\Compiler;
use Folklore\JsonData\Schema\ConfigLocaleResolver;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Schema\ObjectType;
use Folklore\JsonData\Tests\Feature\Fixtures\OfferStatus;
use Folklore\JsonData\Tests\TestCase;

/**
 * The nodes of the schema, what they compile into, and the locales of the config
 * (docs/json-data.md, "Schema").
 */
class JsonTest extends TestCase
{
    public function test_nodes_keep_their_options()
    {
        $name = Json::string()->translatable()->required()->nullable()->max(120)->min(2)->pattern('/^[a-z]+$/')->input('title')->extract('name_{locale}');

        $this->assertTrue($name->isTranslatable());
        $this->assertTrue($name->isRequired());
        $this->assertTrue($name->isNullable());
        $this->assertSame(2, $name->getMin());
        $this->assertSame(120, $name->getMax());
        $this->assertSame('/^[a-z]+$/', $name->getPattern());
        $this->assertSame('title', $name->getInput());
        $this->assertSame('name_{locale}', $name->getExtract());
        $this->assertFalse(Json::integer()->hasDefault());
        $this->assertNull(Json::integer()->default(null)->getDefault());
        $this->assertTrue(Json::integer()->default(null)->hasDefault());
    }

    public function test_an_enum_is_a_backed_enum_or_a_list_of_values()
    {
        $this->assertSame(['draft', 'published'], Json::enum(OfferStatus::class)->getValues());
        $this->assertSame(OfferStatus::class, Json::enum(OfferStatus::class)->getEnum());
        $this->assertSame(['a', 'b'], Json::enum(['x' => 'a', 'y' => 'b'])->getValues());

        $this->expectException(InvalidJsonData::class);
        Json::enum(self::class);
    }

    public function test_objects_allow_additional_keys_unless_the_map_is_enforced()
    {
        $this->assertTrue(Json::object()->allowsAdditional());
        $this->assertFalse(Json::object()->additional(false)->allowsAdditional());

        JsonData::enforceMap();
        $this->assertFalse(Json::object()->allowsAdditional());
        $this->assertTrue(Json::object()->additional()->allowsAdditional());
    }

    public function test_a_property_name_has_no_dot_or_wildcard()
    {
        $this->expectException(InvalidJsonData::class);

        Json::object(['seo.title' => Json::string()]);
    }

    public function test_a_node_under_a_list_is_not_read_from_an_input_key()
    {
        $this->expectException(InvalidJsonData::class);
        $this->expectExceptionMessage('[items.*.title]');

        Compiler::compile(Json::object([
            'items' => Json::list(Json::object(['title' => Json::string()->input('title')])),
        ]));
    }

    public function test_a_node_under_a_map_is_not_extracted()
    {
        $this->expectException(InvalidJsonData::class);

        Compiler::compile(Json::object(['items' => Json::map(Json::string()->extract('item'))]));
    }

    public function test_a_translatable_node_is_extracted_per_locale()
    {
        $this->expectException(InvalidJsonData::class);
        $this->expectExceptionMessage('{locale}');

        Compiler::compile(Json::object(['title' => Json::string()->translatable()->extract('title')]));
    }

    public function test_two_nodes_are_not_read_from_the_same_input_key()
    {
        $this->expectException(InvalidJsonData::class);

        Compiler::compile(Json::object([
            'title' => Json::string(),
            'seo' => Json::object(['title' => Json::string()->input('title')]),
        ]));
    }

    public function test_two_nodes_are_not_extracted_into_the_same_column()
    {
        $this->expectException(InvalidJsonData::class);

        Compiler::compile(Json::object([
            'title' => Json::string()->extract('title'),
            'name' => Json::string()->extract('title'),
        ]));
    }

    public function test_the_inputs_are_the_root_properties_and_the_input_names()
    {
        $compiled = Compiler::compile(Json::object([
            'title' => Json::string()->input('name'),
            'tagline' => Json::string(),
            'seo' => Json::object([
                'title' => Json::string()->input('seo_title'),
                'description' => Json::string(),
            ]),
        ]));

        $this->assertSame(
            ['name' => ['title'], 'tagline' => ['tagline'], 'seo' => ['seo'], 'seo_title' => ['seo', 'title']],
            array_map(fn (array $input) => $input[0], $compiled['inputs']),
        );
    }

    public function test_a_schema_is_compiled_once()
    {
        $schema = Json::object(['title' => Json::string()->extract('title')]);

        $this->assertSame(Compiler::compile($schema), Compiler::compile($schema));
        $this->assertInstanceOf(ObjectType::class, $schema);
    }

    public function test_the_locales_fall_back_to_the_app_locale()
    {
        $config = $this->app['config'];
        $config->set('app.locale', 'fr');
        $resolver = new ConfigLocaleResolver($config);
        $this->assertSame(['fr'], $resolver->locales());
        $this->assertSame('fr', $resolver->defaultLocale());

        $config->set('json-data.locales', ['en', 'es']);
        $this->assertSame(['en', 'es'], $resolver->locales());
        $this->assertSame('en', $resolver->defaultLocale());

        $config->set('json-data.default_locale', 'es');
        $this->assertSame('es', $resolver->defaultLocale());
    }
}
