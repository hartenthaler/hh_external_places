<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;

/** Extracts a valid GEDCOM MAP/LATI/LONG coordinate pair from a shared place. */
final class LocationCoordinates
{
    /** @return array{latitude:float,longitude:float}|null */
    public static function fromGedcom(string $gedcom): ?array
    {
        return Coordinates::fromGedcom($gedcom)?->toArray();
    }
}
