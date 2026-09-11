<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\HttpTransport;
use JsonException;
use Throwable;

/** Read-only adapter for the public GOV REST service. */
final class GovProvider implements ExternalProvider
{
    public const AUTHORITY_URI = 'https://gov.genealogy.net/';

    private readonly HttpTransport $httpClient;

    public function __construct(?HttpTransport $httpClient = null, private readonly ExternalProviderCache $cache = new ExternalProviderCache())
    {
        $this->httpClient = $httpClient ?? HttpTransport::default();
    }

    public function key(): string { return 'gov'; }

    public function label(): string { return 'GOV'; }

    public function authorityUri(): string { return self::AUTHORITY_URI; }

    public function identifier(string $value): ?ExternalIdentifier
    {
        $value = trim($value);
        if (str_starts_with($value, self::AUTHORITY_URI)) { $value = preg_replace('~^https://gov\.genealogy\.net/(?:data/|\?id=)?~', '', $value) ?? $value; }
        // GOV also contains legacy identifiers with lower-case prefixes,
        // e.g. object_1192115.  IDs are still restricted to the documented
        // alphanumeric/underscore alphabet and a bounded length.
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/', $value) !== 1) { return null; }
        return new ExternalIdentifier($this->key(), $value, self::AUTHORITY_URI, 'https://gov.genealogy.net/item/show/' . rawurlencode($value));
    }

    public function fetch(ExternalIdentifier $identifier, string $language): ?ExternalInformation
    {
        $cached = $this->cache->read($this->key(), $identifier->value);
        if ($cached !== null) {
            return $this->map($identifier, $cached);
        }
        try {
            // Use the same endpoint as Vesta's Gov4Webtrees module.  The
            // older /api/data/{id} form is still used as a compatibility
            // fallback by some GOV installations.
            $response = $this->httpClient->request('GET', 'https://gov.genealogy.net/api/getObject', ['itemId' => $identifier->value], ['Accept' => '*/*', 'User-Agent' => 'webtrees GOV Places/0.2'], 30.0);
            if ($response === null || $response->getStatusCode() !== 200) {
                $response = $this->httpClient->request('GET', 'https://gov.genealogy.net/api/data/' . rawurlencode($identifier->value), [], ['Accept' => '*/*', 'User-Agent' => 'webtrees GOV Places/0.2'], 30.0);
            }
            if ($response === null || $response->getStatusCode() !== 200 || strlen($body = $response->getBody()->getContents()) > 1_000_000) { return null; }
            $data = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
        } catch (JsonException) { return null; }
        if (!is_array($data)) { return null; }
        $this->cache->write($this->key(), $identifier->value, $data);
        return $this->map($identifier, $data);
    }

