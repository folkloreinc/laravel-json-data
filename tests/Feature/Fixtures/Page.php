<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Tests\DeclaresJsonData;
use Illuminate\Database\Eloquent\Model;

/**
 * A page that references its parent from its JSON data, through a BelongsTo, as
 * laravel-folklore's pages do.
 */
class Page extends Model implements DeclaresJsonData
{
    /**
     * Options added to the declaration of the `parent` path.
     *
     * @var array<string, mixed>
     */
    public static array $parentOptions = [];

    protected $table = 'pages';

    protected $fillable = ['handle', 'parent_id', 'data'];

    protected $casts = [
        'data' => JsonDataCast::class,
    ];

    public function jsonDataReferences(string $attribute, mixed $value, array $attributes): array
    {
        return [
            'parent' => count(self::$parentOptions) === 0 ? 'parent' : ['relation' => 'parent', ...self::$parentOptions],
        ];
    }

    public function jsonDataColumns(string $attribute, mixed $value, array $attributes): array
    {
        return [];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
