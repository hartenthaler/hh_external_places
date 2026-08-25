<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\HttpTransport;
use JsonException;

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
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $value) !== 1) { return null; }
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

    /** @return list<array{id:string,label:string,description:?string,typeId:?string,distanceKm:?float}> */
    public function search(string $term, string $language = 'en'): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2 || mb_strlen($term) > 120) { return []; }
        $payload = $this->requestJson('https://gov.genealogy.net/api/searchByNameAndType', ['name' => $term, 'placename' => $term]);
        return $this->candidateList($payload);
    }

    /** @return list<array{id:string,label:string,description:?string,typeId:?string,distanceKm:?float}> */
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

    /** @param array<string,mixed>|null $payload @return list<array{id:string,label:string,description:?string,typeId:?string,distanceKm:?float}> */
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
            $typeId = $item['typeId'] ?? $item['govType'] ?? $item['objectTypeId'] ?? null;
            $out[] = ['id' => $id, 'label' => trim(strip_tags($label)), 'description' => $this->firstString($item, ['type', 'description', 'objectType']), 'typeId' => is_scalar($typeId) ? (string) $typeId : null, 'distanceKm' => is_numeric($item['distance'] ?? null) ? (float) $item['distance'] : null];
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    private function map(ExternalIdentifier $identifier, array $data): ExternalInformation
    {
        $label = $this->firstString($data, ['name', 'label', 'title', 'placeName']);
        if ($label !== null) { $label = trim(strip_tags($label)); }
        $description = $this->firstString($data, ['description', 'type', 'objectType']);
        return new ExternalInformation('gov', $identifier->value, $identifier->url, $label, $description, null, [], $this->references($data), $this->details($data), [], [], [], $this->population($data));
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

    /** @param array<string,mixed> $data @return array<string,list<string>> */
    private function references(array $data): array
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
        return $references;
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

    /** @param array<string,mixed> $data @return array<int,int|float> */
    private function population(array $data): array
    {
        $result = [];
        foreach (['population', 'populationCount', 'inhabitants', 'inhabitantCount', 'populationHistory'] as $key) {
            $value = $data[$key] ?? null;
            if (is_array($value)) {
                foreach ($value as $index => $item) {
                    $year = is_array($item) ? ($item['year'] ?? $item['date'] ?? $item['from'] ?? $index) : $index;
                    $amount = is_array($item) ? ($item['value'] ?? $item['count'] ?? $item['population'] ?? null) : $item;
                    if (!is_scalar($year) || !is_scalar($amount)) { continue; }
                    $year = (int) preg_replace('/[^0-9-].*$/', '', (string) $year);
                    if ($year < 1 || !is_numeric($amount)) { continue; }
                    $result[$year] = (float) $amount == (int) (float) $amount ? (int) $amount : (float) $amount;
                }
            }
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }
}
