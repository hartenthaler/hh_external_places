<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

/**
 * Normalizes language identifiers used by GEDCOM and external providers.
 *
 * The canonical comparison key is lower-case ISO 639-1 (for example `de`).
 * GEDCOM stores language names such as `GERMAN`, while providers may return
 * ISO 639-1 or ISO 639-2/T and ISO 639-2/B codes (for example `deu` or `ger`).
 * Unknown, syntactically valid two- or three-letter codes are preserved in
 * lower-case so that data is not silently discarded.
 */
final class LanguageCode
{
    /** @var array<string,string> ISO 639-1 => GEDCOM language name */
    private const GEDCOM_NAMES = [
        'af' => 'AFRIKAANS', 'sq' => 'ALBANIAN', 'ar' => 'ARABIC', 'hy' => 'ARMENIAN',
        'be' => 'BELARUSIAN', 'bg' => 'BULGARIAN', 'ca' => 'CATALAN', 'zh' => 'CHINESE',
        'hr' => 'CROATIAN', 'cs' => 'CZECH', 'da' => 'DANISH', 'nl' => 'DUTCH',
        'en' => 'ENGLISH', 'et' => 'ESTONIAN', 'fi' => 'FINNISH', 'fr' => 'FRENCH',
        'ka' => 'GEORGIAN', 'de' => 'GERMAN', 'el' => 'GREEK', 'he' => 'HEBREW',
        'hu' => 'HUNGARIAN', 'is' => 'ICELANDIC', 'id' => 'INDONESIAN', 'ga' => 'IRISH',
        'it' => 'ITALIAN', 'ja' => 'JAPANESE', 'ko' => 'KOREAN', 'lv' => 'LATVIAN',
        'lt' => 'LITHUANIAN', 'mk' => 'MACEDONIAN', 'no' => 'NORWEGIAN', 'pl' => 'POLISH',
        'pt' => 'PORTUGUESE', 'ro' => 'ROMANIAN', 'ru' => 'RUSSIAN', 'sr' => 'SERBIAN',
        'sk' => 'SLOVAK', 'sl' => 'SLOVENIAN', 'es' => 'SPANISH', 'sv' => 'SWEDISH',
        'tr' => 'TURKISH', 'uk' => 'UKRAINIAN', 'vi' => 'VIETNAMESE',
    ];

    /** @var array<string,string> ISO 639-2/T and ISO 639-2/B => ISO 639-1 */
    private const ISO639_2 = [
        'afr' => 'af', 'alb' => 'sq', 'sqi' => 'sq', 'ara' => 'ar', 'arm' => 'hy', 'hye' => 'hy',
        'bel' => 'be', 'bul' => 'bg', 'cat' => 'ca', 'chi' => 'zh', 'zho' => 'zh', 'hrv' => 'hr',
        'cze' => 'cs', 'ces' => 'cs', 'dan' => 'da', 'dut' => 'nl', 'nld' => 'nl', 'eng' => 'en',
        'est' => 'et', 'fin' => 'fi', 'fre' => 'fr', 'fra' => 'fr', 'geo' => 'ka', 'kat' => 'ka',
        'ger' => 'de', 'deu' => 'de', 'gre' => 'el', 'ell' => 'el', 'heb' => 'he', 'hun' => 'hu',
        'ice' => 'is', 'isl' => 'is', 'ind' => 'id', 'gle' => 'ga', 'ita' => 'it', 'jpn' => 'ja',
        'kor' => 'ko', 'lav' => 'lv', 'lit' => 'lt', 'mac' => 'mk', 'mkd' => 'mk', 'nor' => 'no',
        'pol' => 'pl', 'por' => 'pt', 'rum' => 'ro', 'ron' => 'ro', 'rus' => 'ru', 'scc' => 'sr',
        'srp' => 'sr', 'slo' => 'sk', 'slk' => 'sk', 'slv' => 'sl', 'spa' => 'es', 'swe' => 'sv',
        'tur' => 'tr', 'ukr' => 'uk', 'vie' => 'vi',
    ];

    /** @var array<string,string> GEDCOM spelling variants => ISO 639-1 */
    private const NAME_ALIASES = [
        'belorussian' => 'be',
        'serbo-croatian' => 'sr',
    ];

    public static function normalize(string $value): string
    {
        $value = strtolower(trim(str_replace('_', '-', $value)));
        $value = explode('-', $value)[0] ?? '';
        if (isset(self::ISO639_2[$value])) {
            return self::ISO639_2[$value];
        }
        if (isset(self::GEDCOM_NAMES[$value])) {
            return $value;
        }
        if (isset(self::NAME_ALIASES[$value])) {
            return self::NAME_ALIASES[$value];
        }

        $nameToCode = array_change_key_case(array_flip(self::GEDCOM_NAMES));
        if (isset($nameToCode[$value])) {
            return $nameToCode[$value];
        }

        return preg_match('/^[a-z]{2,3}$/', $value) === 1 ? $value : '';
    }

    public static function gedcom(string $value): ?string
    {
        $code = self::normalize($value);
        if ($code === '') {
            return null;
        }

        return self::GEDCOM_NAMES[$code] ?? strtoupper($code);
    }
}
