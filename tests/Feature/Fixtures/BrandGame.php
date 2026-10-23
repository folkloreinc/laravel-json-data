<?php

namespace Folklore\Tests\Feature\Eloquent\Fixtures;

use Folklore\Eloquent\JsonDataCast;

/**
 * A game with a second JSON attribute, `images`, as a brand has its named images.
 */
class BrandGame extends RegistryGame
{
    protected $casts = [
        'data' => JsonDataCast::class,
        'images' => JsonDataCast::class,
    ];

    public function getJsonDataRelations($key, $value, $attributes = [])
    {
        return $key === 'images'
            ? ['logo' => 'prizes', 'favicon' => 'prizes', 'gallery.*' => 'prizes']
            : parent::getJsonDataRelations($key, $value, $attributes);
    }
}
