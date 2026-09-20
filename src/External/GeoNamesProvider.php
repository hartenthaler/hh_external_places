<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\HttpTransport;
use Throwable;

/** Read-only GeoNames lookup for contextual place information. */
final class GeoNamesProvider implements ExternalProvider
{
    private const ENDPOINT = 'https://secure.geonames.org/searchJSON';
    private const GET_ENDPOINT = 'https://secure.geonames.org/getJSON';
    private const HIERARCHY_ENDPOINT = 'https://secure.geonames.org/hierarchyJSON';
    public const AUTHORITY_URI = 'https://www.geonames.org/';

    private readonly HttpTransport $http;

    public function __construct(?HttpTransport $http = null, private readonly ExternalProviderCache $cache = new ExternalProviderCache())
    {
        $this->http = $http ?? HttpTransport::default();
    }

    public function key(): string { return 'geonames'; }

    public function label(): string { return 'GeoNames'; }


    /** @return array{active:bool,username:bool} */
    public function configurationStatus(): array
    {
        $active = false;
        try {
            $active = Registry::container()->get(ModuleService::class)->findByName('map-location-geonames', true) !== null;
        } catch (Throwable) {
            $active = false;
        }
        return ['active' => $active, 'username' => $this->username() !== ''];
    }

    public function authorityUri(): string { return self::AUTHORITY_URI; }

    public static function matchesAuthority(string $authority): bool
    {
        return preg_match('~^https?://(?:www\.)?geonames\.org/?$~i', trim($authority)) === 1;
    }

    public function identifier(string $value): ?ExternalIdentifier
    {
        $value = trim($value);
        if (preg_match('~^https?://(?:www\.)?geonames\.org/([1-9][0-9]{0,11})/?(?:[?#].*)?$~i', $value, $match) === 1) {
            $value = $match[1];
        }
        if (preg_match('/^[1-9][0-9]{0,11}$/', $value) !== 1) { return null; }
        return new ExternalIdentifier($this->key(), $value, self::AUTHORITY_URI, self::AUTHORITY_URI . $value . '/');
    }

    /** @return list<ExternalIdentifier> */
    public function sourceIdentifiers(string $gedcom): array
    {
        $identifiers = [];
        $inSource = false;
        $inData = false;

        foreach (preg_split('/\R/u', $gedcom) ?: [] as $line) {
            if (preg_match('/^1 SOUR(?:\s|$)/', $line) === 1) {
                $inSource = true;
                $inData = false;
                continue;
            }
            if (preg_match('/^1 /', $line) === 1) {
                $inSource = false;
                $inData = false;
                continue;
            }
            if (!$inSource) {
                continue;
            }
            if (preg_match('/^2 DATA(?:\s|$)/', $line) === 1) {
                $inData = true;
                continue;
            }
            if (preg_match('/^2 /', $line) === 1) {
                $inData = false;
                continue;
            }
            if ($inData && preg_match('/^3 TEXT\s+(.+)$/', $line, $match) === 1) {
                $identifier = $this->identifier($match[1]);
                if ($identifier !== null) {
                    $identifiers[$identifier->value] = $identifier;
                }
            }
        }

        return array_values($identifiers);
    }

    public function fetch(ExternalIdentifier $identifier, string $language): ?ExternalInformation
    {
        $username = $this->username();
        if ($username === '') { return null; }
        $language = $this->language($language);
        $cacheKey = 'id|' . $identifier->value . '|' . $language . '|' . $username;
        $payload = $this->cache->read('geonames', $cacheKey);
        if ($payload === null) {
            $payload = $this->request(self::GET_ENDPOINT, ['geonameId' => $identifier->value, 'lang' => $language, 'style' => 'FULL', 'username' => $username]);
            if ($payload === null) { return null; }
            $this->cache->write('geonames', $cacheKey, $payload);
        }
        return is_array($payload) ? $this->information($identifier, $payload, $language) : null;
    }

