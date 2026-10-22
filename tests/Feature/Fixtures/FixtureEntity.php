<?php

namespace Folklore\Tests\Feature\Eloquent\Fixtures;

use Folklore\Contracts\Entities\Entity;
use Folklore\Contracts\Entities\HasModel;
use Illuminate\Database\Eloquent\Model;

class FixtureEntity implements Entity, HasModel
{
    public function __construct(protected Model $model) {}

    public function id(): string
    {
        return (string) $this->model->getKey();
    }

    public function getModel(): Model
    {
        return $this->model;
    }
}
