<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Contracts\LocaleResolver;
use Illuminate\Contracts\Config\Repository;

/**
 * The locales of the package's config (`json-data.locales`, `json-data.default_locale`),
 * falling back to `app.locale`.
 */
class ConfigLocaleResolver implements LocaleResolver
{
    public function __construct(
        protected Repository $config,
    ) {}

    public function locales(): array
    {
        $locales = $this->config->get('json-data.locales');
        $locales = is_array($locales) ? array_values(array_filter($locales, fn (mixed $locale) => is_string($locale) && $locale !== '')) : [];

        return count($locales) > 0 ? $locales : [$this->appLocale()];
    }

    public function defaultLocale(): string
    {
        $locales = $this->locales();
        $default = $this->config->get('json-data.default_locale');
        if (is_string($default) && $default !== '') {
            return $default;
        }
        $app = $this->appLocale();

        return in_array($app, $locales, true) ? $app : $locales[0];
    }

    protected function appLocale(): string
    {
        $locale = $this->config->get('app.locale');

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }
}