    /** @return list<array{id:string,label:string,url:string,description:?string,distanceKm:?float,details:list<array{label:string,value:string}>}> */
    public function search(string $place, string $language, bool $houseOnly = false, string $filterLevel = 'house'): array
    {
        $username = $this->username();
        $place = trim($place);
        if ($username === '') { return []; }
        if ($place === '' || mb_strlen($place) > 240) { return []; }
        $language = $this->language($language);
        // Bump the key whenever the search strategy changes, so cached
        // responses from the former name/q queries cannot mask new results.
        $cacheKey = 'search-v3|' . $language . '|' . $place . '|' . $username;
        $payload = $this->cache->read('geonames', $cacheKey);
        if ($payload === null) {
            // Prefer an exact place-name match. This resolves names such as
            // “Deutschland” and “European Union” before similarly named
            // places. The fallback `name` query supports composite addresses
            // such as “Klosterstraße 3, Ennetach, Mengen”. No feature-class
            // restriction is applied, so administrative objects remain
            // eligible.
            $query = ['name_equals' => $place, 'lang' => $language, 'maxRows' => 20, 'style' => 'FULL', 'username' => $username];
            $payload = $this->request(self::ENDPOINT, $query);
            if (is_array($payload) && ((array) ($payload['geonames'] ?? []) === [])) {
                $payload = $this->request(self::ENDPOINT, ['name' => $place, 'isNameRequired' => 'true', 'lang' => $language, 'maxRows' => 20, 'style' => 'FULL', 'username' => $username]);
            }
            if ($payload === null) { return []; }
            $this->cache->write('geonames', $cacheKey, $payload);
        }
        $results = [];
        foreach (is_array($payload['geonames'] ?? null) ? $payload['geonames'] : [] as $row) {
            if (!is_array($row) || !is_scalar($row['geonameId'] ?? null)) { continue; }
            $identifier = $this->identifier((string) $row['geonameId']);
            $label = is_string($row['name'] ?? null) ? trim($row['name']) : '';
            if ($identifier === null || $label === '') { continue; }
            $featureClass = (string) ($row['fcl'] ?? $row['fclass'] ?? '');
            $featureType = (string) ($row['fcode'] ?? '');
            $featureCode = strtoupper(trim($featureClass . '.' . $featureType, '.'));
            $allowedTypes = array_map('strtoupper', PlaceTypeFilterSettings::forLevel('geonames', $filterLevel));
            $matchesFilter = in_array($featureCode, $allowedTypes, true)
                || in_array(strtoupper($featureType), array_map(static fn (string $value): string => (string) (str_contains($value, '.') ? substr($value, strrpos($value, '.') + 1) : $value), $allowedTypes), true);
            if ($houseOnly && !$matchesFilter) { continue; }
            $results[] = [
                'id' => $identifier->value,
                'label' => $label,
                'url' => $identifier->url,
                'description' => $this->description($row),
                'distanceKm' => is_numeric($row['distance'] ?? null) ? (float) $row['distance'] : null,
                'details' => $this->details($row),
            ];
        }
        return $results;
    }

    /** @return array{label:string,url:string,details:list<array{label:string,value:string}>}|null */
    public function lookup(string $place, string $language): ?array
    {
        $result = $this->search($place, $language)[0] ?? null;
        if ($result === null) { return null; }
        return ['label' => $result['label'], 'url' => $result['url'], 'details' => $result['details']];
    }

