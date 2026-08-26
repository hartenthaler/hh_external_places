<?php

declare(strict_types=1);

// The dedicated external-information handler may be dispatched before the
// module's boot() method. Register its view namespace during module loading
// so webtrees can render it with the normal page layout on all supported
// versions.
\Fisharebest\Webtrees\View::registerNamespace(
    'hh_external_places',
    __DIR__ . '/resources/views/',
);
// Vesta resolves its shared-place templates by module name. Register these
// replacements during loading as well as in boot(), so the tab is available
// even when the shared-place request is rendered before module boot order.
foreach (['vesta_shared_places', 'vesta_shared_places_20', 'Vesta_Views_Namespace'] as $vestaNamespace) {
    \Fisharebest\Webtrees\View::registerCustomView(
        $vestaNamespace . '::shared-place-page-links',
        'hh_external_places::shared-place-page-links',
    );
    \Fisharebest\Webtrees\View::registerCustomView(
        $vestaNamespace . '::shared-place-page_20',
        'hh_external_places::shared-place-page_20',
    );
}

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
require __DIR__ . '/src/External/ExternalProvider.php';
require __DIR__ . '/src/External/ExternalProviderSettings.php';
require __DIR__ . '/src/External/PlaceTypeFilterSettings.php';
require __DIR__ . '/src/External/GeoNamesProvider.php';
require __DIR__ . '/src/External/GovExternalIdentifierCatalog.php';
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
require __DIR__ . '/src/Http/ExternalInformationPage.php';
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
