<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Tiny key-based i18n service. Loads locale arrays from `locales/<code>.php`
 * once per request; falls back to English if a key is missing in the
 * requested locale. Interpolation uses {placeholder} tokens.
 *
 * Usage:
 *   $t = new Translator(__DIR__ . '/../../locales');
 *   echo $t->trans('auction.bid.placed', ['amount' => '50.00']);
 *   echo $t->trans('auction.bid.placed', ['amount' => '50.00'], 'fr');
 *
 * Default locale is set via setDefaultLocale() once per request (the
 * locale-resolution middleware in public/index.php does this).
 */
final class Translator
{
    public const SUPPORTED = ['en', 'fr'];

    private string $defaultLocale = 'en';

    /** @var array<string, array<string, string>> */
    private array $cache = [];

    public function __construct(private readonly string $localesPath)
    {
    }

    public function setDefaultLocale(string $locale): void
    {
        $this->defaultLocale = $this->normalize($locale);
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * Normalize an inbound locale string to one we support. Anything we
     * don't recognise falls back to 'en'.
     */
    public function normalize(string $locale): string
    {
        $code = strtolower(substr(trim($locale), 0, 2));
        return in_array($code, self::SUPPORTED, true) ? $code : 'en';
    }

    /**
     * Translate a key. Falls back: requested locale → English → the key
     * itself (so a missing translation is visible but doesn't crash).
     *
     * @param  array<string, scalar|null> $params
     */
    public function trans(string $key, array $params = [], ?string $locale = null): string
    {
        $locale = $locale === null ? $this->defaultLocale : $this->normalize($locale);
        $template = $this->lookup($key, $locale)
                 ?? $this->lookup($key, 'en')
                 ?? $key;

        if ($params === []) {
            return $template;
        }

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = (string)$value;
        }
        return strtr($template, $replacements);
    }

    private function lookup(string $key, string $locale): ?string
    {
        if (!isset($this->cache[$locale])) {
            $path = $this->localesPath . '/' . $locale . '.php';
            $this->cache[$locale] = is_file($path) ? (array)require $path : [];
        }
        return $this->cache[$locale][$key] ?? null;
    }
}
