<?php

namespace Folklore\Tests\Feature\Eloquent\Fixtures;

use Folklore\Contracts\Eloquent\HasJsonDataRelations;
use Folklore\Contracts\Entities\ToEntity;
use Folklore\Eloquent\JsonDataCast;
use Illuminate\Database\Eloquent\Model;

/**
 * A contest that references some of its prizes from its JSON data, through a
 * HasMany, as the contests platform references its pages from its menus.
 */
class Contest extends Model implements HasJsonDataRelations, ToEntity
{
    protected $table = 'contests';

    protected $casts = [
        'data' => JsonDataCast::class,
    ];

    public function getJsonDataRelations($key, $value, $attributes = [])
    {
        return [
            'featured.*' => 'prizes',
        ];
    }

    public function prizes()
    {
        return $this->hasMany(Prize::class);
    }

    public function toEntity(): FixtureEntity
    {
        return new FixtureEntity($this);
    }
}
