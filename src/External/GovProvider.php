<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;
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
            return $this->map($identifier, $cached, $language);
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
        return $this->map($identifier, $data, $language);
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
        $center = Coordinates::fromDecimal($latitude, $longitude);
        if ($center === null) { return []; }
        $radiusKm = max(0.1, min(100.0, $radiusKm));
        $box = $center->boundingBox($radiusKm);
        $payload = $this->requestJson('https://gov.genealogy.net/api/searchByBoundingBox', [
            'latitude0' => $box['latitude0'], 'latitude1' => $box['latitude1'],
            'longitude0' => $box['longitude0'], 'longitude1' => $box['longitude1'],
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
    private function map(ExternalIdentifier $identifier, array $data, string $language = 'en'): ExternalInformation
    {
        $label = $this->firstString($data, ['name', 'label', 'title', 'placeName']);
        if ($label !== null) { $label = trim(strip_tags($label)); }
        $description = $this->firstString($data, ['description', 'type', 'objectType']);
        $typeId = $this->firstScalarString($data, ['typeId', 'govType', 'objectTypeId', 'type']);
        $typeIds = array_values(array_filter($this->typeIds($data), static fn (string $value): bool => preg_match('/^\d+$/', $value) === 1));
        if ($typeId !== null && preg_match('/^\d+$/', $typeId) !== 1) {
            $typeId = null;
        }
        // GOV often returns the numeric vocabulary identifier as the type
        // description (for example "24"). Present the readable label from
        // the GOV vocabulary in the requested language instead.
        if ($description !== null && preg_match('/^\\d+$/', $description) === 1) {
            $typeId = $description;
            $description = PlaceTypeFilterSettings::govLabel($description, $language);
        }
        if ($typeId !== null && !in_array($typeId, $typeIds, true)) {
            array_unshift($typeIds, $typeId);
        }
        return new ExternalInformation('gov', $identifier->value, $identifier->url, $label, $description, null, [], $this->references($data, $identifier->value), $this->details($data), [], [], [], $this->population($data), $typeId, [], $this->coordinates($data), array_values(array_unique($typeIds)));
    }

    /** @param array<string,mixed> $data */
    private function coordinates(array $data): ?Coordinates
    {
        foreach (['coordinates', 'coordinate', 'position', 'location', 'map'] as $key) {
            $candidate = $data[$key] ?? null;
            if (is_array($candidate)) {
                $coordinates = Coordinates::fromArray($candidate);
                if ($coordinates !== null) { return $coordinates; }
            }
        }
        $latitude = $data['latitude'] ?? $data['lat'] ?? $data['lati'] ?? null;
        $longitude = $data['longitude'] ?? $data['lon'] ?? $data['long'] ?? $data['lng'] ?? null;
        return is_scalar($latitude) && is_scalar($longitude) ? Coordinates::fromStrings((string) $latitude, (string) $longitude) : null;
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

    /** @param array<string,mixed> $data @return list<array{label:string,value:string,period?:string,from?:string,until?:string}> */
    private function details(array $data): array
    {
        $details = [];
        foreach ($this->nameItems($data) as $name) {
            $value = is_array($name) ? ($name['value'] ?? $name['name'] ?? $name['text'] ?? null) : $name;
            if (!is_string($value) || trim($value) === '') { continue; }
            $language = is_array($name) ? ($name['language'] ?? $name['languageCode'] ?? $name['lang'] ?? $name['code'] ?? null) : null;
            $period = is_array($name) ? $this->namePeriod($name) : [];
            $detail = ['label' => 'Alternate name (' . strtolower(trim((string) $language)) . ')', 'value' => trim(strip_tags($value))];
            if (is_string($language) && preg_match('/^[a-z]{2,3}(?:[-_][a-z]{2,4})?$/i', trim($language)) === 1) {
                $detail['label'] = 'Alternate name (' . strtolower(trim($language)) . ')';
            } else {
                $detail['label'] = 'Name';
            }
            $details[] = array_merge($detail, $period);
        }
        foreach ((array) ($data['externalReference'] ?? $data['extRef'] ?? []) as $reference) {
            $value = is_array($reference) ? ($reference['value'] ?? null) : $reference;
            if (is_string($value) && trim($value) !== '') {
                if (preg_match('/^(wikidata|factgrid|geonames):/i', trim($value)) === 1) { continue; }
                $details[] = ['label' => 'External identifier', 'value' => trim($value)];
            }
        }
        $seen = [];
        $details = array_values(array_filter($details, static function (array $detail) use (&$seen): bool {
            $key = $detail['label'] . "\0" . $detail['value'] . "\0" . ($detail['period'] ?? '') . "\0" . ($detail['from'] ?? '') . "\0" . ($detail['until'] ?? '');
            if (isset($seen[$key])) { return false; }
            $seen[$key] = true;
            return true;
        }));
        usort($details, static function (array $left, array $right): int {
            $alternateLeft = str_starts_with($left['label'], 'Alternate name (');
            $alternateRight = str_starts_with($right['label'], 'Alternate name (');
            if ($alternateLeft !== $alternateRight) { return $alternateLeft ? -1 : 1; }
            return strnatcasecmp(
                $left['label'] . $left['value'] . ($left['period'] ?? '') . ($left['from'] ?? '') . ($left['until'] ?? ''),
                $right['label'] . $right['value'] . ($right['period'] ?? '') . ($right['from'] ?? '') . ($right['until'] ?? ''),
            );
        });
        return $details;
    }

    /**
     * GOV responses have used both a list of name objects and a single
     * associative name object.  Normalize both shapes before extracting
     * language and validity data so that one response cannot silently lose
     * its language metadata.
     *
     * @param array<string,mixed> $data
     * @return list<string|array<string,mixed>>
     */
    private function nameItems(array $data): array
    {
        $raw = $data['name'] ?? $data['names'] ?? [];
        if (is_string($raw) || is_numeric($raw)) {
            return [(string) $raw];
        }
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $hasNameFields = array_intersect(array_keys($raw), [
            'value', 'name', 'text', 'language', 'languageCode', 'lang', 'code',
            'period', 'validity', 'timespan', 'from', 'until', 'begin', 'end',
        ]) !== [];
        if ($hasNameFields) {
            return [$raw];
        }

        return array_values(array_filter($raw, static fn (mixed $item): bool => is_string($item) || is_numeric($item) || is_array($item)));
    }

    /** @param array<string,mixed> $name @return array{period?:string,from?:string,until?:string} */
    private function namePeriod(array $name): array
    {
        $from = $this->nameDate($name, ['from', 'begin', 'start', 'validFrom', 'dateFrom', 'beginDate', 'timeBegin', 'ab']);
        $until = $this->nameDate($name, ['until', 'to', 'end', 'validUntil', 'dateUntil', 'dateTo', 'endDate', 'timeEnd', 'bis']);
        $period = $name['period'] ?? $name['validity'] ?? null;
        if (is_array($period)) {
            $from ??= $this->nameDate($period, ['from', 'begin', 'start', 'validFrom', 'dateFrom', 'beginDate', 'timeBegin', 'ab']);
            $until ??= $this->nameDate($period, ['until', 'to', 'end', 'validUntil', 'dateUntil', 'dateTo', 'endDate', 'timeEnd', 'bis']);
        } elseif (is_scalar($period) && trim((string) $period) !== '' && $from === null && $until === null) {
            return ['period' => trim((string) $period)];
        }

        // Some GOV payloads put one date object below `date` rather than
        // exposing from/until at the name level.
        if (is_array($name['date'] ?? null)) {
            $from ??= $this->nameDate($name['date'], ['from', 'begin', 'start', 'validFrom', 'dateFrom', 'beginDate', 'timeBegin', 'ab']);
            $until ??= $this->nameDate($name['date'], ['until', 'to', 'end', 'validUntil', 'dateUntil', 'dateTo', 'endDate', 'timeEnd', 'bis']);
        }

        // The GOV API represents historical validity as a Julian-day
        // `timespan`. Convert it to an ISO date for a stable, readable
        // provider detail while retaining the direction (from/until).
        if (is_array($name['timespan'] ?? null)) {
            $from ??= $this->govTimespanDate($name['timespan']['begin'] ?? $name['timespan']['from'] ?? $name['timespan']['start'] ?? null);
            $until ??= $this->govTimespanDate($name['timespan']['end'] ?? $name['timespan']['until'] ?? $name['timespan']['to'] ?? null);
        }

        $result = [];
        if ($from !== null) { $result['from'] = $from; }
        if ($until !== null) { $result['until'] = $until; }
        return $result;
    }

    /** @param mixed $value */
    private function govTimespanDate(mixed $value): ?string
    {
        if (is_array($value)) {
            $precision = is_numeric($value['precision'] ?? null) ? (int) $value['precision'] : 0;
            $julianDay = $value['jd'] ?? $value['julianDay'] ?? null;
            if (is_numeric($julianDay) && function_exists('jdtogregorian')) {
                $gregorian = jdtogregorian((int) $julianDay);
                if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{1,6})$/', $gregorian, $parts) === 1) {
                    $year = (int) $parts[3];
                    $month = (int) $parts[1];
                    $day = (int) $parts[2];
                    return match (true) {
                        $precision >= 2 => sprintf('%04d', $year),
                        $precision === 1 => sprintf('%04d-%02d', $year, $month),
                        default => sprintf('%04d-%02d-%02d', $year, $month, $day),
                    };
                }
            }
            foreach (['date', 'value', 'year'] as $key) {
                if (isset($value[$key]) && is_scalar($value[$key]) && trim((string) $value[$key]) !== '') {
                    return trim((string) $value[$key]);
                }
            }
        }
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** @param array<string,mixed> $data @param list<string> $keys */
    private function nameDate(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $data @return array<string,int|float> */
    private function population(array $data): array
    {
        $result = [];
        $add = static function (string $year, int|float $amount, string $qualifier = '') use (&$result): void {
            $year = trim((string) preg_replace('/\s+/u', ' ', strtoupper($year)));
            $base = $qualifier !== '' ? $qualifier . ' ' . $year : $year;
            $key = $base;
            $suffix = 2;
            while (array_key_exists($key, $result)) { $key = $base . ' (' . $suffix++ . ')'; }
            $result[$key] = $amount;
        };
        $collect = function (mixed $value) use (&$collect, &$add): void {
            if (is_string($value)) {
                if (preg_match('/\b(ab|bis)\s+((?:[[:alpha:]ÄÖÜäöü]{3,12}\s+)?\d{3,4})\D+(\d+(?:[.,]\d+)?)/iu', $value, $match) === 1) {
                    $amount = (float) str_replace(',', '.', $match[3]);
                    $date = self::populationDateLabel($match[2]);
                    if ($date !== null) {
                        $add($date, $amount == (int) $amount ? (int) $amount : $amount, mb_strtolower($match[1]));
                    }
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
                foreach (['from' => 'ab', 'beginDate' => 'ab', 'beginYear' => 'ab', 'timeBegin' => 'ab', 'until' => 'bis', 'endDate' => 'bis', 'endYear' => 'bis', 'timeEnd' => 'bis', 'year' => '', 'date' => ''] as $field => $qualifier) {
                    if (!is_scalar($value[$field] ?? null)) { continue; }
                    $monthField = match ($qualifier) {
                        'ab' => 'beginMonth',
                        'bis' => 'endMonth',
                        default => null,
                    };
                    $date = self::populationDateLabel($value[$field], $monthField !== null ? ($value[$monthField] ?? null) : null);
                    if ($date !== null) { $bounds[$qualifier][] = $date; }
                }
                if (($bounds['ab'] ?? []) !== [] || ($bounds['bis'] ?? []) !== []) {
                    foreach (['ab', 'bis'] as $qualifier) {
                        $dates = array_values(array_unique($bounds[$qualifier] ?? []));
                        usort($dates, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
                        if (isset($dates[0])) { $add($dates[0], $numericAmount, $qualifier); }
                    }
                } elseif (($bounds[''] ?? []) !== []) {
                    $dates = array_values(array_unique($bounds['']));
                    usort($dates, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
                    if (isset($dates[0])) { $add($dates[0], $numericAmount); }
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
        return $result;
    }

    private static function populationDateLabel(mixed $value, mixed $month = null): ?string
    {
        if (!is_scalar($value)) { return null; }
        $text = trim((string) $value);
        if ($text === '') { return null; }

        $monthLabel = self::populationMonthLabel($month);
        if (preg_match('/^(\d{4})$/', $text, $match) === 1) {
            return $monthLabel === null ? $match[1] : $monthLabel . ' ' . $match[1];
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})(?:[-\/.](\d{1,2}))?$/', $text, $match) === 1) {
            $monthLabel = self::populationMonthLabel($match[2]);
            if ($monthLabel === null) { return $match[1]; }
            return isset($match[3]) ? $match[3] . ' ' . $monthLabel . ' ' . $match[1] : $monthLabel . ' ' . $match[1];
        }
        if (preg_match('/^(\d{1,2})\s+([[:alpha:]ÄÖÜäöü]{3,12})\.?\s+(\d{4})$/u', $text, $match) === 1) {
            $monthLabel = self::populationMonthLabel($match[2]);
            return $monthLabel === null ? $match[3] : $match[1] . ' ' . $monthLabel . ' ' . $match[3];
        }
        if (preg_match('/^([[:alpha:]ÄÖÜäöü]{3,12})\.?\s+(\d{4})$/u', $text, $match) === 1) {
            $monthLabel = self::populationMonthLabel($match[1]);
            return $monthLabel === null ? $match[2] : $monthLabel . ' ' . $match[2];
        }
        if (preg_match('/\b(\d{4})\b/', $text, $match) === 1) { return $match[1]; }
        return null;
    }

    private static function populationMonthLabel(mixed $month): ?string
    {
        if (!is_scalar($month)) { return null; }
        $month = strtoupper(trim((string) $month));
        $month = rtrim($month, '.');
        $months = [
            '1' => 'JAN', '01' => 'JAN', 'JAN' => 'JAN', 'JANUARY' => 'JAN',
            '2' => 'FEB', '02' => 'FEB', 'FEB' => 'FEB', 'FEBRUARY' => 'FEB',
            '3' => 'MAR', '03' => 'MAR', 'MAR' => 'MAR', 'MARCH' => 'MAR', 'MÄR' => 'MAR', 'MAER' => 'MAR',
            '4' => 'APR', '04' => 'APR', 'APR' => 'APR', 'APRIL' => 'APR',
            '5' => 'MAY', '05' => 'MAY', 'MAY' => 'MAY', 'MAI' => 'MAY',
            '6' => 'JUN', '06' => 'JUN', 'JUN' => 'JUN', 'JUNE' => 'JUN',
            '7' => 'JUL', '07' => 'JUL', 'JUL' => 'JUL', 'JULY' => 'JUL',
            '8' => 'AUG', '08' => 'AUG', 'AUG' => 'AUG', 'AUGUST' => 'AUG',
            '9' => 'SEP', '09' => 'SEP', 'SEP' => 'SEP', 'SEPTEMBER' => 'SEP',
            '10' => 'OCT', 'OCT' => 'OCT', 'OCTOBER' => 'OCT', 'OKT' => 'OCT',
            '11' => 'NOV', 'NOV' => 'NOV', 'NOVEMBER' => 'NOV',
            '12' => 'DEC', 'DEC' => 'DEC', 'DECEMBER' => 'DEC', 'DEZ' => 'DEC',
        ];
        return $months[$month] ?? null;
    }
}
