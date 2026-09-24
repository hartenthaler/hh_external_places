<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

/**
 * Validates and builds language-specific Wikipedia sitelinks.
 *
 * Sitelinks are read from the bounded Wikibase response; arbitrary URLs are
 * never accepted from form input or external claims.
 */
final class WikipediaLink
{
    /** @return array{label:string,url:string}|null */
    public static function fromSitelinks(mixed $sitelinks, string $language): ?array
    {
        if (!is_array($sitelinks)) {
            return null;
        }

        $preferred = LanguageCode::normalize($language);
        $languages = array_values(array_unique(array_filter([$preferred, 'en'])));
        foreach ($languages as $languageCode) {
            $site = $languageCode . 'wiki';
            $sitelink = $sitelinks[$site] ?? null;
            $title = is_array($sitelink) ? ($sitelink['title'] ?? null) : null;
            if (!is_string($title) || trim($title) === '') {
                continue;
            }

            $title = trim($title);
            return [
                'label' => 'Wikipedia (' . strtoupper($languageCode) . ')',
                'url' => self::url($languageCode, $title),
            ];
        }

        return null;
    }

    /** @return array{language:string,title:string,authority:string,url:string}|null */
    public static function parse(string $url): ?array
    {
        if (preg_match('~^https?://([a-z]{2,3}(?:-[a-z0-9]+)*)\\.wikipedia\\.org/wiki/([^?#]+)$~i', trim($url), $match) !== 1) {
            return null;
        }

        $language = strtolower($match[1]);
        $title = rawurldecode($match[2]);
        if ($title === '' || str_contains($title, '\\')) {
            return null;
        }

        $authority = 'https://' . $language . '.wikipedia.org/wiki/';
        return [
            'language' => $language,
            'title' => $title,
            'authority' => $authority,
            'url' => self::url($language, $title),
        ];
    }

    public static function url(string $language, string $title): string
    {
        $language = strtolower(trim($language));
        return 'https://' . $language . '.wikipedia.org/wiki/' . rawurlencode($title);
    }
}
