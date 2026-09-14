<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikibase;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\HttpTransport;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\LanguageCode;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\PlaceTypeFilterSettings;
use JsonException;
use Throwable;

/**
 * Safe common reader for supported Wikibase installations.
 *
 * The endpoint is selected by a provider name, never by GEDCOM content.  This
 * deliberately prevents external identifiers from becoming arbitrary requests.
 */
final class ReadOnlyWikibaseClient
{
    private const MAX_RESPONSE_BYTES = 1_000_000;

    /** @var array<string,string> */
    private const ENDPOINTS = [
        'wikidata' => 'https://www.wikidata.org/w/api.php',
        'factgrid' => 'https://database.factgrid.de/w/api.php',
    ];

    public function __construct(?HttpTransport $httpClient = null)
    {
        $this->httpClient = $httpClient ?? HttpTransport::default();
    }

    private readonly HttpTransport $httpClient;

    /** @return array<string,mixed>|null */
    public function entity(string $provider, string $itemId, string $language): ?array
    {
        $endpoint = self::ENDPOINTS[$provider] ?? null;
        if ($endpoint === null || preg_match('/^Q[1-9][0-9]*$/', $itemId) !== 1) {
            return null;
        }

        $language = $this->language($language);
        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                    'action'        => 'wbgetentities',
                    'format'        => 'json',
                    'formatversion' => '2',
                    'ids'           => $itemId,
                    'languages'     => $language . '|en',
                    // Sitelinks are needed for FactGrid's wikidatawiki link,
                    // which is the canonical cross-reference on some items.
                    'props'         => 'labels|descriptions|claims|sitelinks',
                ], ['Accept' => 'application/json', 'User-Agent' => 'webtrees Wikibase Places/0.2 (https://github.com/hartenthaler/hh_external_places)'], 6.0);
        } catch (Throwable) { return null; }

        if ($response === null) { return null; }
        $body = $response->getBody()->getContents();
        if ($response->getStatusCode() !== 200 || strlen($body) > self::MAX_RESPONSE_BYTES) {
            return null;
        }

        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /** @param list<string> $itemIds @return array<string,array<string,mixed>> */
    public function entities(string $provider, array $itemIds, string $language): array
    {
        $endpoint = self::ENDPOINTS[$provider] ?? null;
        $itemIds = array_values(array_unique(array_filter(array_slice($itemIds, 0, 20), static fn (string $id): bool => preg_match('/^Q[1-9][0-9]*$/', $id) === 1)));
        if ($endpoint === null || $itemIds === []) { return []; }

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'action' => 'wbgetentities', 'format' => 'json', 'formatversion' => '2',
                'ids' => implode('|', $itemIds), 'languages' => $this->language($language) . '|en',
                'props' => 'labels|claims',
            ], ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.3'], 6.0);
            if ($response === null || $response->getStatusCode() !== 200) { return []; }
            $body = $response->getBody()->getContents();
            if (strlen($body) > self::MAX_RESPONSE_BYTES) { return []; }
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) { return []; }

        $entities = [];
        foreach ($payload['entities'] ?? [] as $id => $entity) {
            if (is_string($id) && is_array($entity) && !array_key_exists('missing', $entity)) { $entities[$id] = $entity; }
        }
        return $entities;
    }

    /**
     * Read labels only.  This deliberately avoids claims and descriptions so
     * that large FactGrid entities cannot make the settings page response
     * exceed the safety limit.
     *
     * @param list<string> $itemIds
     * @return array<string,array<string,array{language:string,value:string}>>
     */
    public function labels(string $provider, array $itemIds, string $language): array
    {
        $endpoint = self::ENDPOINTS[$provider] ?? null;
        $itemIds = array_values(array_unique(array_filter(array_slice($itemIds, 0, 50), static fn (string $id): bool => preg_match('/^Q[1-9][0-9]*$/', $id) === 1)));
        if ($endpoint === null || $itemIds === []) {
            return [];
        }

        $labels = [];
        // Keep requests small.  FactGrid can be slow for a large batch even
        // when only labels are requested; chunks also avoid one timeout
        // hiding all labels on the settings page.
        foreach (array_chunk($itemIds, 5) as $chunk) {
            try {
                $response = $this->httpClient->request('GET', $endpoint, [
                    'action' => 'wbgetentities', 'format' => 'json', 'formatversion' => '2',
                    'ids' => implode('|', $chunk), 'languages' => $this->language($language) . '|en',
                    'props' => 'labels',
                ], ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.3'], 15.0);
                if ($response === null || $response->getStatusCode() !== 200) {
                    continue;
                }
                $body = $response->getBody()->getContents();
                if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                    continue;
                }
                $payload = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
                foreach ($payload['entities'] ?? [] as $id => $entity) {
                    if (is_string($id) && is_array($entity) && isset($entity['labels']) && is_array($entity['labels'])) {
                        $labels[$id] = $entity['labels'];
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        if ($provider === 'factgrid' && count($labels) < count($itemIds)) {
            foreach ($itemIds as $id) {
                if (isset($labels[$id])) {
                    continue;
                }
                foreach ($this->search('factgrid', $id, $language) as $result) {
                    if ($result['qid'] === $id && $result['label'] !== $id) {
                        $labels[$id] = [$this->language($language) => ['language' => $this->language($language), 'value' => $result['label']]];
                        break;
                    }
                }
            }
        }
        return $labels;
    }

    /** @return list<array{qid:string,label:string,description:?string}> */
    public function search(string $provider, string $term, string $language, bool $houseOnly = false, string $filterLevel = 'house'): array
    {
        $endpoint = self::ENDPOINTS[$provider] ?? null;
        $term = trim($term);
        if ($endpoint === null || mb_strlen($term) < 2 || mb_strlen($term) > 120) { return []; }
        try {
            $response = $this->httpClient->request('GET', $endpoint, ['action' => 'wbsearchentities', 'format' => 'json', 'language' => $this->language($language), 'uselang' => $this->language($language), 'search' => $term, 'limit' => 10, 'type' => 'item'], ['Accept' => 'application/json', 'User-Agent' => 'webtrees Wikibase Places/0.2'], 6.0);
            if ($response === null) { return []; }
            $body = $response->getBody()->getContents();
            if ($response->getStatusCode() !== 200 || strlen($body) > self::MAX_RESPONSE_BYTES) { return []; }
            $payload = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
        } catch (Throwable) { return []; }
        $results = [];
        foreach (array_slice($payload['search'] ?? [], 0, 10) as $item) {
            $qid = $item['id'] ?? null;
            if (!is_string($qid) || preg_match('/^Q[1-9][0-9]*$/', $qid) !== 1) { continue; }
            $results[] = ['qid' => $qid, 'label' => is_string($item['label'] ?? null) ? $item['label'] : $qid, 'description' => is_string($item['description'] ?? null) ? $item['description'] : null];
        }
        if (!$houseOnly || $results === []) {
            return $results;
        }

        $entities = $this->entities($provider, array_column($results, 'qid'), $language);
        return array_values(array_filter($results, function (array $result) use ($entities, $provider, $filterLevel): bool {
            return PlaceTypeFilterSettings::matchesWikibaseClaims($provider, (array) ($entities[$result['qid']]['claims'] ?? []), $filterLevel);
        }));
    }

    /** @return list<array{qid:string,label:string,description:?string,distanceKm:float}> */
    public function nearby(string $provider, float $latitude, float $longitude, float $radiusKm, string $language, bool $houseOnly = false, string $filterLevel = 'house'): array
    {
        if ($provider !== 'factgrid' || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) { return []; }
        $radiusKm = max(0.1, min(100.0, $radiusKm));
        // FactGrid's query service supports the box service reliably for its
        // P48 coordinate values.  Use a slightly enlarged bounding box and
        // apply the exact great-circle distance below.
        $latDelta = $radiusKm / 111.32;
        $lonScale = max(0.01, cos(deg2rad($latitude)));
        $lonDelta = $radiusKm / (111.32 * $lonScale);
        $southWest = sprintf('Point(%.6F %.6F)', $longitude - $lonDelta, $latitude - $latDelta);
        $northEast = sprintf('Point(%.6F %.6F)', $longitude + $lonDelta, $latitude + $latDelta);
        $houseTypes = array_values(array_filter(PlaceTypeFilterSettings::forLevel('factgrid', $filterLevel), static fn (string $value): bool => preg_match('/^Q[1-9][0-9]*$/', $value) === 1));
        $types = $houseOnly && $houseTypes !== [] ? ' VALUES ?houseType { ' . implode(' ', array_map(static fn (string $value): string => 'wd:' . $value, $houseTypes)) . ' } ?item wdt:P2 ?houseType .' : '';
        // FactGrid calls its coordinate-location property P48 (the local
        // equivalent of Wikidata's P625).
        $query = 'SELECT DISTINCT ?item ?itemLabel ?itemDescription ?coord WHERE {' . $types . ' SERVICE wikibase:box { ?item wdt:P48 ?coord . bd:serviceParam wikibase:cornerSouthWest "' . $southWest . '"^^geo:wktLiteral . bd:serviceParam wikibase:cornerNorthEast "' . $northEast . '"^^geo:wktLiteral . } SERVICE wikibase:label { bd:serviceParam wikibase:language "' . $this->language($language) . ',en". } } LIMIT 20';
        try {
            $response = $this->httpClient->request('GET', 'https://database.factgrid.de/sparql', ['format' => 'json', 'query' => $query], ['Accept' => 'application/sparql-results+json', 'User-Agent' => 'webtrees Wikibase Places/0.2'], 8.0);
            if ($response === null) { return []; }
            $body = $response->getBody()->getContents();
            if ($response->getStatusCode() !== 200 || strlen($body) > self::MAX_RESPONSE_BYTES) { return []; }
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) { return []; }
        $results = [];
        foreach (array_slice($payload['results']['bindings'] ?? [], 0, 20) as $binding) {
            $uri = $binding['item']['value'] ?? null;
            $coord = $binding['coord']['value'] ?? null;
            if (!is_string($uri) || !is_string($coord) || preg_match('~/Q([1-9][0-9]*)$~', $uri, $qid) !== 1) { continue; }
            // Wikibase normally serializes coordinates as Point(lon lat);
            // FactGrid exports may also use the compact @lat/lon notation.
            if (preg_match('/Point\\(([-0-9.]+) ([-0-9.]+)\\)/', $coord, $point) === 1) {
                $candidateLongitude = (float) $point[1];
                $candidateLatitude = (float) $point[2];
            } elseif (preg_match('/@([-0-9.]+)\\/([-0-9.]+)/', $coord, $point) === 1) {
                $candidateLatitude = (float) $point[1];
                $candidateLongitude = (float) $point[2];
            } else {
                continue;
            }
            $distance = $this->distanceKm($latitude, $longitude, $candidateLatitude, $candidateLongitude);
            if ($distance > $radiusKm) { continue; }
            $results[] = ['qid' => 'Q' . $qid[1], 'label' => (string) ($binding['itemLabel']['value'] ?? ('Q' . $qid[1])), 'description' => isset($binding['itemDescription']['value']) ? (string) $binding['itemDescription']['value'] : null, 'distanceKm' => $distance];
        }
        usort($results, static fn (array $a, array $b): int => $a['distanceKm'] <=> $b['distanceKm']);
        return $results;
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earth * 2 * asin(min(1.0, sqrt($a)));
    }

    private function language(string $language): string
    {
        return LanguageCode::normalize($language) ?: 'en';
    }
}
