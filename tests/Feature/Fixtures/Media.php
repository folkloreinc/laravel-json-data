<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A media, mapped to the `medias` key by JsonDataTestCase, as laravel-folklore's medias
 * module maps it on upgraded sites.
 */
class Media extends Model
{
    protected $table = 'medias';

    protected $fillable = ['name'];
}
