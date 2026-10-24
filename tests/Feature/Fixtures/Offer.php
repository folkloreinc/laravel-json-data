<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Closure;
use Folklore\JsonData\Contracts\HasJsonDataSchema;
use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Schema\ObjectType;
use Illuminate\Database\Eloquent\Model;

/**
 * An offer that declares its `data` column with a schema, and leaves `meta` to the
 * declarations hook.
 */
class Offer extends Model implements HasJsonDataSchema
{
    /**
     * The schema of `data` in a test, instead of the default one.
     *
     * @var (Closure(Offer): ?ObjectType)|null
     */
    public static ?Closure $schema = null;

    /**
     * The calls to jsonDataSchema(), by attribute.
     *
     * @var array<string, int>
     */
    public array $schemaCalls = [];

    protected $table = 'offers';

    protected $fillable = ['handle'];

    protected $casts = [
        'data' => JsonDataCast::class,
        'meta' => JsonDataCast::class,
    ];

    public function jsonDataSchema(string $attribute): ?ObjectType
    {
        $this->schemaCalls[$attribute] = ($this->schemaCalls[$attribute] ?? 0) + 1;
        if ($attribute !== 'data') {
            return null;
        }

        return is_null(static::$schema) ? self::defaultSchema() : (static::$schema)($this);
    }

    public static function defaultSchema(): ObjectType
    {
        return Json::object([
            'title' => Json::string()->translatable()->extract('title_{locale}'),
            'tagline' => Json::string()->input('subtitle'),
            'sort_name' => Json::string()->extract('name_sort'),
            'seo' => Json::object([
                'title' => Json::string()->input('seo_title'),
                'description' => Json::string(),
            ]),
            'image' => Json::reference('medias')->relation('medias')->translatable(),
            'gallery' => Json::list(Json::reference('medias')->relation('medias')),
            'prizes' => Json::list(Json::object([
                'prize' => Json::reference('prize')->relation('prizes'),
                'quantity' => Json::integer()->default(1),
            ])),
            'source' => Json::reference('prize'),
            'body' => Json::document()->references(Json::reference('medias')->relation('medias'), 'prize'),
            'status' => Json::enum(OfferStatus::class)->default(OfferStatus::Draft),
            'starts_on' => Json::date(),
            'settings' => Json::map(Json::boolean()),
            'extra' => Json::any(),
        ])->additional(false);
    }

    public function medias()
    {
        return $this->morphToMany(Media::class, 'mediable', 'mediables');
    }

    public function prizes()
    {
        return $this->morphToMany(Prize::class, 'prizable', 'prizes_pivot')->withTimestamps();
    }
}
