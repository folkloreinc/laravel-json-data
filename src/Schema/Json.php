<?php

namespace Folklore\JsonData\Schema;

use BackedEnum;
use Folklore\JsonData\Exceptions\InvalidJsonData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;

/**
 * The factory of the nodes of a JSON column's schema (docs/json-data.md, "Schema"):
 *
 *     Json::object([
 *         'name' => Json::string()->translatable()->required()->max(120),
 *         'seo' => Json::object([
 *             'title' => Json::string()->input('seo_title'),
 *         ]),
 *         'prize' => Json::reference('prize')->relation('prizes'),
 *         'body' => Json::document()->references('media'),
 *     ])->additional(false);
 *
 * It is macroable, so that packages add their own nodes, such as laravel-folklore's
 * `Json::media()`.
 */
class Json
{
    use Macroable;

    public static function string(): StringType
    {
        return new StringType;
    }

    public static function integer(): IntegerType
    {
        return new IntegerType;
    }

    public static function number(): NumberType
    {
        return new NumberType;
    }

    public static function boolean(): BooleanType
    {
        return new BooleanType;
    }

    public static function date(): DateType
    {
        return new DateType;
    }

    public static function datetime(): DateType
    {
        return new DateType(withTime: true);
    }

    /**
     * One of the cases of a backed enum, or of a list of values.
     *
     * @param  class-string<BackedEnum>|array<int|string>  $values
     */
    public static function enum(string|array $values): EnumType
    {
        if (is_array($values)) {
            return new EnumType(null, array_values($values));
        }
        if (! is_subclass_of($values, BackedEnum::class)) {
            throw InvalidJsonData::invalidSchema("[{$values}] is not a backed enum.");
        }

        return new EnumType($values, array_map(fn (BackedEnum $case) => $case->value, $values::cases()));
    }

    /**
     * @param  array<string, Type>  $properties
     */
    public static function object(array $properties = []): ObjectType
    {
        return new ObjectType($properties);
    }

    public static function list(Type $of): ListType
    {
        return new ListType($of);
    }

    public static function map(Type $of): MapType
    {
        return new MapType($of);
    }

    public static function any(): AnyType
    {
        return new AnyType;
    }

    /**
     * A reference with a mapped key (`prize://grand-prize`).
     */
    public static function reference(string $key): ReferenceType
    {
        return new ReferenceType($key);
    }

    public static function document(): DocumentType
    {
        return new DocumentType;
    }

    /**
     * The Laravel validation rules of the flat input that `JsonData::fill()` puts into a
     * JSON attribute of the model, or into every attribute that has a schema, for a form
     * request (docs/json-data.md, "Validation rules"). Empty for an attribute without a
     * schema. The model is the one the input fills: a stored model keeps the paths that
     * the input doesn't give, so `required()` doesn't ask for them again.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(Model $model, ?string $attribute = null): array
    {
        return RuleBuilder::for($model, $attribute);
    }
}
