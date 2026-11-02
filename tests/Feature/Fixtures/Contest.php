<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Tests\DeclaresJsonData;
use Illuminate\Database\Eloquent\Model;

/**
 * A contest that references some of its prizes from its JSON data, through a
 * HasMany, as the contests platform references its pages from its menus.
 */
class Contest extends Model implements DeclaresJsonData
{
    /**
     * Options added to the declaration of the `featured.*` paths.
     *
     * @var array<string, mixed>
     */
    public static array $featuredOptions = [];

    protected $table = 'contests';

    protected $casts = [
        'data' => JsonDataCast::class,
    ];

    public function jsonDataReferences(string $attribute, mixed $value, array $attributes): array
    {
        return [
            'featured.*' => count(self::$featuredOptions) === 0 ? 'prizes' : ['relation' => 'prizes', ...self::$featuredOptions],
        ];
    }

    public function jsonDataColumns(string $attribute, mixed $value, array $attributes): array
    {
        return [];
    }

    public function prizes()
    {
        return $this->hasMany(Prize::class);
    }
}
