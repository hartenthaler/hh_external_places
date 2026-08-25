<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata;

use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Site;
use JsonException;

/** Site-wide default and optional per-tree overrides for nearby discovery. */
final class NearbyDiscoverySettings
{
    /**
     * Keep this preference name below webtrees' setting_name column limit.
     * The same bounded value is used by every provider's nearby search.
     */
    public const PREFERENCE = 'HH_EXTERNAL_PLACES_RADIUS_KM';
    // Keep this below webtrees' setting_name column limit.
    public const EXCEPTIONS_PREFERENCE = 'HH_EP_RAD_EX';
    private const LEGACY_EXCEPTIONS_PREFERENCES = [
        'HH_EXT_PLACES_RAD_EXCEPTIONS',
        'HH_EXTERNAL_PLACES_RADIUS_EXCEPTIONS',
    ];
    public const DEFAULT_RADIUS_KM = 5.0;

    public static function radius(Tree $tree): float
    {
        $exceptions = self::exceptions();
        $key = (string) $tree->id();
        if (isset($exceptions[$key])) { return self::normalise((string) $exceptions[$key]); }
        $global = Site::getPreference(self::PREFERENCE);
        return self::normalise($global !== '' ? $global : $tree->getPreference(self::PREFERENCE, (string) self::DEFAULT_RADIUS_KM));
    }

    public static function globalRadius(): float
    {
        return self::normalise(Site::getPreference(self::PREFERENCE) ?: (string) self::DEFAULT_RADIUS_KM);
    }

    /** @return array<string,float> */
    public static function exceptions(): array
    {
        $raw = trim(Site::getPreference(self::EXCEPTIONS_PREFERENCE));
        if ($raw === '') {
            foreach (self::LEGACY_EXCEPTIONS_PREFERENCES as $preference) {
                $raw = trim(Site::getPreference($preference));
                if ($raw !== '') {
                    break;
                }
            }
        }
        if ($raw === '') { return []; }
        try { $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); } catch (JsonException) { return []; }
        if (!is_array($decoded)) { return []; }
        $exceptions = [];
        foreach ($decoded as $key => $value) {
            if ((is_int($key) || is_string($key)) && is_numeric($value)) {
                $exceptions[(string) $key] = self::normalise((string) $value);
            }
        }
        return $exceptions;
    }

    /** @param array<string,string> $exceptions */
    public static function save(float $global, array $exceptions): void
    {
        $global = self::normalise((string) $global);
        $normalised = [];
        foreach ($exceptions as $key => $value) {
            $key = trim((string) $key);
            $parsed = self::parse((string) $value);
            if ($key !== '' && $parsed !== null) {
                $value = $parsed;
                if ($value !== $global) {
                    $normalised[$key] = $value;
                }
            }
        }
        Site::setPreference(self::PREFERENCE, (string) $global);
        Site::setPreference(self::EXCEPTIONS_PREFERENCE, (string) json_encode($normalised, JSON_UNESCAPED_SLASHES));
    }

    public static function normalise(string $radius): float
    {
        $radius = str_replace(',', '.', trim($radius));
        return is_numeric($radius) ? max(0.1, min(100.0, (float) $radius)) : self::DEFAULT_RADIUS_KM;
    }

    public static function parse(string $radius): ?float
    {
        $radius = str_replace(',', '.', trim($radius));
        return is_numeric($radius) ? self::normalise($radius) : null;
    }
}
