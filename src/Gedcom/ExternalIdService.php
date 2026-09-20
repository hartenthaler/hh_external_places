<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\WikidataIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;

/**
 * Compatibility adapter for the legacy Wikidata lookup API.
 *
 * Generic EXID parsing is delegated to ExternalProviderRegistry. That
 * registry uses hh_exid when available and its local parser otherwise.
 */
final class ExternalIdService
{
    public function wikidataIdentifiers(string $gedcom): WikidataIdentifierLookup
    {
        $identifiers = [];
        foreach ((new ExternalProviderRegistry())->parse($gedcom) as $identifier) {
            if ($identifier->provider !== 'wikidata') {
                continue;
            }

            $wikidata = WikidataIdentifier::tryFrom($identifier->value);
            if ($wikidata !== null) {
                $identifiers[$wikidata->qid()] = $wikidata;
            }
        }

        return new WikidataIdentifierLookup(array_values($identifiers));
    }
}
