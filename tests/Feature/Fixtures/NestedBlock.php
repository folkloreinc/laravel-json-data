<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Tests\DeclaresJsonData;
use Illuminate\Database\Eloquent\Model;

/**
 * A block that holds blocks and images in its JSON data, as the pages of a 1.x site hold
 * blocks that hold blocks and media: `blocks.*` through the relation of an unmapped key,
 * `image.*` by the mapped `medias` key.
 */
class NestedBlock extends Model implements DeclaresJsonData
{
    protected $table = 'nested_blocks';

    protected $fillable = ['type'];

    protected $casts = [
        'data' => JsonDataCast::class,
    ];

    public function jsonDataReferences(string $attribute, mixed $value, array $attributes): array
    {
        return [
            'blocks.*' => 'blocks',
            'image.*' => 'medias',
        ];
    }

    public function jsonDataColumns(string $attribute, mixed $value, array $attributes): array
    {
        return [];
    }

    public function blocks()
    {
        return $this->morphToMany(self::class, 'blockable', 'nested_blocks_pivot', 'blockable_id', 'nested_block_id');
    }

    public function medias()
    {
        return $this->morphToMany(Media::class, 'mediable', 'mediables');
    }
}
