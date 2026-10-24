<?php

namespace Folklore\JsonData\Contracts;

/**
 * The locales of the translatable nodes of schemas. The package binds one that reads its
 * config (`json-data.locales`); an application binds its own in the container.
 */
interface LocaleResolver
{
    /**
     * @return non-empty-list<string>
     */
    public function locales(): array;

    /**
     * The locale that a string given to a translatable node is stored in.
     */
    public function defaultLocale(): string;
}
