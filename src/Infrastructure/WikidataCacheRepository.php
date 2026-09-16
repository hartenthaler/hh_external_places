<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Infrastructure;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\WikidataIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata\WikidataEntity;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata\HistoricAddress;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata\HistoricPersonRelation;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;
use Illuminate\Database\Capsule\Manager as DB;
use JsonException;

/** Persists a deliberately small, language-specific and expiring entity cache. */
final class WikidataCacheRepository
{
    public const DEFAULT_TTL = 604800; // Seven days.

    public function find(WikidataIdentifier $identifier, string $language): ?WikidataEntity
    {
        $row = DB::table(WikidataCacheSchema::TABLE)
            ->where('qid', '=', $identifier->qid())
            ->where('language', '=', $language)
            ->where('expires_at', '>', time())
            ->first();

        if ($row === null || !is_string($row->payload)) {
            return null;
        }

        try {
            $payload = json_decode($row->payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $qid = $payload['qid'] ?? null;
        if (!is_string($qid) || $qid !== $identifier->qid() || !is_array($payload['historic_addresses'] ?? null) || !is_array($payload['owners'] ?? null) || !is_array($payload['occupants'] ?? null)) {
            return null;
        }

        $addresses = [];
        foreach ($payload['historic_addresses'] as $address) {
            if (!is_array($address)) { continue; }
            $addresses[] = new HistoricAddress(
                is_string($address['street_qid'] ?? null) ? $address['street_qid'] : null,
                is_string($address['street_text'] ?? null) ? $address['street_text'] : null,
                is_string($address['house_number'] ?? null) ? $address['house_number'] : null,
                is_string($address['postal_code'] ?? null) ? $address['postal_code'] : null,
                is_string($address['locality_qid'] ?? null) ? $address['locality_qid'] : null,
                is_string($address['from'] ?? null) ? $address['from'] : null,
                is_string($address['until'] ?? null) ? $address['until'] : null,
            );
        }

        $relations = static function (array $items): array {
            $result = [];
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['qid'] ?? null)) {
                    continue;
                }
                $result[] = new HistoricPersonRelation(
                    $item['qid'],
                    is_string($item['from'] ?? null) ? $item['from'] : null,
                    is_string($item['until'] ?? null) ? $item['until'] : null,
                );
            }
            return $result;
        };

        return new WikidataEntity(
            $qid,
            is_string($payload['label'] ?? null) ? $payload['label'] : null,
            is_string($payload['description'] ?? null) ? $payload['description'] : null,
            array_values(array_filter($payload['instance_of'] ?? [], 'is_string')),
            is_string($payload['commons_file_name'] ?? null) ? $payload['commons_file_name'] : null,
            $addresses,
            $relations($payload['owners']),
            $relations($payload['occupants']),
            is_array($payload['coordinates'] ?? null) ? Coordinates::fromArray($payload['coordinates']) : null,
        );
    }

    public function store(WikidataEntity $entity, string $language, int $ttl = self::DEFAULT_TTL): void
    {
        $now     = time();
        $payload = json_encode([
            'qid'               => $entity->qid,
            'label'             => $entity->label,
            'description'       => $entity->description,
            'instance_of'       => $entity->instanceOfQids,
            'commons_file_name' => $entity->commonsFileName,
            'coordinates' => $entity->coordinates?->toArray(),
            'historic_addresses' => array_map(static fn (HistoricAddress $address): array => [
                'street_qid' => $address->streetQid, 'street_text' => $address->streetText,
                'house_number' => $address->houseNumber, 'postal_code' => $address->postalCode,
                'locality_qid' => $address->localityQid, 'from' => $address->from, 'until' => $address->until,
            ], $entity->historicAddresses),
            'owners' => array_map(static fn (HistoricPersonRelation $relation): array => [
                'qid' => $relation->qid, 'from' => $relation->from, 'until' => $relation->until,
            ], $entity->owners),
            'occupants' => array_map(static fn (HistoricPersonRelation $relation): array => [
                'qid' => $relation->qid, 'from' => $relation->from, 'until' => $relation->until,
            ], $entity->occupants),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        DB::table(WikidataCacheSchema::TABLE)->updateOrInsert(
            ['qid' => $entity->qid, 'language' => $language],
            [
                'payload'    => $payload,
                'fetched_at' => $now,
                'expires_at' => $now + max(60, $ttl),
            ],
        );
    }
}
