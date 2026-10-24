<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Folklore\JsonData\JsonDataCast;
use Illuminate\Database\Eloquent\Model;

class Prize extends Model
{
    protected $table = 'prizes';

    protected $fillable = ['contest_id', 'handle'];

    protected $casts = [
        'data' => JsonDataCast::class,
    ];
}
