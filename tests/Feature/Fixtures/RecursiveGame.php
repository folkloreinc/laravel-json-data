<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

/**
 * A game whose blocks are resolved before a recursive pattern over the whole value.
 */
class RecursiveGame extends Game
{
    public function jsonDataReferences(string $attribute, mixed $value, array $attributes): array
    {
        return [
            'blocks.*' => 'blocks',
            '**' => 'medias',
        ];
    }

    public function blocks()
    {
        return $this->morphToMany(CountedBlock::class, 'blockable', 'blocks_pivot', 'blockable_id', 'block_id');
    }
}
