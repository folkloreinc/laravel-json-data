<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Tests\DeclaresJsonData;
use Illuminate\Database\Eloquent\Model;

/**
 * A game of a contest, as the contests platform declares it: localized images,
 * images deep in the questions, blocks, and lazy prizes.
 */
class Game extends Model implements DeclaresJsonData
{
    /**
     * Options added to the declaration of the `image.*` paths.
     *
     * @var array<string, mixed>
     */
    public static array $imageOptions = [];

    protected $table = 'games';

    protected $fillable = ['handle'];

    protected $casts = [
        'data' => JsonDataCast::class,
    ];

    public function jsonDataReferences(string $attribute, mixed $value, array $attributes): array
    {
        return [
            'image.*' => array_merge(['relation' => 'medias'], self::$imageOptions),
            'questions.*.choices.*.image' => 'medias',
            'blocks.*' => 'blocks',
            'prizes.*.prize' => [
                'relation' => 'prizes',
                'lazy' => true,
            ],
        ];
    }

    public function jsonDataColumns(string $attribute, mixed $value, array $attributes): array
    {
        return collect(config('locale.locales'))
            ->mapWithKeys(fn ($locale) => ['title.'.$locale => 'title_'.$locale])
            ->all();
    }

    public function medias()
    {
        return $this->morphToMany(Media::class, 'mediable', 'mediables');
    }

    public function blocks()
    {
        return $this->morphToMany(Block::class, 'blockable', 'blocks_pivot');
    }

    public function prizes()
    {
        return $this->morphToMany(Prize::class, 'prizable', 'prizes_pivot')->withTimestamps();
    }
}
