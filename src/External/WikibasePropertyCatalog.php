<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use JsonException;

/** Read-only property mappings for the bundled Wikibase providers. */
final class WikibasePropertyCatalog
{
    private const FILE = __DIR__ . '/../../resources/config/wikibase-properties.json';

    /** @var array<string,array<string,string|null>>|null */
    private static ?array $definitions = null;

    /** @return array<string,array<string,string|null>> */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        self::$definitions = [];
        if (!is_file(self::FILE)) {
            return self::$definitions;
        }

        try {
            $decoded = json_decode((string) file_get_contents(self::FILE), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::$definitions;
        }

        if (!is_array($decoded)) {
            return self::$definitions;
        }

        $required = ['authority', 'label', 'type', 'image', 'coordinate', 'factgrid', 'wikidata', 'gov', 'geonames', 'genwiki', 'wikitree', 'owner', 'occupant', 'begin', 'end'];
        foreach ($decoded as $provider => $definition) {
            if (!is_string($provider) || !is_array($definition)) {
                continue;
            }

            $valid = true;
            foreach ($required as $key) {
                if (!array_key_exists($key, $definition) || (!is_string($definition[$key]) && $definition[$key] !== null)) {
                    $valid = false;
                    break;
                }
            }
            if (!$valid) {
                continue;
            }

            /** @var array<string,string|null> $definition */
            self::$definitions[$provider] = $definition;
        }

        return self::$definitions;
    }
}
