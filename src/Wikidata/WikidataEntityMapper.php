<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\WikidataIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;

/** @internal Maps the fixed wbgetentities response subset to a value object. */
final class WikidataEntityMapper
{
    /** @param array<string, mixed> $claims */
    public function mapClaims(WikidataIdentifier $identifier, array $claims): WikidataEntity
    {
        return $this->map($identifier, [
            'entities' => [$identifier->qid() => ['claims' => $claims]],
        ], 'en') ?? new WikidataEntity($identifier->qid(), null, null, [], null, [], [], []);
    }

    /** @param array<string, mixed> $payload */
    public function map(WikidataIdentifier $identifier, array $payload, string $language): ?WikidataEntity
    {
        $entityQid = $identifier->qid();
        $entity    = $payload['entities'][$entityQid] ?? null;
        if (!is_array($entity) || array_key_exists('missing', $entity)) {
            foreach ($payload['redirects'] ?? [] as $redirect) {
                $from = $redirect['from'] ?? null;
                $to   = $redirect['to'] ?? null;
                if ($from === $identifier->qid() && is_string($to) && preg_match('/^Q[1-9][0-9]*$/', $to) === 1) {
                    $candidate = $payload['entities'][$to] ?? null;
                    if (is_array($candidate) && !array_key_exists('missing', $candidate)) {
                        $entityQid = $to;
                        $entity    = $candidate;
                    }
                    break;
                }
            }
        }
        if (!is_array($entity) || array_key_exists('missing', $entity)) {
            return null;
        }

        $label       = $this->languageValue($entity['labels'] ?? [], $language);
        $description = $this->languageValue($entity['descriptions'] ?? [], $language);
        $claims      = is_array($entity['claims'] ?? null) ? $entity['claims'] : [];

        return new WikidataEntity(
            $entityQid,
            $label,
            $description,
            $this->entityIds($claims['P31'] ?? []),
            $this->commonsFileName($claims['P18'] ?? []),
            $this->historicAddresses($claims),
            $this->personRelations($claims['P127'] ?? []),
            $this->personRelations($claims['P466'] ?? []),
            Coordinates::fromWikibase($this->claimValue($claims['P625'] ?? [])),
        );
    }

    /** @param mixed $values */
    private function languageValue(mixed $values, string $language): ?string
    {
        if (!is_array($values)) {
            return null;
        }

        foreach (array_unique([$language, 'en']) as $candidate) {
            $value = $values[$candidate]['value'] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param mixed $statements @return list<string> */
    private function entityIds(mixed $statements): array
    {
        if (!is_array($statements)) {
            return [];
        }

        $ids = [];
        foreach ($statements as $statement) {
            // The regular Wikibase shape is statement.mainsnak.datavalue,
            // but formatversion=2 compatible proxies may omit mainsnak or
            // expose the entity value directly. Keep the extraction focused
            // on the claim value and accept all of these representations.
            $value = is_array($statement)
                ? ($statement['mainsnak']['datavalue']['value']
                    ?? $statement['datavalue']['value']
                    ?? $statement['value']
                    ?? null)
                : $statement;
            $id = $this->entityId($value);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** Extract one Wikibase item identifier from a claim value. */
    private function entityId(mixed $value): ?string
    {
        if (is_string($value)) {
            return preg_match('/^Q[1-9][0-9]*$/', $value) === 1 ? $value : null;
        }
        if (!is_array($value)) {
            return null;
        }

        $id = $value['id'] ?? null;
        if (is_string($id) && preg_match('/^Q[1-9][0-9]*$/', $id) === 1) {
            return $id;
        }
        $numericId = $value['numeric-id'] ?? null;
        if (is_int($numericId) || (is_string($numericId) && ctype_digit($numericId))) {
            return 'Q' . $numericId;
        }

        return null;
    }

    /** @param mixed $statements */
    private function commonsFileName(mixed $statements): ?string
    {
        if (!is_array($statements)) {
            return null;
        }

        foreach ($statements as $statement) {
            $value = $statement['mainsnak']['datavalue']['value'] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
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

    /** @param array<string,mixed> $claims @return list<HistoricAddress> */
    private function historicAddresses(array $claims): array
    {
        $addresses = [];
        foreach ($claims['P669'] ?? [] as $statement) {
            $street = $statement['mainsnak']['datavalue']['value']['id'] ?? null;
            if (!is_string($street) || preg_match('/^Q[1-9][0-9]*$/', $street) !== 1) {
                continue;
            }
            $addresses[] = $this->address($statement, $street, null);
        }

        // A free-text street address is useful only where there is no
        // structured street statement for this item.
        if ($addresses === []) {
            foreach ($claims['P6375'] ?? [] as $statement) {
                $text = $statement['mainsnak']['datavalue']['value']['text'] ?? null;
                if (is_string($text) && $text !== '') {
                    $addresses[] = $this->address($statement, null, $text);
                }
            }
        }

        return $addresses;
    }

    /** @param mixed $statements @return list<HistoricPersonRelation> */
    private function personRelations(mixed $statements): array
    {
        $relations = [];
        foreach (is_array($statements) ? $statements : [] as $statement) {
            $qid = $statement['mainsnak']['datavalue']['value']['id'] ?? null;
            if (!is_string($qid) || preg_match('/^Q[1-9][0-9]*$/', $qid) !== 1) {
                continue;
            }

            $qualifiers = is_array($statement['qualifiers'] ?? null) ? $statement['qualifiers'] : [];
            $relations[] = new HistoricPersonRelation(
                $qid,
                $this->timeQualifier($qualifiers['P580'] ?? []) ?? $this->timeQualifier($qualifiers['P585'] ?? []),
                $this->timeQualifier($qualifiers['P582'] ?? []),
            );
        }

        return $relations;
    }

    private function address(mixed $statement, ?string $streetQid, ?string $streetText): HistoricAddress
    {
        $qualifiers = is_array($statement['qualifiers'] ?? null) ? $statement['qualifiers'] : [];

        return new HistoricAddress(
            $streetQid,
            $streetText,
            $this->stringQualifier($qualifiers['P670'] ?? []),
            $this->stringQualifier($qualifiers['P281'] ?? []),
            $this->entityQualifier($qualifiers['P131'] ?? []),
            $this->timeQualifier($qualifiers['P580'] ?? []) ?? $this->timeQualifier($qualifiers['P585'] ?? []),
            $this->timeQualifier($qualifiers['P582'] ?? []),
        );
    }

    private function stringQualifier(mixed $snaks): ?string
    {
        foreach (is_array($snaks) ? $snaks : [] as $snak) {
            $value = $snak['datavalue']['value'] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function entityQualifier(mixed $snaks): ?string
    {
        foreach (is_array($snaks) ? $snaks : [] as $snak) {
            $id = $snak['datavalue']['value']['id'] ?? null;
            if (is_string($id) && preg_match('/^Q[1-9][0-9]*$/', $id) === 1) {
                return $id;
            }
        }
        return null;
    }

    private function timeQualifier(mixed $snaks): ?string
    {
        foreach (is_array($snaks) ? $snaks : [] as $snak) {
            $time = $snak['datavalue']['value']['time'] ?? null;
            $precision = $snak['datavalue']['value']['precision'] ?? null;
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
}
