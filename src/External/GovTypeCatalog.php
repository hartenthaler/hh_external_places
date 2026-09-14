<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

/**
 * Read-only catalogue of the GOV type vocabulary.
 *
 * The bundled OWL snapshot is derived from the public GOV vocabulary.  The
 * catalogue is deliberately kept independent from Vesta so this module also
 * works when Vesta is not installed.
 */
final class GovTypeCatalog
{
    private const FILE = __DIR__ . '/../../resources/config/gov-types.owl';

    /** @var array<string,array<string,string>>|null */
    private static ?array $labels = null;

    /** @return array<string,array<string,string>> */
    public static function all(): array
    {
        if (self::$labels !== null) {
            return self::$labels;
        }

        self::$labels = [];
        if (!is_file(self::FILE) || !function_exists('simplexml_load_file')) {
            return self::$labels;
        }

        $xml = @simplexml_load_file(self::FILE, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($xml === false) {
            return self::$labels;
        }

        $xml->registerXPathNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $xml->registerXPathNamespace('rdfs', 'http://www.w3.org/2000/01/rdf-schema#');
        $nodes = $xml->xpath('//*[@rdf:about]');
        if (!is_array($nodes)) {
            return self::$labels;
        }

        foreach ($nodes as $node) {
            $rdfAttributes = $node->attributes('rdf', true);
            $about = is_object($rdfAttributes) ? (string) ($rdfAttributes['about'] ?? '') : '';
            if (preg_match('~#(\d+)$~', $about, $matches) !== 1) {
                // Group resources (group_8, group_27, ...) are not GOV
                // object type IDs and must not appear as selectable types.
                continue;
            }

            $typeId = $matches[1];
            $labels = $node->xpath('./rdfs:label');
            if (!is_array($labels)) {
                continue;
            }

            foreach ($labels as $label) {
                $xmlAttributes = $label->attributes('xml', true);
                $language = is_object($xmlAttributes) ? strtolower(trim((string) ($xmlAttributes['lang'] ?? ''))) : '';
                $value = trim((string) $label);
                if ($language === '' || $value === '') {
                    continue;
                }
                self::$labels[$typeId][$language] = $value;
            }
        }

        return self::$labels;
    }

    /** @return array<string,string> */
    public static function labels(string $typeId): array
    {
        return self::all()[$typeId] ?? [];
    }

    public static function label(string $typeId, string $language = 'en'): ?string
    {
        $labels = self::labels($typeId);
        if ($labels === []) {
            return null;
        }

        $language = strtolower(str_replace('_', '-', trim($language)));
        $baseLanguage = explode('-', $language, 2)[0] ?? $language;
        foreach (array_unique([$language, $baseLanguage, 'en', 'de']) as $candidate) {
            if (isset($labels[$candidate])) {
                return $labels[$candidate];
            }
        }

        return reset($labels) ?: null;
    }
}
