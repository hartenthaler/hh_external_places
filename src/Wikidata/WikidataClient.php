<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\WikidataIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\HttpTransport;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\LanguageCode;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\PlaceTypeFilterSettings;
use JsonException;
use Throwable;

/**
 * Small, read-only client for the public Wikidata entity endpoint.
 *
 * The endpoint, query parameters, timeout and response size are fixed so a
 * GEDCOM identifier can never become an arbitrary outbound request.
 */
final class WikidataClient
{
    private const ENDPOINT = 'https://www.wikidata.org/w/api.php';
    private const QUERY_ENDPOINT = 'https://query.wikidata.org/sparql';
    private const MAX_RESPONSE_BYTES = 1_000_000;
    private const MAX_SEARCH_RESULTS = 10;
    private const MAX_NEARBY_RESULTS = 20;

    public function __construct(
        ?HttpTransport $httpClient = null,
        private readonly WikidataEntityMapper $mapper = new WikidataEntityMapper(),
    ) {
        $this->httpClient = $httpClient ?? HttpTransport::default();
    }

    private readonly HttpTransport $httpClient;

    public function fetch(WikidataIdentifier $identifier, string $language): ?WikidataEntity
    {
        $language = $this->language($language);

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'allow_redirects' => false,
                'connect_timeout' => 3.0,
                'headers'         => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'webtrees External Places/0.1 (https://github.com/hartenthaler/hh_external_places)',
                ],
                'http_errors'     => false,
                'query'           => [
                    'action'        => 'wbgetentities',
                    'format'        => 'json',
                    'formatversion' => '2',
                    'ids'           => $identifier->qid(),
                    'languages'     => $language . '|en',
                    'props'         => 'labels|descriptions|claims',
                    // We only need values, qualifiers and ranks for the
                    // provider data model. Omitting hashes and references
                    // keeps large entities such as Q183 below our bounded
                    // response-size limit while retaining all P31 values.
                    'clprop'        => 'value|qualifiers|rank',
                ],
                'timeout'         => 6.0,
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response === null || $response->getStatusCode() !== 200) {
            return $this->fetchCompact($identifier, $language);
        }

        $body = $response->getBody()->getContents();
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            return $this->fetchCompact($identifier, $language);
        }

        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->fetchCompact($identifier, $language);
        }

        return is_array($payload) ? $this->mapper->map($identifier, $payload, $language) : null;
    }

    /**
     * Fetch a large entity in bounded pieces. Countries and similar items can
     * have thousands of unrelated statements, making a full entity response
     * exceed the normal safety limit. Only properties consumed by the module
     * are requested in the fallback path.
     */
    private function fetchCompact(WikidataIdentifier $identifier, string $language): ?WikidataEntity
    {
        $entityResponse = $this->httpClient->request('GET', self::ENDPOINT, [
            'action' => 'wbgetentities', 'format' => 'json', 'formatversion' => '2',
            'ids' => $identifier->qid(), 'languages' => $language . '|en',
            'props' => 'labels|descriptions',
        ], ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.1 (https://github.com/hartenthaler/hh_external_places)'], 6.0);
        if ($entityResponse === null || $entityResponse->getStatusCode() !== 200) {
            return null;
        }
        $entityBody = $entityResponse->getBody()->getContents();
        if (strlen($entityBody) > 100_000) {
            return null;
        }
        try {
            $payload = json_decode($entityBody, true, 20, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($payload) || !is_array($payload['entities'][$identifier->qid()] ?? null)) {
            return null;
        }

        $claims = [];
        $claimsResponse = $this->httpClient->request('GET', self::ENDPOINT, [
            'action' => 'wbgetclaims', 'format' => 'json', 'formatversion' => '2',
            'entity' => $identifier->qid(),
            'property' => 'P31|P18|P127|P466|P669|P6375|P14871|P2503|P1566',
        ], ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.1 (https://github.com/hartenthaler/hh_external_places)'], 6.0);
        if ($claimsResponse !== null && $claimsResponse->getStatusCode() === 200) {
            $claimsBody = $claimsResponse->getBody()->getContents();
            if (strlen($claimsBody) <= 900_000) {
                try {
                    $claimsPayload = json_decode($claimsBody, true, 32, JSON_THROW_ON_ERROR);
                    $claims = is_array($claimsPayload['claims'] ?? null) ? $claimsPayload['claims'] : [];
                } catch (JsonException) {
                    $claims = [];
                }
            }
        }
        // Some Wikibase versions accept only one property per wbgetclaims
        // request. Retry the bounded property set individually when the
        // combined request returned no usable claims.
        if ($claims === []) {
            foreach (['P31', 'P18', 'P127', 'P466', 'P669', 'P6375', 'P14871', 'P2503', 'P1566'] as $property) {
                $single = $this->httpClient->request('GET', self::ENDPOINT, [
                    'action' => 'wbgetclaims', 'format' => 'json', 'formatversion' => '2',
                    'entity' => $identifier->qid(), 'property' => $property,
                ], ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.1 (https://github.com/hartenthaler/hh_external_places)'], 6.0);
                if ($single === null || $single->getStatusCode() !== 200) { continue; }
                $singleBody = $single->getBody()->getContents();
                if (strlen($singleBody) > 200_000) { continue; }
                try {
                    $singlePayload = json_decode($singleBody, true, 32, JSON_THROW_ON_ERROR);
                    if (is_array($singlePayload['claims'] ?? null)) {
                        $claims = array_merge($claims, $singlePayload['claims']);
                    }
                } catch (JsonException) {
                    continue;
                }
            }
        }
        $payload['entities'][$identifier->qid()]['claims'] = $claims;
        return $this->mapper->map($identifier, $payload, $language);
    }

    /**
     * Load only the claims needed by a type filter. Large entities such as
     * countries can exceed the bounded size of the full display response.
     */
    private function fetchForFilter(WikidataIdentifier $identifier): ?WikidataEntity
    {
        try {
            // wbgetclaims returns only the requested property and avoids the
            // large, unrelated payload of wbgetentities for countries.
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'allow_redirects' => false,
                'connect_timeout' => 3.0,
                'headers'         => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'webtrees External Places/0.1 (https://github.com/hartenthaler/hh_external_places)',
                ],
                'http_errors' => false,
                'query'       => [
                    'action'        => 'wbgetclaims',
                    'format'        => 'json',
                    'formatversion' => '2',
                    'entity'        => $identifier->qid(),
                    'property'      => 'P31',
                ],
                'timeout' => 6.0,
            ]);
            if ($response === null || $response->getStatusCode() !== 200) {
                return null;
            }
            $body = $response->getBody()->getContents();
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                return null;
            }
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($payload) && is_array($payload['claims'] ?? null)
            ? $this->mapper->mapClaims($identifier, $payload['claims'])
            : null;
    }

    /**
     * Resolve a small, already validated set of item identifiers for display.
     *
     * @param list<string> $qids
     * @return array<string, string>
     */
    public function labels(array $qids, string $language): array
    {
        $qids = array_values(array_unique(array_filter($qids, static fn (string $qid): bool => preg_match('/^Q[1-9][0-9]*$/', $qid) === 1)));
        if ($qids === []) {
            return [];
        }

        $language = $this->language($language);
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'allow_redirects' => false,
                'connect_timeout' => 3.0,
                'headers'         => ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.1 (https://github.com/hartenthaler/hh_external_places)'],
                'http_errors'     => false,
                'query'           => ['action' => 'wbgetentities', 'format' => 'json', 'formatversion' => '2', 'ids' => implode('|', array_slice($qids, 0, 10)), 'languages' => $language . '|en', 'props' => 'labels'],
                'timeout'         => 6.0,
            ]);
            $payload = json_decode($response->getBody()->getContents(), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $labels = [];
        foreach (($payload['entities'] ?? []) as $qid => $entity) {
            $label = $entity['labels'][$language]['value'] ?? $entity['labels']['en']['value'] ?? null;
            if (is_string($qid) && is_string($label)) {
                $labels[$qid] = $label;
            }
        }

        return $labels;
    }

    /**
     * Resolve a bounded set of externally published person/organisation facts.
     * No result is associated with a local webtrees individual.
     *
     * @param list<string> $qids
     * @return array<string, WikidataPerson>
     */
    public function people(array $qids, string $language): array
    {
        $qids = array_values(array_unique(array_filter($qids, static fn (string $qid): bool => preg_match('/^Q[1-9][0-9]*$/', $qid) === 1)));
        if ($qids === []) {
            return [];
        }

        $language = $this->language($language);
        $people = [];
        foreach (array_chunk(array_slice($qids, 0, 20), 10) as $chunk) {
            try {
                $response = $this->httpClient->request('GET', self::ENDPOINT, [
                    'allow_redirects' => false,
                    'connect_timeout' => 3.0,
                    'headers'         => ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.1 (https://github.com/hartenthaler/hh_external_places)'],
                    'http_errors'     => false,
                    'query'           => ['action' => 'wbgetentities', 'format' => 'json', 'formatversion' => '2', 'ids' => implode('|', $chunk), 'languages' => $language . '|en', 'props' => 'labels|claims'],
                    'timeout'         => 6.0,
                ]);
                $body = $response->getBody()->getContents();
                if ($response->getStatusCode() !== 200 || strlen($body) > self::MAX_RESPONSE_BYTES) {
                    continue;
                }
                $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            foreach ($payload['entities'] ?? [] as $qid => $entity) {
                if (!is_string($qid) || !is_array($entity)) {
                    continue;
                }
                $label = $entity['labels'][$language]['value'] ?? $entity['labels']['en']['value'] ?? null;
                $claims = is_array($entity['claims'] ?? null) ? $entity['claims'] : [];
                $people[$qid] = new WikidataPerson(
                    $qid,
                    is_string($label) ? $label : null,
                    $this->claimDate($claims['P569'] ?? []),
                    $this->claimDate($claims['P570'] ?? []),
                    $this->personExternalLinks($claims),
                );
            }
        }

        return $people;
    }

    /**
     * Build links for deliberately supported external person identifiers.
     * Values are validated before being appended to a fixed provider URL.
     *
     * @param array<string,mixed> $claims
     * @return array<string,string>
     */
    private function personExternalLinks(array $claims): array
    {
        $links = [];
        // WikiTree IDs are usually ASCII, but valid IDs may preserve
        // diacritics from a surname (for example Baumgärtner-1891).
        $wikitree = $this->claimString($claims['P2949'] ?? [], '/^[\p{L}][\p{L}\p{M}0-9._-]{0,119}$/u');
        if ($wikitree !== null) {
            $links['WikiTree'] = 'https://www.wikitree.com/wiki/' . rawurlencode($wikitree);
        }

        return $links;
    }

    /** @param mixed $statements */
    private function claimString(mixed $statements, string $pattern): ?string
    {
        foreach (is_array($statements) ? $statements : [] as $statement) {
            $value = $statement['mainsnak']['datavalue']['value'] ?? null;
            if (!is_string($value)) {
                continue;
            }
            // Wikibase normally returns an external identifier as plain text.
            // Accept a formatter URL as well, since older/imported statements
            // can contain the canonical WikiTree URL instead.
            $value = trim($value);
            if (preg_match('~^https?://(?:www\\.)?wikitree\\.com/wiki/(.+)$~i', $value, $match) === 1) {
                $value = rawurldecode($match[1]);
            }
            if (preg_match($pattern, $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    /** @return list<WikidataSearchResult> */
    public function search(string $term, string $language, bool $houseOnly = false, string $filterLevel = 'house'): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2 || mb_strlen($term) > 120) {
            return [];
        }

        $language = $this->language($language);
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'allow_redirects' => false,
                'connect_timeout' => 3.0,
                'headers'         => ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.1 (https://github.com/hartenthaler/hh_external_places)'],
                'http_errors'     => false,
                'query'           => [
                    'action'   => 'wbsearchentities',
                    'format'   => 'json',
                    'language' => $language,
                    'limit'    => self::MAX_SEARCH_RESULTS,
                    'search'   => $term,
                    'type'     => 'item',
                    'uselang'  => $language,
                ],
                'timeout'         => 6.0,
            ]);
            $body = $response->getBody()->getContents();
            if ($response->getStatusCode() !== 200 || strlen($body) > self::MAX_RESPONSE_BYTES) {
                return [];
            }
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $results = [];
        foreach (array_slice($payload['search'] ?? [], 0, self::MAX_SEARCH_RESULTS) as $candidate) {
            $qid   = $candidate['id'] ?? null;
            $label = $candidate['label'] ?? null;
            if (!is_string($qid) || preg_match('/^Q[1-9][0-9]*$/', $qid) !== 1 || !is_string($label) || $label === '') {
                continue;
            }
            $description = $candidate['description'] ?? null;
            $results[] = new WikidataSearchResult($qid, $label, is_string($description) && $description !== '' ? $description : null);
        }

        $results = $this->localiseSearchResults($results, $language);
        if (!$houseOnly) {
            return $results;
        }

        return array_values(array_filter($results, function (WikidataSearchResult $result) use ($language, $filterLevel): bool {
            $identifier = WikidataIdentifier::tryFrom($result->qid);
            $entity = $identifier === null ? null : $this->fetchForFilter($identifier);
            return $entity !== null && PlaceTypeFilterSettings::matchesTypeIds('wikidata', $entity->instanceOfQids, $filterLevel);
        }));
    }

    /**
     * Find a deliberately small number of places around known shared-place
     * coordinates.  Coordinates, radius and endpoint are all constrained so
     * the operation cannot be used as an arbitrary outbound request.
     *
     * @return list<WikidataNearbyCandidate>
     */
    public function nearby(float $latitude, float $longitude, float $radiusKm, string $language, string $placeName = '', bool $houseOnly = false, string $filterLevel = 'house'): array
    {
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return [];
        }

        $language = $this->language($language);
        $radiusKm = max(0.1, min(100.0, $radiusKm));
        $center   = sprintf('Point(%.6F %.6F)', $longitude, $latitude);
        $houseTypes = array_values(array_filter(PlaceTypeFilterSettings::forLevel('wikidata', $filterLevel), static fn (string $value): bool => preg_match('/^Q[1-9][0-9]*$/', $value) === 1));
        $types = $houseOnly && $houseTypes !== [] ? ' VALUES ?houseType { ' . implode(' ', array_map(static fn (string $value): string => 'wd:' . $value, $houseTypes)) . ' } ?item wdt:P31/wdt:P279* ?houseType .' : '';
        $query    = 'SELECT ?item ?itemLabel ?itemDescription ?coord WHERE {' . $types
            . ' SERVICE wikibase:around { ?item wdt:P625 ?coord .'
            . ' bd:serviceParam wikibase:center "' . $center . '"^^geo:wktLiteral .'
            . ' bd:serviceParam wikibase:radius "' . number_format($radiusKm, 3, '.', '') . '" . }'
            . ' SERVICE wikibase:label { bd:serviceParam wikibase:language "' . $language . ',en". }'
            . ' } LIMIT ' . self::MAX_NEARBY_RESULTS;

        try {
            $response = $this->httpClient->request('GET', self::QUERY_ENDPOINT, [
                'allow_redirects' => false,
                'connect_timeout' => 3.0,
                'headers'         => ['Accept' => 'application/sparql-results+json', 'User-Agent' => 'webtrees External Places/0.1 (https://github.com/hartenthaler/hh_external_places)'],
                'http_errors'     => false,
                'query'           => ['format' => 'json', 'query' => $query],
                'timeout'         => 8.0,
            ]);
            $body = $response->getBody()->getContents();
            if ($response->getStatusCode() !== 200 || strlen($body) > self::MAX_RESPONSE_BYTES) {
                return [];
            }
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $candidates = [];
        foreach (array_slice($payload['results']['bindings'] ?? [], 0, self::MAX_NEARBY_RESULTS) as $binding) {
            $item  = $binding['item']['value'] ?? null;
            $label = $binding['itemLabel']['value'] ?? null;
            $coord = $binding['coord']['value'] ?? null;
            if (!is_string($item) || !is_string($label) || !is_string($coord) || preg_match('~/(Q[1-9][0-9]*)$~', $item, $qid) !== 1) {
                continue;
            }
            $coordinates = $this->wktCoordinates($coord);
            if ($coordinates === null) {
                continue;
            }
            $distance = $this->distanceKm($latitude, $longitude, $coordinates['latitude'], $coordinates['longitude']);
            $description = $binding['itemDescription']['value'] ?? null;
            $candidates[] = new WikidataNearbyCandidate(
                $qid[1],
                $label,
                is_string($description) && $description !== '' ? $description : null,
                $distance,
                $this->rankingScore($label, $placeName, $distance),
            );
        }

        usort($candidates, static fn (WikidataNearbyCandidate $a, WikidataNearbyCandidate $b): int => $b->score <=> $a->score ?: $a->distanceKm <=> $b->distanceKm);

        return $candidates;
    }

    private function language(string $language): string
    {
        // Wikidata labels use ISO 639-1 keys such as "de". The shared
        // normalizer also accepts webtrees regional tags such as "de-DE".
        return LanguageCode::normalize($language) ?: 'en';
    }

    /** @param mixed $statements */
    private function claimDate(mixed $statements): ?string
    {
        foreach (is_array($statements) ? $statements : [] as $statement) {
            $time = $statement['mainsnak']['datavalue']['value']['time'] ?? null;
            $precision = $statement['mainsnak']['datavalue']['value']['precision'] ?? null;
            if (!is_string($time) || !is_int($precision) || preg_match('/^[+-](\\d{4,})-(\\d{2})-(\\d{2})T/', $time, $date) !== 1) {
                continue;
            }
            return match (true) {
                $precision >= 11 => $date[1] . '-' . $date[2] . '-' . $date[3],
                $precision >= 10 => $date[1] . '-' . $date[2],
                default => $date[1],
            };
        }
        return null;
    }

    /**
     * The search endpoint can return a description in an interface fallback
     * language.  Refresh the small, already bounded result set through the
     * entity endpoint to prefer the actual requested content language.
     *
     * @param list<WikidataSearchResult> $results
     * @return list<WikidataSearchResult>
     */
    private function localiseSearchResults(array $results, string $language): array
    {
        if ($results === []) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'allow_redirects' => false,
                'connect_timeout' => 3.0,
                'headers'         => ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.1 (https://github.com/hartenthaler/hh_external_places)'],
                'http_errors'     => false,
                'query'           => [
                    'action' => 'wbgetentities', 'format' => 'json', 'formatversion' => '2',
                    'ids' => implode('|', array_map(static fn (WikidataSearchResult $result): string => $result->qid, $results)),
                    'languages' => $language . '|en', 'props' => 'labels|descriptions',
                ],
                'timeout' => 6.0,
            ]);
            $body = $response->getBody()->getContents();
            if ($response->getStatusCode() !== 200 || strlen($body) > self::MAX_RESPONSE_BYTES) {
                return $results;
            }
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $results;
        }

        $localized = [];
        foreach ($results as $result) {
            $entity = $payload['entities'][$result->qid] ?? [];
            $label = $entity['labels'][$language]['value'] ?? $entity['labels']['en']['value'] ?? $result->label;
            $description = $entity['descriptions'][$language]['value'] ?? $entity['descriptions']['en']['value'] ?? $result->description;
            $localized[] = new WikidataSearchResult($result->qid, is_string($label) ? $label : $result->label, is_string($description) && $description !== '' ? $description : null);
        }

        return $localized;
    }

    /** @return array{latitude:float,longitude:float}|null */
    private function wktCoordinates(string $wkt): ?array
    {
        if (preg_match('/^Point\\((-?[0-9.]+) (-?[0-9.]+)\\)$/', $wkt, $matches) !== 1) {
            return null;
        }

        return ['latitude' => (float) $matches[2], 'longitude' => (float) $matches[1]];
    }

    private function distanceKm(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $a = sin(deg2rad($latitude2 - $latitude1) / 2) ** 2
            + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin(deg2rad($longitude2 - $longitude1) / 2) ** 2;

        return 6371.0088 * 2 * asin(min(1.0, sqrt($a)));
    }

    private function rankingScore(string $label, string $placeName, float $distanceKm): int
    {
        $score = (int) max(0, 100 - round($distanceKm));
        $placeName = mb_strtolower(trim($placeName));
        $label = mb_strtolower(trim($label));

        if ($placeName !== '' && $label === $placeName) {
            return $score + 1000;
        }
        if ($placeName !== '' && (str_contains($label, $placeName) || str_contains($placeName, $label))) {
            return $score + 200;
        }

        return $score;
    }
}
