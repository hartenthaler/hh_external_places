<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\HttpTransport;
use Throwable;

/** Read-only, cached contextual lookup through the public OSM Nominatim API. */
final class NominatimProvider
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    public function __construct(
        private readonly ?HttpTransport $http = null,
        private readonly ExternalProviderCache $cache = new ExternalProviderCache(),
    ) {
    }

    /** @return array{label:string,url:string,description:?string,details:list<array{label:string,value:string}>}|null */
    public function lookup(string $place, string $language): ?array
    {
        // Vesta may provide a formatted place name (for example with a
        // <span dir="auto"> wrapper). Never send presentation markup to the
        // external search service.
        $place = trim(html_entity_decode(strip_tags($place), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($place === '' || mb_strlen($place) > 240) {
            return null;
        }

        $language = $this->language($language);
        // One page render must never trigger several immediate requests. This
        // is required by the public Nominatim usage policy (one request/sec).
        $queries = [$place];

        $payload = null;
        $attempts = [];
        foreach ($queries as $query) {
            // Version the key so results cached before polygon_geojson was
            // requested cannot suppress the map on an otherwise valid result.
            $cacheKey = 'v2|' . $language . '|' . $query;
            $payload = $this->cache->read('nominatim', $cacheKey);
            if ($payload !== null) {
                $attempts[] = $query . ' (cache hit)';
                break;
            }
            if (!$this->cache->allowRequest('nominatim')) {
                break;
            }
            $attempts[] = $query . ' (request)';
            $payload = $this->request($query, $language);
            if ($payload !== null) {
                $this->cache->write('nominatim', $cacheKey, $payload);
                break;
            }
        }
        if ($payload === null) {
            return null;
        }

        return $this->map($payload, $place);
    }

    /** @return array<string,mixed>|null */
    private function request(string $place, string $language): ?array
    {
        $transport = $this->http ?? HttpTransport::default();
        try {
                $response = $transport->request('GET', self::ENDPOINT, [
                    'q' => $place,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'namedetails' => 1,
                    'extratags' => 1,
                    'polygon_geojson' => 1,
                    'limit' => 1,
                ], [
                    'Accept' => 'application/json',
                    'Accept-Language' => $language,
                    'Referer' => 'https://github.com/hartenthaler/hh_external_places',
                    'User-Agent' => 'webtrees External Places/0.3 (+https://github.com/hartenthaler/hh_external_places)',
                ], 10.0);
                if ($response === null || $response->getStatusCode() !== 200) {
                    return null;
                }
                $body = $response->getBody()->getContents();
                if (strlen($body) > 500_000) {
                    return null;
                }
                $decoded = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
                $payload = is_array($decoded) ? ($decoded[0] ?? null) : null;
                if ($payload === null) {
                }
                return is_array($payload) ? $payload : null;
        } catch (Throwable $exception) {
            return null;
        }
    }

    /** @param array<string,mixed> $payload */
    private function map(array $payload, string $place): ?array
    {
        $label = is_string($payload['display_name'] ?? null) ? trim($payload['display_name']) : '';
        if ($label === '') {
            $label = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        }
        if ($label === '') {
            return null;
        }

        $osmType = is_string($payload['osm_type'] ?? null) ? strtoupper($payload['osm_type']) : '';
        $osmId = is_scalar($payload['osm_id'] ?? null) ? (string) $payload['osm_id'] : '';
        $url = $osmType !== '' && $osmId !== ''
            ? 'https://www.openstreetmap.org/' . strtolower($osmType) . '/' . rawurlencode($osmId)
            : 'https://www.openstreetmap.org/search?query=' . rawurlencode($place);

        $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $details = [];
        foreach ([
            'house_number' => 'House number',
            'road' => 'Street',
            'postcode' => 'Postal code',
            'village' => 'Place',
            'town' => 'Place',
            'city' => 'Place',
            'municipality' => 'Municipality',
            'county' => 'County',
            'state' => 'State',
            'country' => 'Country',
        ] as $key => $detailLabel) {
            $value = $address[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $details[] = ['label' => $detailLabel, 'value' => trim((string) $value)];
            }
        }
        if (is_string($payload['type'] ?? null) && trim($payload['type']) !== '' && !in_array(strtolower(trim($payload['type'])), ['yes', 'no'], true)) {
            array_unshift($details, ['label' => 'Type', 'value' => trim($payload['type'])]);
        }
        $geometry = is_array($payload['geojson'] ?? null) && is_string($payload['geojson']['type'] ?? null) && is_array($payload['geojson']['coordinates'] ?? null)
            ? ['type' => $payload['geojson']['type'], 'coordinates' => $payload['geojson']['coordinates']]
            : null;
        return ['label' => $label, 'url' => $url, 'description' => is_string($payload['category'] ?? null) ? trim($payload['category']) : null, 'details' => $details, 'geometry' => $geometry];
    }

    private function language(string $language): string
    {
        return preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z]{2,4})?$/', $language) === 1 ? str_replace('_', '-', $language) : 'en';
    }
}
