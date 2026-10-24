<?php

return [
    /*
    | The locales of the translatable nodes of schemas, as a list (`['fr', 'en']`). Null
    | for `app.locale` alone. An application can bind its own
    | `Folklore\JsonData\Contracts\LocaleResolver` in the container instead.
    */
    'locales' => null,

    /*
    | The locale that a string given to a translatable node is stored in. Null for
    | `app.locale` when it is one of the locales, else the first one.
    */
    'default_locale' => null,
];
