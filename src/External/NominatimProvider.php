<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\HttpTransport;
use Throwable;

/** Read-only, cached contextual lookup through the public OSM Nominatim API. */
final class NominatimProvider
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const PHOTON_ENDPOINT = 'https://photon.komoot.io/api';
    private const GEOCODER_CACHE_TTL = 2592000; // 30 days
    private string $diagnostic = '';
    private bool $lastNominatimResponseEmpty = false;
    public function __construct(
        private readonly ?HttpTransport $http = null,
        private readonly ExternalProviderCache $cache = new ExternalProviderCache(),
    ) {
    }

    public function diagnostic(): string { return $this->diagnostic; }

    /** @return array{label:string,url:string,description:?string,details:list<array{label:string,value:string}>,addresses:list<ExternalAddress>,geometry:?array}|null */
    public function lookup(string $place, string $language, ?string $preferredLayer = null): ?array
    {
        // Vesta may provide a formatted place name (for example with a
        // <span dir="auto"> wrapper). Never send presentation markup to the
        // external search service.
        $inputPlace = trim(html_entity_decode(strip_tags($place), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Keep useful locality context while removing ISO codes and the
        // synthetic top-level "Earth" component.
        $components = array_values(array_filter(array_map('trim', explode(',', $inputPlace)), static function (string $component): bool {
            return $component !== '' && strcasecmp($component, 'Erde') !== 0 && preg_match('/^[A-Z]{2,3}$/', $component) !== 1;
        }));
        $place = implode(', ', array_slice($components, 0, 4));
        if ($place === '' || mb_strlen($place) > 240) {
            $this->diagnostic = 'Nominatim query="' . $place . '"; invalid or empty place query';
            return null;
        }

        $language = $this->language($language);
        // If the complete hierarchy has no result, retry once without its
        // highest component. This handles historical or obsolete parent names
        // (for example a former state) while keeping the request count bounded.
        $queries = [$place];
        if (count($components) > 1) {
            $queries[] = implode(', ', array_slice($components, 0, -1));
        }

        $payload = null;
        $attempts = [];
        foreach ($queries as $query) {
            // Version the key so results cached before polygon_geojson was
            // requested cannot suppress the map on an otherwise valid result.
                $cacheKey = 'v6|' . $language . '|' . ($preferredLayer ?? '') . '|' . $query;
            $payload = $this->cache->read('nominatim', $cacheKey, self::GEOCODER_CACHE_TTL);
            if ($payload !== null) {
                $attempts[] = $query . ' (cache hit)';
                $this->diagnostic = 'Nominatim query="' . $query . '"; cache hit';
                break;
            }
            if ($this->lastNominatimResponseEmpty && $query !== $queries[0]) {
                // allowRequest() deliberately rejects requests made within a
                // second. Wait out that interval before the bounded fallback.
                usleep(1_000_000);
            }
            if (!$this->cache->allowRequest('nominatim')) {
                $this->diagnostic = 'request throttled by local rate limit';
                break;
            }
            $attempts[] = $query . ' (request)';
            $payload = $this->request($query, $language, $preferredLayer);
            if ($payload !== null) {
                $this->cache->write('nominatim', $cacheKey, $payload);
                if ($query !== $queries[0]) {
                    $this->diagnostic = 'Nominatim hierarchy fallback query="' . $query . '"; ' . $this->diagnostic;
                }
                break;
            }
        }
        if ($payload === null) {
            // Photon is used only as a fallback for the initial lookup. A
            // successful response is cached separately; subsequent renders do
            // not contact either public geocoder. Photon normally returns a
            // point geometry, so polygon maps remain a Nominatim feature.
            $nominatimDiagnostic = $this->diagnostic;
            {
                // Version the Photon key when its candidate filtering changes,
                // so stale unrelated results cannot mask a better match.
                $photonKey = 'v10|' . $language . '|' . ($preferredLayer ?? '') . '|' . $place;
                $payload = $this->cache->read('photon', $photonKey, self::GEOCODER_CACHE_TTL);
                if ($payload !== null) {
                    $this->diagnostic = ($nominatimDiagnostic !== '' ? $nominatimDiagnostic . '; ' : '') . 'Photon query="' . $place . '"; fallback active (cache hit)';
                }
                if ($payload === null && $this->cache->allowRequest('photon')) {
                    $this->diagnostic = ($nominatimDiagnostic !== '' ? $nominatimDiagnostic . '; ' : '') . 'Photon query="' . $place . '"; fallback active (request)';
                $payload = $this->requestPhoton($place, $language, $preferredLayer);
                    if ($payload === null && $preferredLayer !== null) {
                        // Photon layer names are useful hints but are not
                        // supported consistently by every deployment.  A
                        // type-restricted empty response must therefore get
                        // one bounded retry without the layer; candidate
                        // ranking still prefers the requested type below.
                        $layerDiagnostic = $this->diagnostic;
                        $payload = $this->requestPhoton($place, $language, null);
                        $this->diagnostic = $layerDiagnostic . '; retry without Photon layer; ' . $this->diagnostic;
                    }
                    if ($payload !== null) {
                        $photonDiagnostic = $this->diagnostic;
                        $this->cache->write('photon', $photonKey, $payload);
                        $this->diagnostic = ($nominatimDiagnostic !== '' ? $nominatimDiagnostic . '; ' : '') . $photonDiagnostic;
                    } else {
                        $photonDiagnostic = $this->diagnostic;
                        $this->diagnostic = ($nominatimDiagnostic !== '' ? $nominatimDiagnostic . '; ' : '') . ($photonDiagnostic !== '' && $photonDiagnostic !== 'Photon fallback active (request)' ? $photonDiagnostic : 'Photon fallback returned no usable result');
                    }
                }
            }
            if ($payload === null) {
                if ($this->diagnostic === '') { $this->diagnostic = 'request returned no usable payload'; }
                return null;
            }
        }

        return $this->map($payload, $place);
    }

    /** @return array<string,mixed>|null */
    private function request(string $place, string $language, ?string $preferredLayer = null): ?array
    {
        $transport = $this->http ?? HttpTransport::default();
        $this->lastNominatimResponseEmpty = false;
        try {
                $query = [
                    'q' => $place,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'namedetails' => 1,
                    'extratags' => 1,
                    'polygon_geojson' => 1,
                    'limit' => 5,
                ];
                $response = $transport->request('GET', self::ENDPOINT, $query, [
                    'Accept' => 'application/json',
                    'Accept-Language' => $language,
                    'Referer' => $this->applicationUrl(),
                    'User-Agent' => 'webtrees External Places/0.4 (+' . $this->applicationUrl() . ')',
                ], 10.0);
                if ($response === null || $response->getStatusCode() !== 200) {
                    $this->diagnostic = 'Nominatim query="' . $place . '"; HTTP status ' . ($response?->getStatusCode() ?? 'no response');
                    return null;
                }
                $body = $response->getBody()->getContents();
                if (strlen($body) > 5_000_000) {
                    $this->diagnostic = 'Nominatim query="' . $place . '"; response exceeded 5000000 bytes';
                    return null;
                }
                $decoded = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) { return null; }
                $candidates = array_values(array_filter($decoded, 'is_array'));
                $rawCandidateCount = count($candidates);
                // Do not select linear/natural map features (rivers, roads,
                // railways, shops, etc.) when a place or administrative unit
                // is available for the same name.
                $candidates = array_values(array_filter($candidates, static function (array $candidate): bool {
                    $category = strtolower(trim((string) ($candidate['category'] ?? '')));
                    $addressType = strtolower(trim((string) ($candidate['addresstype'] ?? '')));
                    $excludedCategories = ['waterway', 'highway', 'railway', 'natural', 'landuse', 'amenity', 'shop', 'leisure', 'tourism'];
                    $allowedAddressTypes = ['house', 'building', 'village', 'town', 'city', 'municipality', 'county', 'state', 'country', 'continent', 'administrative'];
                    return !in_array($category, $excludedCategories, true)
                        && ($addressType === '' || in_array($addressType, $allowedAddressTypes, true));
                }));
                if ($candidates === []) {
                    $this->lastNominatimResponseEmpty = true;
                    $this->diagnostic = 'Nominatim query="' . $place . '"; HTTP 200; raw candidates=' . $rawCandidateCount . '; usable candidates=0';
                    return null;
                }
                $this->diagnostic = 'Nominatim query="' . $place . '"; HTTP 200; raw candidates=' . $rawCandidateCount . '; usable candidates=' . count($candidates);
                // Prefer a result whose locality matches the requested place
                // name. Nominatim ranks administrative regions highly for
                // ambiguous names, even when a settlement is available.
                $needle = mb_strtolower(trim($place));
                usort($candidates, static function (array $left, array $right) use ($needle, $preferredLayer): int {
                    $score = static function (array $candidate) use ($needle, $preferredLayer): int {
                        $address = is_array($candidate['address'] ?? null) ? $candidate['address'] : [];
                        $localities = array_filter(array_map('strval', [$candidate['name'] ?? '', $address['city'] ?? '', $address['town'] ?? '', $address['village'] ?? '', $address['municipality'] ?? '', $address['county'] ?? '', $address['state'] ?? '', $address['country'] ?? '']));
                        $display = mb_strtolower((string) ($candidate['display_name'] ?? ''));
                        $value = 0;
                        $addressType = strtolower((string) ($candidate['addresstype'] ?? ''));
                        $type = strtolower((string) ($candidate['type'] ?? ''));
                        $typeMatch = match ($preferredLayer) {
                            'county' => $addressType === 'county' || ($addressType === 'administrative' && str_contains($type, 'county')),
                            'state' => $addressType === 'state' || ($addressType === 'administrative' && str_contains($type, 'state')),
                            'city' => in_array($addressType, ['city', 'town'], true),
                            'locality' => in_array($addressType, ['village', 'hamlet', 'locality'], true),
                            'house' => in_array($addressType, ['house', 'building'], true),
                            default => false,
                        };
                        if ($typeMatch) { $value += 100; }
                        foreach ($localities as $locality) {
                            $locality = mb_strtolower($locality);
                            if ($locality === $needle) { $value += 100; }
                            elseif ($needle !== '' && str_contains($locality, $needle)) { $value += 20; }
                        }
                        if ($needle !== '' && str_contains($display, $needle)) { $value += 5; }
                        return $value;
                    };
                    return $score($right) <=> $score($left);
                });
                $payload = $candidates[0] ?? null;
                if (is_array($payload)) {
                    $this->diagnostic .= '; selected=' . (string) ($payload['display_name'] ?? $payload['name'] ?? '') . ' (type=' . (string) ($payload['addresstype'] ?? '') . ', osm=' . (string) ($payload['osm_type'] ?? '') . '/' . (string) ($payload['osm_id'] ?? '') . ')';
                }
                return is_array($payload) ? $payload : null;
        } catch (Throwable $exception) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function requestPhoton(string $place, string $language, ?string $preferredLayer = null): ?array
    {
        try {
            $query = [
                'q' => $place,
                'lang' => strtok($language, '-') ?: 'en',
                'limit' => 50,
            ];
            if ($preferredLayer !== null) { $query['layer'] = $preferredLayer; }
            $response = ($this->http ?? HttpTransport::default())->request('GET', self::PHOTON_ENDPOINT, $query, [
                'Accept' => 'application/json',
                'User-Agent' => 'webtrees External Places/0.4 (+' . $this->applicationUrl() . ')',
            ], 10.0);
            if ($response === null || $response->getStatusCode() !== 200) {
                $this->diagnostic = 'Photon HTTP status ' . ($response?->getStatusCode() ?? 'no response');
                return null;
            }
            $decoded = json_decode($response->getBody()->getContents(), true, 20, JSON_THROW_ON_ERROR);
            $rawFeatures = array_values(array_filter(is_array($decoded['features'] ?? null) ? $decoded['features'] : [], 'is_array'));
            $this->diagnostic = 'Photon HTTP 200; raw candidates=' . count($rawFeatures);
            $features = array_values(array_filter($rawFeatures, static function (array $feature): bool {
                $key = strtolower(trim((string) (($feature['properties'] ?? [])['osm_key'] ?? '')));
                return !in_array($key, ['waterway', 'highway', 'railway', 'natural', 'landuse', 'amenity', 'shop', 'leisure', 'tourism'], true);
            }));
            $this->diagnostic .= '; after category filter=' . count($features);
            $needle = mb_strtolower(trim($place));
            $queryTokens = array_values(array_filter(preg_split('/\s*,\s*|\s+/u', $needle) ?: [], static fn (string $token): bool => mb_strlen($token) >= 3));
            $matchedFeatures = array_values(array_filter($features, fn (array $feature): bool => $this->photonCandidateName(is_array($feature['properties'] ?? null) ? $feature['properties'] : []) !== '' && $this->photonCandidateMatches($feature, $queryTokens)));
            if ($matchedFeatures === []) {
                $this->diagnostic .= '; no candidate matched the query';
                $rejections = array_map(fn (array $feature): string => $this->photonCandidateRejection($feature, $queryTokens), $rawFeatures);
                $rejections = array_values(array_filter($rejections, static fn (string $value): bool => $value !== ''));
                if ($rejections !== []) {
                    $this->diagnostic .= '; rejected candidates: ' . implode(' | ', $rejections);
                }
                return null;
            }
            $features = $matchedFeatures;
            $this->diagnostic .= '; matched candidates=' . count($features);
            usort($features, static function (array $left, array $right) use ($needle, $queryTokens): int {
                $score = static function (array $feature) use ($needle, $queryTokens): int {
                    $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
                    $name = mb_strtolower(trim((string) ($properties['name'] ?? '')));
                    $label = mb_strtolower(trim(implode(', ', array_filter(array_map('strval', [
                        $properties['name'] ?? '', $properties['label'] ?? '', $properties['street'] ?? '', $properties['housenumber'] ?? '',
                        $properties['district'] ?? '', $properties['city'] ?? ($properties['locality'] ?? ''), $properties['village'] ?? '',
                        $properties['municipality'] ?? '', $properties['county'] ?? '', $properties['state'] ?? '', $properties['postcode'] ?? '', $properties['country'] ?? '',
                    ])))));
                    $tokenMatches = count(array_filter($queryTokens, static fn (string $token): bool => str_contains($label, $token)));
                    return ($name === $needle ? 100 : 0) + ($name !== '' && $needle !== '' && str_contains($name, $needle) ? 30 : 0) + ($label !== '' && $needle !== '' && str_contains($label, $needle) ? 5 : 0) + ($tokenMatches * 10);
                };
                return $score($right) <=> $score($left);
            });
            $feature = is_array($features[0] ?? null) ? $features[0] : null;
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : null;
            // Photon normally returns a point plus an extent. Convert the
            // extent to a transparent bounding polygon so the map shows the
            // complete area instead of zooming tightly to the centroid.
            $extent = is_array($properties['extent'] ?? null) ? array_map('floatval', $properties['extent']) : [];
            if (count($extent) === 4 && $geometry !== null) {
                // Photon extent order: min longitude, max latitude, max
                // longitude, min latitude.
                [$minLon, $maxLat, $maxLon, $minLat] = $extent;
                $geometry = ['type' => 'Polygon', 'coordinates' => [[
                    [$minLon, $minLat], [$minLon, $maxLat], [$maxLon, $maxLat], [$maxLon, $minLat], [$minLon, $minLat],
                ]]];
            }
            if (($properties['osm_type'] ?? '') === 'W' && is_scalar($properties['osm_id'] ?? null)) {
                $osmGeometry = $this->requestOsmWayGeometry((string) $properties['osm_id']);
                if ($osmGeometry !== null) {
                    $geometry = $osmGeometry;
                    $this->diagnostic .= '; exact OSM way geometry loaded';
                }
            }
            if (($properties['osm_type'] ?? '') === 'R' && is_scalar($properties['osm_id'] ?? null)) {
                $osmGeometry = $this->requestOsmRelationGeometry((string) $properties['osm_id']);
                if ($osmGeometry !== null) {
                    $geometry = $osmGeometry;
                    $this->diagnostic .= '; exact OSM relation geometry loaded';
                }
            }
            $name = $this->photonCandidateName($properties);
            if ($name === '') { $this->diagnostic .= '; selected candidate has no name'; return null; }
            $this->diagnostic .= '; selected=' . $name . ' (' . (string) ($properties['osm_key'] ?? '') . ':' . (string) ($properties['osm_value'] ?? '') . ', osm=' . (string) ($properties['osm_type'] ?? '') . '/' . (string) ($properties['osm_id'] ?? '') . ', country=' . (string) ($properties['country'] ?? '') . ', extent=' . implode(',', $extent) . ')';
            $parts = array_filter([
                $name,
                $properties['street'] ?? null,
                $properties['city'] ?? ($properties['locality'] ?? null),
                $properties['state'] ?? null,
                $properties['country'] ?? null,
            ], static fn ($value): bool => is_scalar($value) && trim((string) $value) !== '');
            return [
                'display_name' => implode(', ', array_map('strval', $parts)),
                'name' => $name,
                'category' => is_string($properties['osm_key'] ?? null) ? $properties['osm_key'] : null,
                'type' => is_string($properties['osm_value'] ?? null) ? $properties['osm_value'] : null,
                'osm_type' => is_string($properties['osm_type'] ?? null) ? $properties['osm_type'] : null,
                'osm_id' => is_scalar($properties['osm_id'] ?? null) ? (string) $properties['osm_id'] : null,
                'address' => array_filter([
                    'house_number' => $properties['housenumber'] ?? null,
                    'road' => $properties['street'] ?? null,
                    'postcode' => $properties['postcode'] ?? null,
                    'city' => $properties['city'] ?? ($properties['locality'] ?? null),
                    'state' => $properties['state'] ?? null,
                    'country' => $properties['country'] ?? null,
                ], static fn ($value): bool => is_scalar($value) && trim((string) $value) !== ''),
                'geojson' => $geometry,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $properties */
    private function photonCandidateName(array $properties): string
    {
        $name = trim((string) ($properties['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $street = trim((string) ($properties['street'] ?? ''));
        $houseNumber = trim((string) ($properties['housenumber'] ?? ''));
        $name = trim($street . ($houseNumber !== '' ? ' ' . $houseNumber : ''));

        return $name !== '' ? $name : trim((string) ($properties['label'] ?? ''));
    }

    /** @param array<string,mixed> $feature @param list<string> $tokens */
    private function photonCandidateMatches(array $feature, array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }

        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $name = $this->photonCandidateName($properties);
        $values = [
            $name,
            $properties['label'] ?? '',
            $properties['street'] ?? '',
            $properties['housenumber'] ?? '',
            $properties['district'] ?? '',
            $properties['locality'] ?? '',
            $properties['village'] ?? '',
            $properties['town'] ?? '',
            $properties['city'] ?? '',
            $properties['municipality'] ?? '',
            $properties['county'] ?? '',
            $properties['state'] ?? '',
            $properties['postcode'] ?? '',
            $properties['country'] ?? '',
            $properties['osm_value'] ?? '',
        ];
        $searchText = mb_strtolower(implode(' ', array_filter(array_map('strval', $values))));

        return (bool) array_filter($tokens, static fn (string $token): bool => str_contains($searchText, $token));
    }

    /** @param array<string,mixed> $feature @param list<string> $tokens */
    private function photonCandidateRejection(array $feature, array $tokens): string
    {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $name = $this->photonCandidateName($properties);
        $key = strtolower(trim((string) ($properties['osm_key'] ?? '')));
        $osmType = (string) ($properties['osm_type'] ?? '');
        $osmId = (string) ($properties['osm_id'] ?? '');
        $label = $name !== '' ? $name : '(ohne Namen)';
        $location = $osmType !== '' || $osmId !== '' ? ' osm=' . $osmType . '/' . $osmId : '';
        $excluded = ['waterway', 'highway', 'railway', 'natural', 'landuse', 'amenity', 'shop', 'leisure', 'tourism'];
        if (in_array($key, $excluded, true)) {
            return $label . $location . ': Kategorie ' . ($key !== '' ? $key : '(unbekannt)') . ' ausgeschlossen';
        }
        if ($name === '') {
            return $label . $location . ': kein auswertbarer Name';
        }
        if (!$this->photonCandidateMatches($feature, $tokens)) {
            return $label . $location . ': Suchbegriffe nicht in Name/Adresse/Verwaltung gefunden';
        }

        return '';
    }

    /** @return array{type:string,coordinates:array}|null */
    private function requestOsmWayGeometry(string $wayId): ?array
    {
        try {
            $response = ($this->http ?? HttpTransport::default())->request(
                'GET',
                'https://api.openstreetmap.org/api/0.6/way/' . rawurlencode($wayId) . '/full.json',
                [],
                ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.4 (+' . $this->applicationUrl() . ')'],
                10.0,
            );
            if ($response === null || $response->getStatusCode() !== 200) {
                return null;
            }
            $data = json_decode($response->getBody()->getContents(), true, 20, JSON_THROW_ON_ERROR);
            $elements = is_array($data['elements'] ?? null) ? $data['elements'] : [];
            $nodes = [];
            foreach ($elements as $element) {
                if (is_array($element) && ($element['type'] ?? '') === 'node' && isset($element['id'], $element['lat'], $element['lon'])) {
                    $nodes[(string) $element['id']] = [(float) $element['lon'], (float) $element['lat']];
                }
            }
            foreach ($elements as $element) {
                if (!is_array($element) || ($element['type'] ?? '') !== 'way' || !is_array($element['nodes'] ?? null)) {
                    continue;
                }
                $coordinates = [];
                foreach ($element['nodes'] as $nodeId) {
                    if (isset($nodes[(string) $nodeId])) { $coordinates[] = $nodes[(string) $nodeId]; }
                }
                if (count($coordinates) >= 4 && $coordinates[0] === $coordinates[count($coordinates) - 1]) {
                    return ['type' => 'Polygon', 'coordinates' => [$coordinates]];
                }
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }

    /** @return array{type:string,coordinates:array}|null */
    private function requestOsmRelationGeometry(string $relationId): ?array
    {
        try {
            $response = ($this->http ?? HttpTransport::default())->request('GET', 'https://api.openstreetmap.org/api/0.6/relation/' . rawurlencode($relationId) . '/full.json', [], ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.4 (+' . $this->applicationUrl() . ')'], 15.0);
            if ($response === null || $response->getStatusCode() !== 200) { return null; }
            $data = json_decode($response->getBody()->getContents(), true, 30, JSON_THROW_ON_ERROR);
            $elements = is_array($data['elements'] ?? null) ? $data['elements'] : [];
            $nodes = [];
            $ways = [];
            $outerRefs = [];
            foreach ($elements as $element) {
                if (!is_array($element)) { continue; }
                if (($element['type'] ?? '') === 'node' && isset($element['id'], $element['lat'], $element['lon'])) {
                    $nodes[(string) $element['id']] = [(float) $element['lon'], (float) $element['lat']];
                } elseif (($element['type'] ?? '') === 'way' && is_array($element['nodes'] ?? null)) {
                    $ways[(string) ($element['id'] ?? '')] = array_map('strval', $element['nodes']);
                } elseif (($element['type'] ?? '') === 'relation' && (string) ($element['id'] ?? '') === $relationId && is_array($element['members'] ?? null)) {
                    foreach ($element['members'] as $member) {
                        if (is_array($member) && ($member['type'] ?? '') === 'way' && ($member['role'] ?? '') === 'outer') { $outerRefs[] = (string) ($member['ref'] ?? ''); }
                    }
                }
            }
            $chains = [];
            foreach ($outerRefs as $ref) { if (isset($ways[$ref])) { $chains[] = $ways[$ref]; } }
            $rings = [];
            while ($chains !== []) {
                $chain = array_shift($chains);
                $merged = true;
                while ($merged && $chains !== []) {
                    $merged = false;
                    foreach ($chains as $index => $candidate) {
                        $start = $chain[0];
                        $end = $chain[count($chain) - 1];
                        if ($end === $candidate[0]) { $chain = array_merge($chain, array_slice($candidate, 1)); }
                        elseif ($end === $candidate[count($candidate) - 1]) { $chain = array_merge($chain, array_reverse(array_slice($candidate, 0, -1))); }
                        elseif ($start === $candidate[count($candidate) - 1]) { $chain = array_merge(array_slice($candidate, 0, -1), $chain); }
                        elseif ($start === $candidate[0]) { $chain = array_merge(array_reverse(array_slice($candidate, 1)), $chain); }
                        else { continue; }
                        array_splice($chains, $index, 1); $merged = true; break;
                    }
                }
                if (count($chain) >= 4 && $chain[0] === $chain[count($chain) - 1]) {
                    $ring = array_values(array_filter(array_map(static fn (string $id): ?array => $nodes[$id] ?? null, $chain)));
                    if (count($ring) >= 4) { $rings[] = $ring; }
                }
            }
            if ($rings === []) { return null; }
            return count($rings) === 1 ? ['type' => 'Polygon', 'coordinates' => $rings] : ['type' => 'MultiPolygon', 'coordinates' => array_map(static fn (array $ring): array => [$ring], $rings)];
        } catch (Throwable) { return null; }
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
        $osmPath = ['N' => 'node', 'W' => 'way', 'R' => 'relation'][$osmType] ?? strtolower($osmType);
        $url = $osmType !== '' && $osmId !== ''
            ? 'https://www.openstreetmap.org/' . $osmPath . '/' . rawurlencode($osmId)
            : 'https://www.openstreetmap.org/search?query=' . rawurlencode($place);

        $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $city = $this->firstAddressValue($address, ['village', 'town', 'city']);
        $administrativeValues = [];
        foreach (['town', 'city', 'municipality', 'county', 'state'] as $administrativeKey) {
            $value = $this->addressValue($address, $administrativeKey);
            if ($value !== null && $value !== $city) {
                $administrativeValues[] = $value;
            }
        }
        $administrativeArea = implode(', ', array_values(array_unique($administrativeValues)));
        $externalAddress = new ExternalAddress(
            houseNumber: $this->addressValue($address, 'house_number'),
            street: $this->addressValue($address, 'road'),
            postalCode: $this->addressValue($address, 'postcode'),
            city: $city,
            administrativeArea: $administrativeArea !== '' ? $administrativeArea : null,
        );
        $hasAddressComponents = $externalAddress->houseNumber !== null
            || $externalAddress->street !== null
            || $externalAddress->postalCode !== null
            || $externalAddress->city !== null;
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
        if (is_string($payload['type'] ?? null) && trim($payload['type']) !== '' && !in_array(strtolower(trim($payload['type'])), ['yes', 'no', 'administrative'], true)) {
            array_unshift($details, ['label' => 'Type', 'value' => trim($payload['type'])]);
        }
        $geometry = is_array($payload['geojson'] ?? null) && is_string($payload['geojson']['type'] ?? null) && is_array($payload['geojson']['coordinates'] ?? null)
            ? ['type' => $payload['geojson']['type'], 'coordinates' => $payload['geojson']['coordinates']]
            : null;
        return [
            'label' => $label,
            'url' => $url,
            'description' => is_string($payload['category'] ?? null) ? trim($payload['category']) : null,
            'details' => $details,
            'addresses' => $hasAddressComponents ? [$externalAddress] : [],
            'geometry' => $geometry,
        ];
    }

    /** @param array<string,mixed> $address */
    private function addressValue(array $address, string $key): ?string
    {
        $value = $address[$key] ?? null;
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** @param array<string,mixed> $address @param list<string> $keys */
    private function firstAddressValue(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->addressValue($address, $key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function language(string $language): string
    {
        return preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z]{2,4})?$/', $language) === 1 ? str_replace('_', '-', $language) : 'en';
    }

    private function applicationUrl(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return 'https://github.com/hartenthaler/hh_external_places';
        }
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        return ($https ? 'https://' : 'http://') . $host;
    }
}
