<?php

namespace Folklore\Tests\Feature\Eloquent\Fixtures;

use Folklore\Contracts\Eloquent\HasJsonDataColumnExtract;
use Folklore\Contracts\Eloquent\HasJsonDataRelations;
use Folklore\Contracts\Entities\ToEntity;
use Folklore\Eloquent\JsonDataCast;
use Folklore\Mediatheque\Concerns\HasMedias;
use Folklore\Models\Block;
use Illuminate\Database\Eloquent\Model;

/**
 * A game of a contest, as the contests platform declares it: localized images,
 * images deep in the questions, blocks, and lazy prizes.
 */
class Game extends Model implements HasJsonDataColumnExtract, HasJsonDataRelations, ToEntity
{
    use HasMedias;

    protected $table = 'games';

    protected $fillable = ['handle'];

    protected $casts = [
        'data' => JsonDataCast::class,
    ];

    public function getJsonDataRelations($key, $value, $attributes = [])
    {
        return [
            'image.*' => 'medias',
            'questions.*.choices.*.image' => 'medias',
            'blocks.*' => 'blocks',
            'prizes.*.prize' => [
                'relation' => 'prizes',
                'lazy' => true,
            ],
        ];
    }

    public function getJsonDataColumnExtract($key, $value, $attributes = [])
    {
        return collect(config('locale.locales'))
            ->mapWithKeys(fn ($locale) => ['title.'.$locale => 'title_'.$locale])
            ->all();
    }

    public function blocks()
    {
        return $this->morphToMany(Block::class, 'blockable', 'blocks_pivot');
    }

    public function prizes()
    {
        return $this->morphToMany(Prize::class, 'prizable', 'prizes_pivot')->withTimestamps();
    }

    public function toEntity(): FixtureEntity
    {
        return new FixtureEntity($this);
    }
}
