<?php

namespace Folklore\Tests\Feature\Eloquent\Fixtures;

use Folklore\Contracts\Entities\ToEntity;
use Folklore\Eloquent\JsonDataCast;
use Illuminate\Database\Eloquent\Model;

class Prize extends Model implements ToEntity
{
    protected $table = 'prizes';

    protected $fillable = ['contest_id', 'handle'];

    protected $casts = [
        'data' => JsonDataCast::class,
    ];

    public function toEntity(): FixtureEntity
    {
        return new FixtureEntity($this);
    }
}
