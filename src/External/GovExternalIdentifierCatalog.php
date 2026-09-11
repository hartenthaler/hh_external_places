<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use JsonException;

/** Read-only metadata and link templates for GOV external identifiers. */
final class GovExternalIdentifierCatalog
{
    /** @return array<string,array{description:string,url:?string}> */
    private static function all(): array
    {
        static $entries;
        if (is_array($entries)) { return $entries; }
        $file = dirname(__DIR__, 2) . '/resources/config/gov-external-identifiers.json';
        try {
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR) : [];
        } catch (JsonException) {
            $decoded = [];
        }
        $entries = [];
        foreach (is_array($decoded) ? $decoded : [] as $prefix => $entry) {
            if (!is_string($prefix) || !is_array($entry) || !is_string($entry['description'] ?? null)) { continue; }
            $url = $entry['url'] ?? null;
            $entries[$prefix] = ['description' => $entry['description'], 'url' => is_string($url) && str_contains($url, '{0}') ? $url : null];
        }
        return $entries;
    }

    /** @return array{prefix:string,description:string,url:?string}|null */
    public static function forValue(string $value): ?array
    {
        $separator = strpos($value, ':');
        if ($separator === false) { return null; }
        $prefix = trim(substr($value, 0, $separator));
        foreach (self::all() as $configuredPrefix => $entry) {
            if (strcasecmp($prefix, $configuredPrefix) === 0) {
                return ['prefix' => $configuredPrefix, ...$entry];
            }
        }
        return null;
    }

    public static function url(string $value): ?string
    {
        $entry = self::forValue($value);
        if ($entry === null || $entry['url'] === null) { return null; }
        $separator = strpos($value, ':');
        $identifier = trim(substr($value, $separator + 1));
        if ($identifier === '') {
            return null;
        }
        // Encode each path segment separately so structured identifiers such as
        // DCAT-AP.de:stateKey/08 retain their documented slash separator.
        $encodedIdentifier = implode('/', array_map('rawurlencode', explode('/', $identifier)));
        return str_replace('{0}', $encodedIdentifier, $entry['url']);
    }
}
