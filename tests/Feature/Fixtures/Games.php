<?php

namespace Folklore\Tests\Feature\Eloquent\Fixtures;

use Folklore\Repositories\Entities;
use Illuminate\Database\Eloquent\Model;

/**
 * Like the games repository of the contests platform: every key outside the
 * fillable columns goes to the JSON data, and the prizes aren't eager loaded.
 */
class Games extends Entities
{
    protected $jsonAttributeFillable = '*';

    protected function newModel(): Model
    {
        return new Game;
    }

    protected function newQuery()
    {
        return parent::newQuery()->with(['medias', 'blocks']);
    }
}
