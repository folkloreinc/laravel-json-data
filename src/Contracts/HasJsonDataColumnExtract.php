<?php

namespace Folklore\Contracts\Eloquent;

interface HasJsonDataColumnExtract
{
    public function getJsonDataColumnExtract($key, $value, $attributes = []);
}
