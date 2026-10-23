<?php

namespace Folklore\Tests\Unit\Eloquent\Fixtures;

use Folklore\Contracts\Entities\HasEntityKey;

class MediaWithUid extends ReferencedMedia implements HasEntityKey
{
    public function getEntityKeyName(): string
    {
        return 'uid';
    }

    public function getEntityKey(): mixed
    {
        return $this->getAttribute($this->getEntityKeyName());
    }
}
