<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\LanguageCode;

/** Shared parser for language-specific shared-place names. */
final class PlaceNameRegistry
{
    /** @return array<string,list<string>> */
    public static function byLanguage(string $gedcom): array
    {
        $names = [];
        $currentName = null;
        foreach (preg_split('/\r?\n/', $gedcom) ?: [] as $line) {
            if (preg_match('/^1 NAME(?: |$)(.*)$/', $line, $match) === 1 && trim($match[1]) !== '') {
                $currentName = trim($match[1]);
                $names[''] ??= [];
                $names[''][] = $currentName;
            } elseif ($currentName !== null && preg_match('/^2 LANG (.+)$/', $line, $match) === 1) {
                $language = LanguageCode::normalize($match[1]);
                if ($language !== '') {
                    $names[$language][] = $currentName;
                }
            }
        }
        foreach ($names as $language => $values) {
            $names[$language] = array_values(array_unique($values));
        }
        return $names;
    }
}
