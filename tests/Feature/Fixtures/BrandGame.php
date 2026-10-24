<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Folklore\JsonData\JsonDataCast;

/**
 * A game with a second JSON attribute, `images`, as a brand has its named images.
 */
class BrandGame extends RegistryGame
{
    protected $casts = [
        'data' => JsonDataCast::class,
        'images' => JsonDataCast::class,
    ];

    public function jsonDataReferences(string $attribute, mixed $value, array $attributes): array
    {
        return $attribute === 'images'
            ? ['logo' => 'prizes', 'favicon' => 'prizes', 'gallery.*' => 'prizes']
            : parent::jsonDataReferences($attribute, $value, $attributes);
    }
}
