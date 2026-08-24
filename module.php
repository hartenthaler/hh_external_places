<?php

declare(strict_types=1);

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\ExternalPlacesModule;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\VestaExternalPlacesModule;

// webtrees loads custom modules independently.  Register Vesta's classes
// before loading our class declaration, which implements Vesta's hook API.
$vestaAutoload = dirname(__DIR__) . '/vesta_common/autoload.php';
if (is_file($vestaAutoload)) {
    require_once $vestaAutoload;
}

require __DIR__ . '/src/MoreI18N.php';
require __DIR__ . '/src/Domain/ExternalIdentifier.php';
require __DIR__ . '/src/Domain/FactGridIdentifier.php';
require __DIR__ . '/src/Http/HttpTransport.php';
require __DIR__ . '/src/Wikibase/ReadOnlyWikibaseClient.php';
require __DIR__ . '/src/External/ExternalInformation.php';
require __DIR__ . '/src/External/ExternalPerson.php';
require __DIR__ . '/src/External/ExternalPersonRelation.php';
require __DIR__ . '/src/External/ExternalProviderCache.php';
require __DIR__ . '/src/External/ExternalProviderSettings.php';
require __DIR__ . '/src/External/GeoNamesProvider.php';
require __DIR__ . '/src/External/ExternalProvider.php';
require __DIR__ . '/src/External/WikibaseProvider.php';
require __DIR__ . '/src/External/GovProvider.php';
require __DIR__ . '/src/External/ExternalProviderRegistry.php';
require __DIR__ . '/src/Domain/WikidataIdentifier.php';
require __DIR__ . '/src/Domus/DomusLinkProvider.php';
require __DIR__ . '/src/Domus/DomusMapLinkProvider.php';
require __DIR__ . '/src/Gedcom/WikidataIdentifierLookup.php';
require __DIR__ . '/src/Gedcom/ExternalIdService.php';
require __DIR__ . '/src/Gedcom/ExternalIdEditor.php';
require __DIR__ . '/src/Gedcom/WikidataExternalIdEditor.php';
require __DIR__ . '/src/Gedcom/WikidataLocationAssignmentService.php';
require __DIR__ . '/src/Wikidata/LocationCoordinates.php';
require __DIR__ . '/src/Wikidata/NearbyDiscoverySettings.php';
require __DIR__ . '/src/Wikidata/WikidataNearbyCandidate.php';
require __DIR__ . '/src/Http/WikidataLocationAssignmentAction.php';
require __DIR__ . '/src/Http/WikidataLocationAssignmentPage.php';
require __DIR__ . '/src/Wikidata/HistoricAddress.php';
require __DIR__ . '/src/Wikidata/HistoricPersonRelation.php';
require __DIR__ . '/src/Wikidata/WikidataPerson.php';
require __DIR__ . '/src/Wikidata/WikidataEntity.php';
require __DIR__ . '/src/Wikidata/WikidataEntityMapper.php';
require __DIR__ . '/src/Wikidata/WikidataSearchResult.php';
require __DIR__ . '/src/Wikidata/WikidataClient.php';
require __DIR__ . '/src/Infrastructure/WikidataCacheSchema.php';
require __DIR__ . '/src/Infrastructure/WikidataCacheRepository.php';
require __DIR__ . '/src/ExternalPlacesModule.php';

if (interface_exists(\Vesta\Hook\HookInterfaces\PrintFunctionsPlaceInterface::class)) {
    require __DIR__ . '/src/VestaExternalPlacesModule.php';

    return new VestaExternalPlacesModule();
}

return new ExternalPlacesModule();
