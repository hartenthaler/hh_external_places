<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

/** Shared GeoNames-to-GEDCOM language mapping. */
final class GeoNamesLanguage
{
    /** @var array<string,string> */
    private const NAMES = ['af' => 'AFRIKAANS', 'sq' => 'ALBANIAN', 'ar' => 'ARABIC', 'hy' => 'ARMENIAN', 'be' => 'BELARUSIAN', 'bg' => 'BULGARIAN', 'ca' => 'CATALAN', 'zh' => 'CHINESE', 'hr' => 'CROATIAN', 'cs' => 'CZECH', 'da' => 'DANISH', 'nl' => 'DUTCH', 'en' => 'ENGLISH', 'et' => 'ESTONIAN', 'fi' => 'FINNISH', 'fr' => 'FRENCH', 'ka' => 'GEORGIAN', 'de' => 'GERMAN', 'el' => 'GREEK', 'he' => 'HEBREW', 'hu' => 'HUNGARIAN', 'is' => 'ICELANDIC', 'id' => 'INDONESIAN', 'ga' => 'IRISH', 'it' => 'ITALIAN', 'ja' => 'JAPANESE', 'ko' => 'KOREAN', 'lv' => 'LATVIAN', 'lt' => 'LITHUANIAN', 'mk' => 'MACEDONIAN', 'no' => 'NORWEGIAN', 'pl' => 'POLISH', 'pt' => 'PORTUGUESE', 'ro' => 'ROMANIAN', 'ru' => 'RUSSIAN', 'sr' => 'SERBIAN', 'sk' => 'SLOVAK', 'sl' => 'SLOVENIAN', 'es' => 'SPANISH', 'sv' => 'SWEDISH', 'tr' => 'TURKISH', 'uk' => 'UKRAINIAN', 'vi' => 'VIETNAMESE'];

    public static function gedcom(string $code): ?string
    {
        $code = strtolower(explode('-', str_replace('_', '-', trim($code)))[0]);
        return self::NAMES[$code] ?? (preg_match('/^[a-z]{2,3}$/', $code) === 1 ? strtoupper($code) : null);
    }

    public static function code(string $gedcom): string
    {
        $value = strtolower(trim($gedcom));
        $aliases = ['belorussian' => 'be', 'serbo-croatian' => 'sr'];
        if (isset($aliases[$value])) { return $aliases[$value]; }
        $code = array_search(strtoupper($value), self::NAMES, true);
        return $code === false ? $value : $code;
    }
}
