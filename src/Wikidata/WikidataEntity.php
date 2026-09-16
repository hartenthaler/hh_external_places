<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;

/**
 * Read-only subset of a Wikidata entity used by the first module release.
 */
final class WikidataEntity
{
    /**
     * @param list<string> $instanceOfQids
     * @param list<HistoricAddress> $historicAddresses
     * @param list<HistoricPersonRelation> $owners
     * @param list<HistoricPersonRelation> $occupants
     */
    public function __construct(
        public readonly string $qid,
        public readonly ?string $label,
        public readonly ?string $description,
        public readonly array $instanceOfQids,
        public readonly ?string $commonsFileName,
        public readonly array $historicAddresses,
        public readonly array $owners,
        public readonly array $occupants,
        public readonly ?Coordinates $coordinates = null,
    ) {
    }

    public function entityUrl(): string
    {
        return 'https://www.wikidata.org/entity/' . $this->qid;
    }
}
