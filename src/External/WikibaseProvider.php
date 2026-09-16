<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikibase\ReadOnlyWikibaseClient;

/** Provider adapter for Wikidata-like, read-only Wikibase installations. */
final class WikibaseProvider implements ExternalProvider
{
    /** @param array{authority:string, label:string, type:string, image:string, coordinate:string, factgrid:?string, wikidata:?string, gov:?string, geonames:?string, genwiki:?string, wikitree:string, owner:string, occupant:string, begin:string, end:string} $definition */
    public function __construct(private readonly string $key, private readonly array $definition, private readonly ReadOnlyWikibaseClient $client = new ReadOnlyWikibaseClient(), private readonly ExternalProviderCache $cache = new ExternalProviderCache())
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->definition['label'];
    }

    public function authorityUri(): string
    {
        return $this->definition['authority'];
    }

    public function identifier(string $value): ?ExternalIdentifier
    {
        $value = trim($value);
        if (str_starts_with($value, $this->authorityUri())) {
            $value = substr($value, strlen($this->authorityUri()));
        }
        if (preg_match('/^Q[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }

        return new ExternalIdentifier($this->key(), $value, $this->authorityUri(), $this->entityUrl($value));
    }

    public function fetch(ExternalIdentifier $identifier, string $language): ?ExternalInformation
    {
        // v3 invalidates payloads cached before the GenWiki (P14871)
        // cross-reference was requested.
        $cacheKey = 'v3|' . $identifier->value . '|' . $language;
        $payload = $this->cache->read($this->key(), $cacheKey);
        if ($payload === null) {
            $payload = $this->client->entity($this->key(), $identifier->value, $language);
            if ($payload !== null) { $this->cache->write($this->key(), $cacheKey, $payload); }
        }
        $entity = $payload['entities'][$identifier->value] ?? null;
        if (!is_array($entity) || array_key_exists('missing', $entity)) {
            return null;
        }

        $claims = is_array($entity['claims'] ?? null) ? $entity['claims'] : [];
        $references = [];
        foreach (['factgrid', 'wikidata', 'gov', 'geonames'] as $provider) {
            $property = $this->definition[$provider];
            if ($property !== null) {
                $values = $this->claimStrings($claims[$property] ?? [], $provider);
                if ($values !== []) {
                    $references[$provider] = $values;
                }
            }
        }
        $genwikiProperty = $this->definition['genwiki'] ?? null;
        if ($genwikiProperty !== null) {
            foreach ($this->claimStrings($claims[$genwikiProperty] ?? [], 'genwiki') as $pageId) {
                $references['genwiki'][] = 'https://wiki.genealogy.net/?curid=' . rawurlencode($pageId);
            }
            $references['genwiki'] = array_values(array_unique($references['genwiki'] ?? []));
        }
        // FactGrid can link back to Wikidata through the special sitelink
        // "wikidatawiki" instead of a dedicated claim.  Treat only a plain
        // Wikidata Q-ID as an external reference; never turn arbitrary page
        // titles into outbound identifiers.
        if ($this->key === 'factgrid' && !isset($references['wikidata'])) {
            $sitelink = $entity['sitelinks']['wikidatawiki']['title'] ?? null;
            if (is_string($sitelink) && preg_match('/^Q[1-9][0-9]*$/', $sitelink) === 1) {
                $references['wikidata'] = [$sitelink];
            }
        }
        $owners = $this->relations($claims[$this->definition['owner']] ?? []);
        $occupants = $this->relations($claims[$this->definition['occupant']] ?? []);
        $people = $this->people($owners, $occupants, $language);

        return new ExternalInformation(
            $this->key(),
            $identifier->value,
            $identifier->url,
            $this->languageValue($entity['labels'] ?? [], $language),
            $this->languageValue($entity['descriptions'] ?? [], $language),
            $this->imageUrl($claims[$this->definition['image']] ?? []),
            $this->claimEntityIds($claims[$this->definition['type']] ?? []),
            $references,
            [],
            $people,
            $owners,
            $occupants,
            [],
            null,
            [],
            Coordinates::fromWikibase($this->claimValue($claims[$this->definition['coordinate']] ?? [])),
        );
    }

    /** @return list<ExternalPersonRelation> */
    private function relations(mixed $statements): array
    {
        $relations = [];
        foreach (is_array($statements) ? $statements : [] as $statement) {
            $id = $statement['mainsnak']['datavalue']['value']['id'] ?? null;
            if (!is_string($id) || preg_match('/^Q[1-9][0-9]*$/', $id) !== 1) { continue; }
            $qualifiers = is_array($statement['qualifiers'] ?? null) ? $statement['qualifiers'] : [];
            $relations[] = new ExternalPersonRelation($id, $this->claimDate($qualifiers[$this->definition['begin']] ?? []), $this->claimDate($qualifiers[$this->definition['end']] ?? []));
        }
        return $relations;
    }

    /** @param list<ExternalPersonRelation> $owners @param list<ExternalPersonRelation> $occupants @return array<string,ExternalPerson> */
    private function people(array $owners, array $occupants, string $language): array
    {
        $ids = array_values(array_unique(array_merge(array_map(static fn (ExternalPersonRelation $r): string => $r->id, $owners), array_map(static fn (ExternalPersonRelation $r): string => $r->id, $occupants))));
        $entities = $this->client->entities($this->key, $ids, $language);
        $people = [];
        foreach ($entities as $id => $entity) {
            $claims = is_array($entity['claims'] ?? null) ? $entity['claims'] : [];
            $links = [];
            foreach (['wikidata' => $this->definition['wikidata'], 'gov' => $this->definition['gov'], 'wikitree' => $this->definition['wikitree']] as $provider => $property) {
                if ($property === null) { continue; }
                foreach ($this->claimStrings($claims[$property] ?? [], $provider) as $value) {
                    $baseUrl = match ($provider) {
                        'wikidata' => 'https://www.wikidata.org/entity/',
                        'gov' => 'https://gov.genealogy.net/item/show/',
                        default => 'https://www.wikitree.com/wiki/',
                    };
                    $links[$provider === 'wikitree' ? 'WikiTree' : ucfirst($provider)] = $baseUrl . rawurlencode($value);
                }
            }
            $label = $this->languageValue($entity['labels'] ?? [], $language);
            $people[$id] = new ExternalPerson($this->key, $id, $this->entityUrl($id), $label, $this->claimDate($claims['P569'] ?? []), $this->claimDate($claims['P570'] ?? []), $links);
        }
        return $people;
    }

    /** @param mixed $statements */
    private function claimDate(mixed $statements): ?string
    {
        foreach (is_array($statements) ? $statements : [] as $statement) {
            $time = $statement['datavalue']['value']['time'] ?? $statement['mainsnak']['datavalue']['value']['time'] ?? null;
            if (is_string($time) && preg_match('/^[+-](\d{4,})-(\d{2})-(\d{2})T/', $time, $m) === 1) {
                return $m[2] === '00' ? $m[1] : ($m[1] . '-' . $m[2] . ($m[3] === '00' ? '' : '-' . $m[3]));
            }
        }
        return null;
    }

    private function entityUrl(string $value): string
    {
        return $this->authorityUri() . $value;
    }

    private function languageValue(mixed $values, string $language): ?string
    {
        if (!is_array($values)) { return null; }
        $language = LanguageCode::normalize($language) ?: 'en';
        foreach (array_unique([$language, 'en']) as $candidate) {
            $value = $values[$candidate]['value'] ?? null;
            if (is_string($value) && trim($value) !== '') { return trim($value); }
        }
        return null;
    }

    /** @return list<string> */
    private function claimStrings(mixed $statements, string $provider): array
    {
        $values = [];
        foreach (is_array($statements) ? $statements : [] as $statement) {
            $value = $statement['mainsnak']['datavalue']['value'] ?? null;
            if (!is_string($value)) { continue; }
            $value = trim($value);
            if ($provider === 'wikitree' && preg_match('~^https?://(?:www\\.)?wikitree\\.com/wiki/(.+)$~i', $value, $match) === 1) {
                $value = rawurldecode($match[1]);
            }
            $valid = match ($provider) {
                'gov' => preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $value) === 1,
                'geonames' => preg_match('/^[1-9][0-9]{0,11}$/', $value) === 1,
                'wikitree' => preg_match('/^[\p{L}][\p{L}\p{M}0-9._-]{0,119}$/u', $value) === 1,
                'genwiki' => preg_match('/^[1-9][0-9]{0,11}$/', $value) === 1,
                default => preg_match('/^Q[1-9][0-9]*$/', $value) === 1,
            };
            if ($valid) { $values[] = $value; }
        }
        return array_values(array_unique($values));
    }

    /** @return list<string> */
    private function claimEntityIds(mixed $statements): array
    {
        $values = [];
        foreach (is_array($statements) ? $statements : [] as $statement) {
            $value = $statement['mainsnak']['datavalue']['value']['id'] ?? null;
            if (is_string($value) && preg_match('/^Q[1-9][0-9]*$/', $value) === 1) { $values[] = $value; }
        }
        return array_values(array_unique($values));
    }

    private function imageUrl(mixed $statements): ?string
    {
        foreach (is_array($statements) ? $statements : [] as $statement) {
            $value = $statement['mainsnak']['datavalue']['value'] ?? null;
            if (is_string($value) && $value !== '') {
                return 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($value);
            }
        }
        return null;
    }

    private function claimValue(mixed $statements): mixed
    {
        foreach (is_array($statements) ? $statements : [] as $statement) {
            $value = $statement['mainsnak']['datavalue']['value'] ?? null;
            if ($value !== null) { return $value; }
        }
        return null;
    }
}
