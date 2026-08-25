<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Fisharebest\Webtrees\Site;
use JsonException;

/** Administrator-managed type identifiers for the optional house filter. */
final class PlaceTypeFilterSettings
{
    public const PREFERENCE = 'HH_EP_HOUSE_TYPES';

    /** @var array<string,list<string>> */
    private const DEFAULTS = [
        'wikidata' => ['Q3947', 'Q41176', 'Q131596', 'Q16560', 'Q1802963'],
        // FactGrid's documented buildings model uses Q701396 for residential
        // buildings and Q16200 for real estate. Q545649 is an apartment and
        // Q1340072 an isolated settlement/farmstead.
        'factgrid' => ['Q701396', 'Q16200', 'Q545649', 'Q1340072'],
        'gov' => ['8', '17', '21', '24', '193', '229', '231', '236', '261', '111', '102', '87'],
        'geonames' => ['S.BLDA', 'S.BLDG', 'S.BRKS', 'S.CH', 'S.CSTL', 'S.CSTM', 'S.EST', 'S.FRM', 'S.FRMQ', 'S.FRMS', 'S.FRMT', 'S.GHSE', 'S.HSE', 'S.HSEC', 'S.HTL', 'S.HUT', 'S.HUTS', 'S.LTHSE', 'S.ML', 'S.PAL', 'S.PRN', 'S.RNCH', 'S.RSRT', 'S.RUIN', 'S.SNTR', 'S.CVNT', 'S.MSSN'],
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

    /** @return array<string,list<string>> */
    public static function defaults(): array
    {
        return self::DEFAULTS;
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
    public static function matches(string $provider, array $candidate): bool
    {
        $values = self::all()[$provider] ?? [];
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
    public static function matchesWikibaseClaims(string $provider, array $claims): bool
    {
        $property = $provider === 'wikidata' ? 'P31' : 'P2';
        $allowed = self::all()[$provider] ?? [];
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
