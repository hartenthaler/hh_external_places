<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

/**
 * Backwards-compatible facade for the former GeoNames-specific mapping.
 *
 * New provider code should use the provider-neutral LanguageCode class.
 */
final class GeoNamesLanguage
{
    public static function gedcom(string $code): ?string
    {
        return LanguageCode::gedcom($code);
    }

    public static function code(string $gedcom): string
    {
        return LanguageCode::normalize($gedcom);
    }
}
