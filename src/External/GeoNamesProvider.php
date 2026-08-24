<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Fisharebest\Webtrees\Site;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\HttpTransport;
use Throwable;

/** Read-only GeoNames lookup for contextual place information. */
final class GeoNamesProvider
{
    private const ENDPOINT = 'https://secure.geonames.org/searchJSON';

    private readonly HttpTransport $http;

    public function __construct(?HttpTransport $http = null, private readonly ExternalProviderCache $cache = new ExternalProviderCache())
    {
        $this->http = $http ?? HttpTransport::default();
    }

    /** @return array{label:string,url:string,details:list<array{label:string,value:string}>}|null */
    public function lookup(string $place, string $language): ?array
    {
        $username = trim(Site::getPreference('geonames'));
        $place = trim($place);
        if ($username === '' || $place === '' || mb_strlen($place) > 240) { return null; }
        $language = preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z]{2,4})?$/', $language) === 1 ? str_replace('_', '-', $language) : 'en';
        $cacheKey = $language . '|' . $place . '|' . $username;
        $payload = $this->cache->read('geonames', $cacheKey);
        if ($payload === null) {
            try {
                $response = $this->http->request('GET', self::ENDPOINT, [
                    'q' => $place, 'lang' => $language, 'maxRows' => 1, 'style' => 'FULL', 'username' => $username,
                ], ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.3'], 6.0);
                if ($response === null || $response->getStatusCode() !== 200) { return null; }
                $body = $response->getBody()->getContents();
                if (strlen($body) > 500_000) { return null; }
                $payload = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) { return null; }
                $this->cache->write('geonames', $cacheKey, $payload);
            } catch (Throwable) { return null; }
        }

        $result = $payload['geonames'][0] ?? null;
        $id = is_array($result) ? ($result['geonameId'] ?? null) : null;
        $label = is_array($result) ? ($result['name'] ?? null) : null;
        if (!is_numeric($id) || !is_string($label) || $label === '') { return null; }
        $details = [];
        foreach ([
            'countryName' => 'Country', 'adminName1' => 'Region', 'adminName2' => 'Administrative area',
            'fcodeName' => 'Feature', 'population' => 'Population', 'elevation' => 'Elevation',
        ] as $key => $detailLabel) {
            $value = $result[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '' && (string) $value !== '0') {
                $details[] = ['label' => $detailLabel, 'value' => (string) $value];
            }
        }
        if (is_array($result['timezone'] ?? null) && is_string($result['timezone']['timeZoneId'] ?? null)) {
            $details[] = ['label' => 'Time zone', 'value' => $result['timezone']['timeZoneId']];
        }

        return [
            'label' => $label,
            'url' => 'https://www.geonames.org/' . (int) $id . '/',
            'details' => $details,
        ];
    }
}
