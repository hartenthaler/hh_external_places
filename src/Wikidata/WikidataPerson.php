<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata;

/** A minimal, external-only presentation of a Wikidata person or organisation. */
final class WikidataPerson
{
    /** @param array<string,string> $externalLinks */
    public function __construct(
        public readonly string $qid,
        public readonly ?string $label,
        public readonly ?string $birthDate,
        public readonly ?string $deathDate,
        public readonly array $externalLinks = [],
        public readonly ?string $sex = null,
    ) {
    }

    public function entityUrl(): string
    {
        return 'https://www.wikidata.org/entity/' . $this->qid;
    }
}
