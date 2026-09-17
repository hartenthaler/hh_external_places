<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Fisharebest\Webtrees\Site;
use JsonException;

/** Site-wide tolerances for cross-provider coordinate comparisons. */
final class CoordinateConsistencySettings
{
    public const PREFERENCE = 'HH_EP_COORD_TOL';

    /** Values are stored in metres; null means that the level has no coordinates. */
    private const DEFAULTS = ['house' => 5.0, 'country' => 200_000.0, 'federation' => 500_000.0, 'planet' => null];
    public const LEVELS = ['house', 'country', 'federation', 'planet'];

    /** @return array<string,float|null> */
    public static function all(): array
    {
        $result = self::DEFAULTS;
        $raw = trim(Site::getPreference(self::PREFERENCE));
        if ($raw === '') { return $result; }
        try { $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR); } catch (JsonException) { return $result; }
        if (!is_array($decoded)) { return $result; }
        foreach (self::DEFAULTS as $level => $default) {
            if ($default === null || !is_numeric($decoded[$level] ?? null)) { continue; }
            $value = (float) $decoded[$level];
            if (is_finite($value) && $value >= 0.0 && $value <= 20_000_000.0) { $result[$level] = $value; }
        }
        return $result;
    }

    public static function forLevel(string $level): ?float { return self::all()[$level] ?? null; }

    /** @param array<string,mixed> $values Values are entered in the displayed unit. */
    public static function save(array $values): void
    {
        $result = self::DEFAULTS;
        foreach (['house', 'country', 'federation'] as $level) {
            $raw = str_replace(',', '.', trim((string) ($values[$level] ?? '')));
            if (!is_numeric($raw)) { continue; }
            $value = (float) $raw * ($level === 'house' ? 1.0 : 1000.0);
            if (is_finite($value) && $value >= 0.0 && $value <= 20_000_000.0) { $result[$level] = $value; }
        }
        Site::setPreference(self::PREFERENCE, (string) json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    public static function displayValue(string $level, ?float $metres = null): string
    {
        $metres ??= self::forLevel($level);
        if ($metres === null) { return ''; }
        return (string) ($level === 'house' ? $metres : $metres / 1000.0);
    }

    public static function unit(string $level): string { return $level === 'house' ? 'm' : 'km'; }

    /** Infer the comparison level from the shared-place type without requiring a provider. */
    public static function hierarchyForGedcom(string $gedcom): string
    {
        if (preg_match('/^1 TYPE .*?(Haus|Hof|Hofstelle|Bauernhof|Wohnung|Gebäude|Burg|Schloss|house|farm|building|castle|palace)/imu', $gedcom) === 1) { return 'house'; }
        if (preg_match('/^1 TYPE .*?(Staatenbund|Bund|Union|Internationale Organisation|federation|international organization)/imu', $gedcom) === 1) { return 'federation'; }
        if (preg_match('/^1 TYPE .*?(Planet|Erde|world|planet)/imu', $gedcom) === 1) { return 'planet'; }
        if (preg_match('/^1 TYPE .*?(Landkreis|Bundesland|Staat|Land|county|state|country)/imu', $gedcom) === 1) { return 'country'; }
        // An unknown or missing place type must not be treated as a country.
        // Otherwise local places such as villages are incorrectly excluded
        // from Nominatim lookups. Coordinate comparison remains disabled for
        // this level because no tolerance is configured for it.
        return 'unknown';
    }
}
