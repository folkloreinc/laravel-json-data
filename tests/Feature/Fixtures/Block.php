<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A block, referenced through the `blocks` relation without a mapped key, as in 1.x.
 */
class Block extends Model
{
    protected $table = 'blocks';

    protected $fillable = ['type'];
}
