<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A value that stands for a model, as an application's entity does: the cast takes it
 * once JsonData::unwrapUsing() knows it.
 */
class Wrapper
{
    public function __construct(public Model $model) {}
}
