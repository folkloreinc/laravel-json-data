<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

/**
 * A game whose handle is set on `saved` by a listener registered when the model boots, so
 * before the cast's, which saves the model again, as eloquent-sluggable does with
 * `sluggableEvent()` set to `saved` (#22).
 */
class SluggedGame extends Game
{
    /**
     * Whether the listener saves the model again with events, rather than quietly.
     */
    public static bool $withEvents = false;

    protected static function booted(): void
    {
        static::saved(function (SluggedGame $game) {
            if (is_null($game->handle)) {
                $game->handle = 'game-'.$game->id;
                static::$withEvents ? $game->save() : $game->saveQuietly();
            }
        });
    }

    public function getMorphClass()
    {
        return Game::class;
    }
}
