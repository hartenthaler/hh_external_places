<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Fisharebest\Webtrees\Site;
use JsonException;

/** Administrator-managed type identifiers for the optional hierarchy filters. */
final class PlaceTypeFilterSettings
{
    public const PREFERENCE = 'HH_EP_HOUSE_TYPES';

    /** @var list<string> */
    public const LEVELS = ['planet', 'federation', 'country', 'house'];

    /** @var array<string,list<string>> */
    private const DEFAULTS = [
        'wikidata' => ['Q23413', 'Q751876', 'Q3947', 'Q16560', 'Q41176', 'Q44613', 'Q365627', 'Q1802963', 'Q131596'],
        // FactGrid building, dwelling and farm type identifiers.
        'factgrid' => ['Q701396', 'Q701397', 'Q983863', 'Q1783859', 'Q1783874', 'Q1853416', 'Q635758', 'Q394385', 'Q498903', 'Q36251', 'Q164328', 'Q370004', 'Q468136', 'Q902325'],
        'gov' => ['8', '17', '21', '24', '193', '229', '231', '236', '261', '111', '102', '87'],
        'geonames' => ['S.BLDA', 'S.BLDG', 'S.BRKS', 'S.CH', 'S.CSTL', 'S.CSTM', 'S.EST', 'S.FRM', 'S.FRMQ', 'S.FRMS', 'S.FRMT', 'S.GHSE', 'S.HSE', 'S.HSEC', 'S.HTL', 'S.HUT', 'S.HUTS', 'S.LTHSE', 'S.ML', 'S.PAL', 'S.PRN', 'S.RNCH', 'S.RSRT', 'S.RUIN', 'S.SNTR', 'S.CVNT', 'S.MSSN'],
    ];

    /** @var array<string,array<string,list<string>>> */
    private const HIERARCHY_DEFAULTS = [
        'planet' => [
            'wikidata' => ['Q634'],
            'factgrid' => ['Q176135'],
            'gov' => [],
            'geonames' => [],
        ],
        'federation' => [
            'wikidata' => ['Q484652', 'Q1335818', 'Q170156'],
            'factgrid' => ['Q1059807'],
            'gov' => [],
            'geonames' => [],
        ],
        'country' => [
            'wikidata' => ['Q6256', 'Q1048835', 'Q4835091'],
            'factgrid' => ['Q21925', 'Q221010'],
            'gov' => [],
            'geonames' => ['A.PCLI'],
        ],
    ];

    /** English labels from the GOV type vocabulary (German labels are in the PO files). */
    private const GOV_LABELS = [
        '8' => 'Castle',
        '17' => 'Building',
        '21' => 'Manor (building)',
        '24' => 'Farm',
        '193' => 'Alpine pasture',
        '229' => 'Group of houses',
        '231' => 'Farms',
        '236' => 'Houses',
        '261' => 'Farm hamlet',
        '111' => 'Palace',
        '102' => "Forester's house",
        '87' => 'Mill',
    ];

