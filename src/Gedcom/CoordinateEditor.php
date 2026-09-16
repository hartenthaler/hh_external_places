<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;

/** Adds a validated MAP coordinate block to a shared-place GEDCOM record. */
final class CoordinateEditor
{
    public function add(string $gedcom, Coordinates $coordinates): string
    {
        if (preg_match('/^1 MAP\b/m', $gedcom) === 1 || preg_match('/^[1-9] LATI\s+/m', $gedcom) === 1 || preg_match('/^[1-9] LONG\s+/m', $gedcom) === 1) {
            return $gedcom;
        }
        $latitude = ($coordinates->latitude < 0 ? 'S' : 'N') . number_format(abs($coordinates->latitude), 6, '.', '');
        $longitude = ($coordinates->longitude < 0 ? 'W' : 'E') . number_format(abs($coordinates->longitude), 6, '.', '');
        return rtrim($gedcom) . "\n1 MAP\n2 LATI " . $latitude . "\n2 LONG " . $longitude . "\n";
    }
}
