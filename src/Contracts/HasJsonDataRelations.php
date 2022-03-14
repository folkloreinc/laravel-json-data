<?php

namespace Folklore\Contracts\Eloquent;

interface HasJsonDataRelations
{
    public function getJsonDataRelations($key, $value, $attributes = []);
}
