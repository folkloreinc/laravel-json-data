<?php

namespace Folklore\Tests\Feature\Eloquent\Fixtures;

use Folklore\Repositories\Entities;
use Illuminate\Database\Eloquent\Model;

/**
 * A repository of games with the `data` and `images` JSON attributes.
 */
class RegistryGames extends Entities
{
    protected $jsonAttributes = ['data', 'images'];

    protected $jsonAttributeFillable = '*';

    protected function newModel(): Model
    {
        return new BrandGame;
    }

    public function loadsJsonReferences(): bool
    {
        return $this->loadsJsonReferences;
    }
}