    /** @param array<string,mixed> $query @return array<string,mixed>|null */
    private function request(string $endpoint, array $query): ?array
    {
        try {
            $response = $this->http->request('GET', $endpoint, $query, ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.3'], 10.0);
            if ($response === null) { return null; }
            if ($response->getStatusCode() !== 200) { return null; }
            $body = $response->getBody()->getContents();
            if (strlen($body) > 500_000) { return null; }
            $payload = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
            return is_array($payload) ? $payload : null;
        } catch (Throwable) { return null; }
    }

    private function language(string $language): string
    {
        return preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z]{2,4})?$/', $language) === 1 ? str_replace('_', '-', $language) : 'en';
    }

    /** Use the core GeoNames module's username, with the legacy site value as fallback. */
    private function username(): string
    {
        $fallback = trim(Site::getPreference('geonames'));
        try {
            $module = Registry::container()->get(ModuleService::class)->findByName('map-location-geonames', true);
            if ($module !== null && method_exists($module, 'getPreference')) {
                $username = trim((string) $module->getPreference('username', $fallback));
                if ($username !== '') { return $username; }
            }
        } catch (Throwable) {
            // The provider also works in installations without the core module.
        }
        return $fallback;
    }

    /** @param array<string,mixed> $row */
    private function description(array $row): ?string
    {
        foreach (['fcodeName', 'toponymName', 'countryName'] as $key) {
            if (is_string($row[$key] ?? null) && trim($row[$key]) !== '') { return trim($row[$key]); }
        }
        return null;
    }

    /** @param array<string,mixed> $row @return list<array{label:string,value:string}> */
    private function details(array $row): array
    {
        $details = [];
        // Keep the presentation order stable: administrative area, region,
        // country, then the remaining descriptive values.
        foreach (['adminName2' => 'Administrative area', 'adminName1' => 'Region', 'countryName' => 'Country', 'fcodeName' => 'Feature', 'elevation' => 'Elevation', 'population' => 'Population'] as $key => $label) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '' && (string) $value !== '0') { $details[] = ['label' => $label, 'value' => (string) $value]; }
        }

        // GeoNames may return several alternate names in FULL responses. Keep
        // the language code visible, discard malformed values, and deduplicate
        // names because the service can repeat the same value in its payload.
        $seen = [];
        foreach ((array) ($row['alternateNames'] ?? []) as $alternate) {
            if (!is_array($alternate)) { continue; }
            $name = trim((string) ($alternate['name'] ?? ''));
            $language = strtolower(trim((string) ($alternate['lang'] ?? '')));
            if ($name === '' || preg_match('/^[a-z]{2,3}(?:[-_][a-z]{2,4})?$/i', $language) !== 1) { continue; }
            $key = $language . "\0" . $name;
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $details[] = ['label' => 'Alternate name (' . $language . ')', 'value' => $name];
        }
        return $details;
    }

    /** @param array<string,mixed> $row */
    private function information(ExternalIdentifier $identifier, array $row, string $language): ExternalInformation
    {
        return new ExternalInformation('geonames', $identifier->value, $identifier->url, is_string($row['name'] ?? null) ? $row['name'] : null, $this->description($row), null, [], [], $this->details($row), [], [], [], [], null, $this->hierarchies($identifier->value, $language), Coordinates::fromArray($row));
    }

    /** @return list<list<array{label:string,value:string,url:string}>> */
    private function hierarchies(string $geonameId, string $language): array
    {
        $username = $this->username();
        if ($username === '') { return []; }
        $language = $this->language($language);
        $cacheKey = 'hierarchy|' . $geonameId . '|' . $language . '|' . $username;
        $payload = $this->cache->read('geonames', $cacheKey);
        if ($payload === null) {
            $payload = $this->request(self::HIERARCHY_ENDPOINT, ['geonameId' => $geonameId, 'lang' => $language, 'username' => $username]);
            if ($payload === null) { return []; }
            $this->cache->write('geonames', $cacheKey, $payload);
        }
        $branch = [];
        foreach ((array) ($payload['geonames'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['geonameId'])) { continue; }
            $id = (string) $row['geonameId'];
            $name = trim((string) ($row['name'] ?? $row['toponymName'] ?? ''));
            if ($name === '') { continue; }
            $branch[] = ['label' => trim((string) ($row['fcodeName'] ?? 'Place')), 'value' => $name, 'url' => self::AUTHORITY_URI . rawurlencode($id) . '/'];
        }
        return $branch === [] ? [] : [$branch];
    }
}
