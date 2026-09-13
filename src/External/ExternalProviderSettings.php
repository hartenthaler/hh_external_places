<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Fisharebest\Webtrees\Site;

/** Site-wide enablement for optional external information providers. */
final class ExternalProviderSettings
{
    public const PREFERENCE = 'HH_EXTERNAL_PLACES_PROVIDERS';

    /** @var list<string> */
    public const PROVIDERS = ['wikidata', 'factgrid', 'gov', 'geonames', 'genwiki', 'nominatim'];

    /** @return list<string> */
    public static function enabled(): array
    {
        $stored = trim(Site::getPreference(self::PREFERENCE));
        if ($stored === '') { return self::PROVIDERS; }
        if ($stored === '-') { return []; }
        $values = array_values(array_intersect(self::PROVIDERS, array_filter(array_map('trim', explode(',', $stored)))));
        return array_values(array_unique($values));
    }

    public static function isEnabled(string $provider): bool
    {
        return in_array($provider, self::enabled(), true);
    }

    /** @param list<string> $providers */
    public static function save(array $providers): void
    {
        $providers = array_values(array_intersect(self::PROVIDERS, array_unique($providers)));
        Site::setPreference(self::PREFERENCE, $providers === [] ? '-' : implode(',', $providers));
    }

    /** @return array<string,string> */
    public static function labels(): array
    {
        return ['wikidata' => 'Wikidata', 'factgrid' => 'FactGrid', 'gov' => 'GOV', 'geonames' => 'GeoNames', 'genwiki' => 'GenWiki', 'nominatim' => 'Nominatim'];
    }
}
