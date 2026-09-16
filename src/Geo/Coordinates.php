<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo;

/** Validated WGS84 coordinates with provider-neutral parsing and distances. */
final class Coordinates
{
    private const EARTH_RADIUS_METRES = 6_371_008.8;

    private function __construct(public readonly float $latitude, public readonly float $longitude)
    {
    }

    public static function fromDecimal(float $latitude, float $longitude): ?self
    {
        return self::valid($latitude, -90.0, 90.0) && self::valid($longitude, -180.0, 180.0)
            ? new self($latitude, $longitude) : null;
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): ?self
    {
        $latitude = $values['latitude'] ?? $values['lat'] ?? null;
        $longitude = $values['longitude'] ?? $values['lon'] ?? $values['lng'] ?? null;
        if (is_numeric($latitude) && is_numeric($longitude)) { return self::fromDecimal((float) $latitude, (float) $longitude); }
        return is_scalar($latitude) && is_scalar($longitude) ? self::fromStrings((string) $latitude, (string) $longitude) : null;
    }

    /** Parse decimal, signed, compass and degree/minute/second values. */
    public static function fromStrings(string $latitude, string $longitude): ?self
    {
        $lat = self::parse($latitude, -90.0, 90.0, 'NS');
        $lon = self::parse($longitude, -180.0, 180.0, 'EOW');
        return $lat === null || $lon === null ? null : new self($lat, $lon);
    }

    public static function fromGedcom(string $gedcom): ?self
    {
        $map = null;
        if (preg_match('/\n1 MAP\b[^\n]*(?:\n[2-9].*)*/', $gedcom, $match) === 1) { $map = $match[0]; }
        $source = $map ?? $gedcom;
        if (preg_match('/\n[1-9] LATI\s+([^\n]+)/', $source, $latitude) !== 1 || preg_match('/\n[1-9] LONG\s+([^\n]+)/', $source, $longitude) !== 1) { return null; }
        return self::fromStrings($latitude[1], $longitude[1]);
    }

    /** Parse Wikibase's decimal coordinate object or Point(lon lat) WKT. */
    public static function fromWikibase(mixed $value): ?self
    {
        if (is_array($value)) {
            $latitude = $value['latitude'] ?? $value['lat'] ?? null;
            $longitude = $value['longitude'] ?? $value['lon'] ?? $value['lng'] ?? null;
            return is_numeric($latitude) && is_numeric($longitude) ? self::fromDecimal((float) $latitude, (float) $longitude) : null;
        }
        if (is_string($value) && preg_match('/Point\s*\(\s*([+-]?[0-9]+(?:\.[0-9]+)?)\s+([+-]?[0-9]+(?:\.[0-9]+)?)\s*\)/i', $value, $match) === 1) {
            return self::fromDecimal((float) $match[2], (float) $match[1]);
        }
        if (is_string($value) && preg_match('/@\s*([+-]?[0-9]+(?:\.[0-9]+)?)\s*\/\s*([+-]?[0-9]+(?:\.[0-9]+)?)/', $value, $match) === 1) {
            return self::fromDecimal((float) $match[1], (float) $match[2]);
        }
        return null;
    }

    public function distanceTo(self $other): float
    {
        $lat1 = deg2rad($this->latitude); $lat2 = deg2rad($other->latitude);
        $dLat = $lat2 - $lat1; $dLon = deg2rad($other->longitude - $this->longitude);
        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
        return self::EARTH_RADIUS_METRES * 2 * asin(min(1.0, sqrt($a)));
    }

    public function distanceKmTo(self $other): float { return $this->distanceTo($other) / 1000.0; }

    /**
     * Return an approximate WGS84 bounding box for a radius around this point.
     * The box is used only to narrow provider queries; callers must still apply
     * the exact great-circle distance afterwards.
     *
     * @return array{latitude0:float,latitude1:float,longitude0:float,longitude1:float}
     */
    public function boundingBox(float $radiusKm): array
    {
        $radiusKm = max(0.0, $radiusKm);
        $latDelta = $radiusKm / 111.32;
        $lonScale = max(0.01, abs(cos(deg2rad($this->latitude))));
        $lonDelta = $radiusKm / (111.32 * $lonScale);

        return [
            'latitude0'  => max(-90.0, $this->latitude - $latDelta),
            'latitude1'  => min(90.0, $this->latitude + $latDelta),
            'longitude0' => max(-180.0, $this->longitude - $lonDelta),
            'longitude1' => min(180.0, $this->longitude + $lonDelta),
        ];
    }

    /** @return array{latitude:float,longitude:float} */
    public function toArray(): array { return ['latitude' => $this->latitude, 'longitude' => $this->longitude]; }

    private static function valid(float $value, float $minimum, float $maximum): bool { return is_finite($value) && $value >= $minimum && $value <= $maximum; }

    private static function parse(string $value, float $minimum, float $maximum, string $directions): ?float
    {
        $value = trim($value); if ($value === '') { return null; }
        $direction = '';
        if (preg_match('/^([' . $directions . '])\s*/iu', $value, $prefix) === 1) { $direction = strtoupper($prefix[1]); $value = trim(substr($value, strlen($prefix[0]))); }
        elseif (preg_match('/([' . $directions . '])\s*$/iu', $value, $suffix) === 1) { $direction = strtoupper($suffix[1]); $value = trim(substr($value, 0, -strlen($suffix[0]))); }
        $coordinate = null;
        if (preg_match('/^([+-]?[0-9]+(?:\.[0-9]+)?)$/', $value, $decimal) === 1) { $coordinate = (float) $decimal[1]; }
        elseif (preg_match('/^([0-9]+)(?:[^0-9]+([0-9]+))?(?:[^0-9]+([0-9]+(?:\.[0-9]+)?))?$/', $value, $dms) === 1) {
            $minutes = ($dms[2] ?? '') === '' ? 0.0 : (float) $dms[2]; $seconds = ($dms[3] ?? '') === '' ? 0.0 : (float) $dms[3];
            if ($minutes >= 60 || $seconds >= 60) { return null; }
            $coordinate = (float) $dms[1] + $minutes / 60 + $seconds / 3600;
        }
        if ($coordinate === null) { return null; }
        if (in_array($direction, ['N', 'E', 'O'], true)) { $coordinate = abs($coordinate); }
        if (in_array($direction, ['S', 'W'], true)) { $coordinate = -abs($coordinate); }
        return self::valid($coordinate, $minimum, $maximum) ? $coordinate : null;
    }
}