    /** @return array<string,list<string>> */
    public static function all(): array
    {
        $raw = trim(Site::getPreference(self::PREFERENCE));
        if ($raw === '') { return self::DEFAULTS; }
        try { $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); } catch (JsonException) { return self::DEFAULTS; }
        if (!is_array($decoded)) { return self::DEFAULTS; }
        $result = self::DEFAULTS;
        foreach (array_keys(self::DEFAULTS) as $provider) {
            if (!is_array($decoded[$provider] ?? null)) { continue; }
            $values = [];
            foreach ($decoded[$provider] as $value) {
                $value = trim((string) $value);
                if ($value !== '' && mb_strlen($value) <= 120) { $values[] = $value; }
            }
            $result[$provider] = array_values(array_unique($values));
        }
        return $result;
    }

    /** @return array<string,array<string,list<string>>> */
    public static function levels(): array
    {
        $levels = self::HIERARCHY_DEFAULTS;
        $levels['house'] = self::all();
        $raw = trim(Site::getPreference(self::PREFERENCE . '_LEVELS'));
        if ($raw === '') {
            return $levels;
        }
        try { $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); } catch (JsonException) { return $levels; }
        if (!is_array($decoded)) { return $levels; }
        foreach (self::LEVELS as $level) {
            foreach (array_keys(self::DEFAULTS) as $provider) {
                if (!is_array($decoded[$level][$provider] ?? null)) { continue; }
                $values = array_values(array_unique(array_filter(array_map(static fn ($value): string => trim((string) $value), $decoded[$level][$provider]), static fn (string $value): bool => $value !== '' && mb_strlen($value) <= 120)));
                $levels[$level][$provider] = $values;
            }
        }
        return $levels;
    }

    /** @return list<string> */
    public static function forLevel(string $provider, string $level): array
    {
        return self::levels()[$level][$provider] ?? [];
    }

    /** @param array<string,array<string,list<string>>> $levels */
    public static function saveLevels(array $levels): void
    {
        $result = [];
        foreach (self::LEVELS as $level) {
            foreach (array_keys(self::DEFAULTS) as $provider) {
                $result[$level][$provider] = [];
                foreach ((array) ($levels[$level][$provider] ?? []) as $rawValue) {
                    foreach (preg_split('/\R/u', (string) $rawValue) ?: [] as $value) {
                        $value = trim($value);
                        if ($value !== '' && mb_strlen($value) <= 120) { $result[$level][$provider][] = $value; }
                    }
                }
                $result[$level][$provider] = array_values(array_unique($result[$level][$provider]));
            }
        }
        self::save($result['house']);
        Site::setPreference(self::PREFERENCE . '_LEVELS', (string) json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,list<string>> */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /** @return array<string,list<string>> */
    public static function defaultsForLevel(string $level): array
    {
        return $level === 'house' ? self::DEFAULTS : (self::HIERARCHY_DEFAULTS[$level] ?? []);
    }

    public static function reset(string $provider): void
    {
        if (!array_key_exists($provider, self::DEFAULTS)) {
            return;
        }

        $filters = self::all();
        $filters[$provider] = self::DEFAULTS[$provider];
        self::save($filters);
    }

    /** @param array<string,list<string>> $filters */
    public static function save(array $filters): void
    {
        $result = [];
        foreach (array_keys(self::DEFAULTS) as $provider) {
            $result[$provider] = [];
            foreach ((array) ($filters[$provider] ?? []) as $rawValue) {
                foreach (preg_split('/\R/u', (string) $rawValue) ?: [] as $value) {
                    $value = trim($value);
                    if ($value !== '' && mb_strlen($value) <= 120) {
                        $result[$provider][] = $value;
                    }
                }
            }
            $result[$provider] = array_values(array_unique($result[$provider]));
        }
        Site::setPreference(self::PREFERENCE, (string) json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $candidate */
    public static function matches(string $provider, array $candidate, string $level = 'house'): bool
    {
        $values = self::forLevel($provider, $level);
        if ($values === []) { return false; }
        $haystack = mb_strtolower(implode(' ', array_map('strval', [$candidate['description'] ?? '', $candidate['type'] ?? '', $candidate['typeId'] ?? '', $candidate['featureCode'] ?? ''])));
        foreach ($values as $value) {
            if (str_contains($haystack, mb_strtolower($value))) { return true; }
            $label = self::GOV_LABELS[$value] ?? null;
            if ($provider === 'gov' && $label !== null && str_contains($haystack, mb_strtolower($label))) { return true; }
        }
        return false;
    }

    /** @param array<string,mixed> $claims */
    public static function matchesWikibaseClaims(string $provider, array $claims, string $level = 'house'): bool
    {
        $property = $provider === 'wikidata' ? 'P31' : 'P2';
        $allowed = self::forLevel($provider, $level);
        foreach ((array) ($claims[$property] ?? []) as $statement) {
            $value = $statement['mainsnak']['datavalue']['value'] ?? null;
            $id = is_array($value) ? ($value['id'] ?? (isset($value['numeric-id']) ? 'Q' . $value['numeric-id'] : null)) : null;
            if (is_string($id) && in_array($id, $allowed, true)) {
                return true;
            }
        }
        return false;
    }

    public static function govLabel(string $typeId): string
    {
        return self::GOV_LABELS[$typeId] ?? $typeId;
    }

    public static function geonamesLabel(string $code): string
    {
        static $labels;
        if (!is_array($labels)) {
            $file = dirname(__DIR__, 2) . '/resources/config/geonames-feature-codes.json';
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
            $labels = is_array($decoded) ? $decoded : [];
        }
        return is_string($labels[$code] ?? null) ? $labels[$code] : $code;
    }
}