    /** @return list<array{id:string,label:string,description:?string,typeId:?string,typeIds:list<string>,distanceKm:?float}> */
    public function search(string $term, string $language = 'en'): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2 || mb_strlen($term) > 120) { return []; }
        $payload = $this->requestJson('https://gov.genealogy.net/api/searchByNameAndType', ['name' => $term, 'placename' => $term]);
        return $this->candidateList($payload);
    }

    /** @return list<array{id:string,label:string,description:?string,typeId:?string,typeIds:list<string>,distanceKm:?float}> */
    public function nearby(float $latitude, float $longitude, float $radiusKm): array
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) { return []; }
        $radiusKm = max(0.1, min(100.0, $radiusKm));
        $latDelta = $radiusKm / 111.32;
        $lonDelta = $radiusKm / max(1.0, 111.32 * cos(deg2rad($latitude)));
        $payload = $this->requestJson('https://gov.genealogy.net/api/searchByBoundingBox', [
            'latitude0' => max(-90, $latitude - $latDelta), 'latitude1' => min(90, $latitude + $latDelta),
            'longitude0' => max(-180, $longitude - $lonDelta), 'longitude1' => min(180, $longitude + $lonDelta),
        ]);
        return $this->candidateList($payload);
    }

    /** @param array<string,string> $query @return array<string,mixed>|null */
    private function requestJson(string $url, array $query): ?array
    {
        try {
            // GOV currently requires a broad Accept header.  The Vesta GOV
            // module uses */* here as well; with application/json GOV may
            // return its anti-bot challenge instead of the JSON payload.
            $response = $this->httpClient->request('GET', $url, $query, ['Accept' => '*/*', 'User-Agent' => 'webtrees GOV Places/0.2'], 30.0);
            if ($response === null) { return null; }
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
            return $response->getStatusCode() === 200 && is_array($data) ? $data : null;
        } catch (JsonException) { return null; }
    }

    /** @param array<string,mixed>|null $payload @return list<array{id:string,label:string,description:?string,typeId:?string,typeIds:list<string>,distanceKm:?float}> */
    private function candidateList(?array $payload): array
    {
        if ($payload === null) { return []; }
        $items = $payload['results'] ?? $payload['items'] ?? $payload['objects'] ?? $payload;
        if (!is_array($items)) { return []; }
        $out = [];
        foreach (array_slice($items, 0, 20) as $item) {
            if (!is_array($item)) { continue; }
            $id = $item['id'] ?? $item['govId'] ?? $item['itemId'] ?? $item['value'] ?? null;
            $label = $this->firstString($item, ['name', 'label', 'title']) ?? $id;
            if (!is_string($id) || $this->identifier($id) === null || !is_string($label)) { continue; }
            $typeIds = $this->typeIds($item);
            $out[] = ['id' => $id, 'label' => trim(strip_tags($label)), 'description' => $this->firstString($item, ['type', 'description', 'objectType']), 'typeId' => $typeIds[0] ?? null, 'typeIds' => $typeIds, 'distanceKm' => is_numeric($item['distance'] ?? null) ? (float) $item['distance'] : null];
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    private function map(ExternalIdentifier $identifier, array $data): ExternalInformation
    {
        $label = $this->firstString($data, ['name', 'label', 'title', 'placeName']);
        if ($label !== null) { $label = trim(strip_tags($label)); }
        $description = $this->firstString($data, ['description', 'type', 'objectType']);
        $typeId = $this->firstScalarString($data, ['typeId', 'govType', 'objectTypeId', 'type']);
        // GOV often returns the numeric vocabulary identifier as the type
        // description (for example "24"). Present the configured, readable
        // label instead; the label is translated at rendering time.
        if ($description !== null && preg_match('/^\\d+$/', $description) === 1) {
            $typeId = $description;
            $description = PlaceTypeFilterSettings::govLabel($description);
        }
        return new ExternalInformation('gov', $identifier->value, $identifier->url, $label, $description, null, [], $this->references($data, $identifier->value), $this->details($data), [], [], [], $this->population($data), $typeId);
    }

    /** @param array<string,mixed> $data @param list<string> $keys */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') { return trim($value); }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item) && trim($item) !== '') { return trim($item); }
                    if (is_array($item)) {
                        foreach (['value', 'name', 'label', 'text'] as $field) {
                            if (is_string($item[$field] ?? null) && trim($item[$field]) !== '') { return trim($item[$field]); }
                        }
                    }
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $item @return list<string> */
    private function typeIds(array $item): array
    {
        $values = [];
        foreach (['typeId', 'govType', 'objectTypeId', 'type', 'types', 'objectTypes'] as $key) {
            $raw = $item[$key] ?? null;
            foreach (is_array($raw) ? $raw : [$raw] as $value) {
                if (is_array($value)) { $value = $value['id'] ?? $value['value'] ?? $value['typeId'] ?? $value['name'] ?? null; }
                if (is_scalar($value) && trim((string) $value) !== '') { $values[] = trim((string) $value); }
            }
        }
        return array_values(array_unique($values));
    }

    /** @param array<string,mixed> $data @param list<string> $keys */
    private function firstScalarString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $data @return array<string,list<string>> */
    private function references(array $data, string $govId): array
    {
        $references = [];
        foreach (['wikidata', 'factgrid'] as $provider) {
            $value = $data[$provider] ?? ($data[strtoupper($provider)] ?? null);
            if (is_string($value) && preg_match('/^Q[1-9][0-9]*$/', trim($value)) === 1) { $references[$provider] = [trim($value)]; }
        }
        foreach ((array) ($data['externalReference'] ?? $data['extRef'] ?? []) as $externalReference) {
            $value = is_array($externalReference) ? ($externalReference['value'] ?? null) : $externalReference;
            if (!is_string($value) || preg_match('/^(wikidata|factgrid|geonames):((?:Q[1-9][0-9]*|[1-9][0-9]{0,11})$)/i', trim($value), $match) !== 1) { continue; }
            $references[strtolower($match[1])][] = $match[2];
        }
        foreach (['genwiki', 'genWiki', 'genwikiUrl', 'genWikiUrl'] as $key) {
            $url = $data[$key] ?? null;
            if (is_string($url) && preg_match('~^https?://(?:www\.)?genwiki\.genealogy\.net/~i', $url) === 1) {
                $references['genwiki'][] = $url;
            }
        }
        foreach ((array) ($data['external'] ?? []) as $external) {
            $url = is_array($external) ? ($external['value'] ?? $external['url'] ?? null) : $external;
            if (is_string($url) && preg_match('~^https?://(?:www\.)?genwiki\.genealogy\.net/~i', $url) === 1) {
                $references['genwiki'][] = $url;
            }
        }
        foreach ($references as $provider => $values) { $references[$provider] = array_values(array_unique($values)); }

        // Resolve the documented GOV namespace page through MediaWiki. The
        // API follows redirects, so the resulting link points to the actual
        // GenWiki article title rather than to a missing GOV: namespace page.
        if (($genwikiUrl = $this->genwikiUrl($govId)) !== null && ($references['genwiki'] ?? []) === []) {
            $references['genwiki'] = [$genwikiUrl];
        }
        return $references;
    }

    private function genwikiUrl(string $govId): ?string
    {
        $cached = $this->cache->read('genwiki', $govId);
        if ($cached !== null) {
            return is_string($cached['url'] ?? null) ? $cached['url'] : null;
        }
        if (!$this->cache->allowRequest('genwiki', 1.0)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://wiki.genealogy.net/api.php', [
                'action' => 'query',
                'format' => 'json',
                'prop' => '',
                'titles' => 'GOV:' . $govId,
                'redirects' => '1',
            ], [
                'Accept' => 'application/json',
                'User-Agent' => 'webtrees External Places/0.1 (https://github.com/hartenthaler/hh_external_places)',
            ], 10.0);
            if ($response === null || $response->getStatusCode() !== 200) {
                return null;
            }
            $payload = json_decode($response->getBody()->getContents(), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $url = null;
        foreach (($payload['query']['pages'] ?? []) as $page) {
            if (!is_array($page) || array_key_exists('missing', $page)) {
                continue;
            }
            if (is_numeric($page['pageid'] ?? null) && (int) $page['pageid'] > 0) {
                $url = 'https://wiki.genealogy.net/?curid=' . (int) $page['pageid'];
            } elseif (is_string($page['title'] ?? null)) {
                $url = 'https://wiki.genealogy.net/' . rawurlencode($page['title']);
            }
            break;
        }
        $this->cache->write('genwiki', $govId, ['url' => $url]);
        return $url;
    }

    /** @param array<string,mixed> $data @return list<array{label:string,value:string}> */
    private function details(array $data): array
    {
        $details = [];
        foreach ((array) ($data['name'] ?? []) as $name) {
            $value = is_array($name) ? ($name['value'] ?? $name['name'] ?? null) : $name;
            if (is_string($value) && trim($value) !== '') { $details[] = ['label' => 'Name', 'value' => trim(strip_tags($value))]; }
        }
        foreach ((array) ($data['externalReference'] ?? $data['extRef'] ?? []) as $reference) {
            $value = is_array($reference) ? ($reference['value'] ?? null) : $reference;
            if (is_string($value) && trim($value) !== '') {
                if (preg_match('/^(wikidata|factgrid|geonames):/i', trim($value)) === 1) { continue; }
                $details[] = ['label' => 'External identifier', 'value' => trim($value)];
            }
        }
        return $details;
    }

    /** @param array<string,mixed> $data @return array<string,int|float> */
    private function population(array $data): array
    {
        $result = [];
        $add = static function (string $year, int|float $amount, string $qualifier = '') use (&$result): void {
            $base = $qualifier !== '' ? $qualifier . ' ' . $year : $year;
            $key = $base;
            $suffix = 2;
            while (array_key_exists($key, $result)) { $key = $base . ' (' . $suffix++ . ')'; }
            $result[$key] = $amount;
        };
        $collect = function (mixed $value) use (&$collect, &$add): void {
            if (is_string($value)) {
                if (preg_match('/\b(ab|bis)\s+(\d{3,4})\D+(\d+(?:[.,]\d+)?)/iu', $value, $match) === 1) {
                    $amount = (float) str_replace(',', '.', $match[3]);
                    $add($match[2], $amount == (int) $amount ? (int) $amount : $amount, mb_strtolower($match[1]));
                }
                return;
            }
            if (!is_array($value)) { return; }

            // A population record normally has an amount plus one or two
            // temporal bounds. Never use the array index as a year: GOV's
            // JSON frequently numbers list entries 0, 1, 2, ... .
            $amount = null;
            foreach (['value', 'count', 'population', 'inhabitants', 'number'] as $field) {
                if (is_scalar($value[$field] ?? null) && is_numeric((string) $value[$field])) {
                    $amount = (float) $value[$field];
                    break;
                }
            }
            if ($amount !== null) {
                $numericAmount = $amount == (int) $amount ? (int) $amount : $amount;
                $bounds = [];
                foreach (['from' => 'ab', 'timeBegin' => 'ab', 'until' => 'bis', 'timeEnd' => 'bis', 'year' => '', 'date' => ''] as $field => $qualifier) {
                    if (!is_scalar($value[$field] ?? null)) { continue; }
                    if (preg_match('/\b(\d{3,4})\b/', (string) $value[$field], $match) !== 1) { continue; }
                    $bounds[$qualifier][] = $match[1];
                }
                if (($bounds['ab'] ?? []) !== [] || ($bounds['bis'] ?? []) !== []) {
                    foreach (['ab', 'bis'] as $qualifier) {
                        foreach (array_unique($bounds[$qualifier] ?? []) as $year) { $add($year, $numericAmount, $qualifier); }
                    }
                } elseif (($bounds[''] ?? []) !== []) {
                    foreach (array_unique($bounds[''] ) as $year) { $add($year, $numericAmount); }
                }
            }
            foreach ($value as $key => $child) {
                // Some older GOV responses use a year as the object key and
                // the population as scalar value. Accept only real years;
                // list indexes (1, 2, 3, ...) must never become years.
                if (is_scalar($child) && is_numeric((string) $child) && preg_match('/^(?:1[0-9]{3}|20[0-9]{2}|21[0-9]{2})$/', (string) $key) === 1) {
                    $amount = (float) $child;
                    $add((string) $key, $amount == (int) $amount ? (int) $amount : $amount);
                    continue;
                }
                $collect($child);
            }
        };
        foreach (['population', 'populationCount', 'inhabitants', 'inhabitantCount', 'populationHistory'] as $key) {
            $collect($data[$key] ?? null);
        }
        uksort($result, static function (string $a, string $b): int { return ((int) preg_replace('/\D.*/', '', $a)) <=> ((int) preg_replace('/\D.*/', '', $b)); });
        return $result;
    }
}
