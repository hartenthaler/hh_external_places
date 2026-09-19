<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule;

// Keep the settings value available when webtrees loads this class through
// its module loader instead of the legacy module.php bootstrap sequence.
require_once __DIR__ . '/External/PlaceTypeFilterSettings.php';

use Fisharebest\Localization\Translation;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Infrastructure\WikibaseCacheSchema;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\ExternalIdService;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\GovTypeValidator;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalInformation;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderSettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\GeoNamesProvider;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\LanguageCode;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\NominatimProvider;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\GovExternalIdentifierCatalog;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\PlaceTypeFilterSettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\CoordinateConsistencySettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikibase\ReadOnlyWikibaseClient;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domus\DomusMapLinkProvider;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\WikidataLocationAssignmentPage;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\ExternalInformationPage;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Presentation\ExternalInformationRenderer;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata\WikidataClient;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata\NearbyDiscoverySettings;
use Vesta\Model\GenericViewElement;
use Vesta\Model\GovReference;
use Vesta\Model\LocReference;
use Vesta\Model\MapCoordinates;
use Vesta\Model\PlaceStructure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function file_exists;
use function route;

class ExternalPlacesModule extends AbstractModule implements ModuleConfigInterface, ModuleCustomInterface
{
    use ModuleCustomTrait;
    use ModuleConfigTrait;

    private const MODULE_NAME = 'hh_external_places';
    private const GITHUB_USER = 'hartenthaler';
    private const CACHE_SCHEMA_VERSION_PREFERENCE = 'wikibase_cache_schema_version';
    private const ASSIGNMENT_ROUTE_NAME = 'hh-external-places.assignment-page';
    private const ASSIGNMENT_ROUTE_PATH = '/tree/{tree}/external-place/{xref}/assignment';
    private const EXTERNAL_INFORMATION_ROUTE_PATH = '/tree/{tree}/external-place/{xref}/information';
    // Keep the site preference below webtrees' setting_name column limit.
    private const SHOW_CONSISTENT_REFERENCES_PREFERENCE = 'HH_EP_SHOW_CONSISTENT';

    public function boot(): void
    {
        $currentVersion = (int) $this->getPreference(self::CACHE_SCHEMA_VERSION_PREFERENCE, '0');
        $targetVersion  = (new WikibaseCacheSchema())->ensureSchema($currentVersion);

        if ($targetVersion !== $currentVersion) {
            $this->setPreference(self::CACHE_SCHEMA_VERSION_PREFERENCE, (string) $targetVersion);
        }

        View::registerNamespace(self::MODULE_NAME, $this->resourcesFolder() . 'views/');
        if (class_exists('Vesta\\VestaUtils')) {
            $vestaNamespace = \Vesta\VestaUtils::vestaViewsNamespace();
            if (is_string($vestaNamespace) && $vestaNamespace !== '') {
                View::registerCustomView($vestaNamespace . '::shared-place-page-links', self::MODULE_NAME . '::shared-place-page-links');
                View::registerCustomView($vestaNamespace . '::shared-place-page-links_20', self::MODULE_NAME . '::shared-place-page-links');
            }
        }
        // Shared Places renders its page through its own module namespace.
        // Register both supported view variants without depending on the
        // concrete Vesta module class name at compile time.
        foreach (['vesta_shared_places', 'vesta_shared_places_20'] as $vestaModuleNamespace) {
            View::registerCustomView($vestaModuleNamespace . '::shared-place-page-links', self::MODULE_NAME . '::shared-place-page-links');
            View::registerCustomView($vestaModuleNamespace . '::shared-place-page_20', self::MODULE_NAME . '::shared-place-page_20');
        }
        $router = Registry::routeFactory()->routeMap();
        if (method_exists($router, 'add')) {
            // webtrees 2.3 identifies routes by their request-handler class.
            $router->add(self::ASSIGNMENT_ROUTE_PATH, WikidataLocationAssignmentPage::class);
            $router->add(self::EXTERNAL_INFORMATION_ROUTE_PATH, ExternalInformationPage::class);
        } else {
            // webtrees 2.2 uses an explicit route name and HTTP verb map.
            $router->get(
                self::ASSIGNMENT_ROUTE_NAME,
                self::ASSIGNMENT_ROUTE_PATH,
                WikidataLocationAssignmentPage::class,
            )->allows(['GET', 'POST']);
            $router->get(
                'hh-external-places.external-information',
                self::EXTERNAL_INFORMATION_ROUTE_PATH,
                ExternalInformationPage::class,
            );
        }
    }

    /**
     * Build a URL for the assignment page on both webtrees routing APIs.
     *
     * @param array<string, scalar> $parameters
     */
    public static function assignmentUrl(array $parameters): string
    {
        $routeMap = Registry::routeFactory()->routeMap();
        $routeName = method_exists($routeMap, 'add')
            ? WikidataLocationAssignmentPage::class
            : self::ASSIGNMENT_ROUTE_NAME;

        return route($routeName, $parameters);
    }

    /** Build the dedicated external-information URL on both routing APIs. */
    public static function externalInformationUrl(array $parameters): string
    {
        $routeMap = Registry::routeFactory()->routeMap();
        $routeName = method_exists($routeMap, 'add')
            ? ExternalInformationPage::class
            : 'hh-external-places.external-information';

        return route($routeName, $parameters);
    }

    /** Keep the Vesta summary compact; the full output lives on its own page. */
    public function plac2html(PlaceStructure $place): ?GenericViewElement
    {
        $location = $place->getLocation();
        if ($location === null) {
            return null;
        }

        if (ExternalProviderSettings::enabled() === []) {
            return GenericViewElement::create('<div class="alert alert-warning">' . e(I18N::translate('At least one external information provider must be enabled in the module settings.')) . '</div>');
        }

        $url = self::externalInformationUrl(['tree' => $location->tree()->name(), 'xref' => $location->xref()]);
        return GenericViewElement::create('<a class="btn btn-outline-secondary btn-sm" href="' . e($url) . '">' . e(I18N::translate('External information')) . '</a>');
    }

    /** Render all external provider information for the dedicated page. */
    public function externalInformationForPlace(PlaceStructure $place): ?GenericViewElement
    {
        $location = $place->getLocation();
        if ($location === null) {
            return null;
        }

        if (ExternalProviderSettings::enabled() === []) {
            return GenericViewElement::create('<div class="alert alert-warning">' . e(I18N::translate('At least one external information provider must be enabled in the module settings.')) . '</div>');
        }

        $providerRegistry = new ExternalProviderRegistry();
        $externalIdentifiers = $providerRegistry->parse($location->gedcom());
        foreach ($providerRegistry->errors() as $error) {
            FlashMessages::addMessage(I18N::translate($error), 'danger');
        }
        $lookup = (new ExternalIdService())->wikidataIdentifiers($location->gedcom());
        $wikidataEnabled = ExternalProviderSettings::isEnabled('wikidata');
        if ($wikidataEnabled && $lookup->isAmbiguous()) {
            return GenericViewElement::create('<div class="alert alert-warning">' . e(I18N::translate('Several Wikidata identifiers are configured for this shared place.')) . '</div>');
        }

        $identifier = $lookup->identifier();
        $sharedCoordinates = Coordinates::fromGedcom($location->gedcom());
        $coordinates = $sharedCoordinates?->toArray();
        // Domus deep-links are meaningful only for a known Wikidata item.
        // Do not show a generic Domus start-page button for places without a
        // Wikidata assignment.
        $domusUrl = $wikidataEnabled && $identifier !== null
            ? (new DomusMapLinkProvider())->url($identifier, $coordinates)
            : '';
        if ($identifier === null || !$wikidataEnabled) {
            $language = explode('-', str_replace('_', '-', I18N::languageTag()))[0] ?: 'en';
            $renderer = new ExternalInformationRenderer(self::showConsistentReferences());
            $geoNamesHtml = $renderer->geoNamesHtml($location->fullName(), $language);
            $nominatimHtml = $renderer->nominatimHtml($renderer->nominatimPlaceName($location->gedcom(), $this->nominatimPlaceContext($place, $location->fullName())), $language, $location->gedcom());
            if ($externalIdentifiers === [] && !$location->canEdit() && $geoNamesHtml === '' && $nominatimHtml === '') {
                return null;
            }

            $assignmentUrl = $location->canEdit() ? self::assignmentUrl(['tree' => $location->tree()->name(), 'xref' => $location->xref()]) : null;
            $genwikiShown = [];
            $html = $renderer->externalInformationHtml($externalIdentifiers, $language, '', $location->fullName(), $assignmentUrl, $location->gedcom(), $genwikiShown, $sharedCoordinates);
            $html .= $geoNamesHtml;
            $html .= $nominatimHtml;
            $html .= '<div class="d-flex gap-2 flex-wrap mt-2">';
            if ($location->canEdit()) {
                $html .= '<a class="btn btn-primary btn-sm" href="' . e(self::assignmentUrl(['tree' => $location->tree()->name(), 'xref' => $location->xref()])) . '">' . e(I18N::translate('Assign external identifier')) . '</a>';
            }
            if ($domusUrl !== '') {
                $html .= '<a class="btn btn-primary btn-sm" href="' . e($domusUrl) . '" rel="noopener noreferrer" target="_blank">' . e(I18N::translate('Show in Domus')) . '</a>';
            }
            $html .= '</div>';
            return GenericViewElement::create($html);
        }

        $language = explode('-', str_replace('_', '-', I18N::languageTag()))[0] ?: 'en';
        $renderer = new ExternalInformationRenderer(self::showConsistentReferences());
        $wikidataClient = new WikidataClient();
        $entity   = $wikidataClient->fetch($identifier, $language);

        $label = $entity?->label ?? $identifier->qid();
        $html  = '<section class="mt-4">' . $renderer->providerHeading('wikidata', I18N::translate('Wikidata'));
        $html .= '<a href="' . e($identifier->entityUrl()) . '" rel="noopener noreferrer" target="_blank">' . e($label) . '</a> (' . e($identifier->qid()) . ')';
        if ($entity?->description !== null) {
            $html .= ' — ' . e($entity->description);
        }
        if ($entity !== null && $entity->instanceOfQids !== []) {
            $typeLabels = (new WikidataClient())->labels($entity->instanceOfQids, $language);
            $types      = array_map(static fn (string $qid): string => $typeLabels[$qid] ?? $qid, $entity->instanceOfQids);
            $html .= '<br>' . e(MoreI18N::xlate('Type')) . ': ' . e(implode(', ', $types));
        }
        if ($entity?->coordinates !== null) {
            $html .= $renderer->coordinateConsistencyHtml($entity->coordinates, $sharedCoordinates, 'Wikidata', $location->gedcom(), $location->canEdit() ? self::assignmentUrl(['tree' => $location->tree()->name(), 'xref' => $location->xref()]) : null);
        }
        if ($entity !== null && $entity->historicAddresses !== []) {
            $addressQids = [];
            foreach ($entity->historicAddresses as $address) {
                foreach ([$address->streetQid, $address->localityQid] as $qid) {
                    if ($qid !== null) { $addressQids[] = $qid; }
                }
            }
            $addressLabels = (new WikidataClient())->labels($addressQids, $language);
            $html .= '<h5 class="mt-3">' . e(MoreI18N::xlate('Addresses')) . '</h5><div class="table-responsive"><table class="table table-sm"><thead><tr>'
                . '<th>' . e(I18N::translate('House number')) . '</th><th>' . e(I18N::translate('Street')) . '</th><th>' . e(MoreI18N::xlate('Postal code')) . '</th><th>' . e(MoreI18N::xlate('Place')) . '</th><th>' . e(MoreI18N::xlate('From')) . '</th><th>' . e(I18N::translate('Until')) . '</th></tr></thead><tbody>';
            foreach ($entity->historicAddresses as $address) {
                $street = $address->streetText ?? ($address->streetQid === null ? '' : ($addressLabels[$address->streetQid] ?? $address->streetQid));
                $placeName = $address->localityQid === null ? '' : ($addressLabels[$address->localityQid] ?? $address->localityQid);
                $html .= '<tr><td>' . e($address->houseNumber ?? '') . '</td><td>' . e($street) . '</td><td>' . e($address->postalCode ?? '') . '</td><td>' . e($placeName) . '</td><td>' . e($address->from ?? '') . '</td><td>' . e($address->until ?? '') . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        if ($entity !== null && ($entity->owners !== [] || $entity->occupants !== [])) {
            $people = (new WikidataClient())->people(
                array_merge(
                    array_map(static fn ($relation): string => $relation->qid, $entity->owners),
                    array_map(static fn ($relation): string => $relation->qid, $entity->occupants),
                ),
                $language,
            );
            $html .= $renderer->personRelationsHtml(MoreI18N::xlate('Owner'), $entity->owners, $people);
            $html .= $renderer->personRelationsHtml(I18N::translate('Occupants'), $entity->occupants, $people);
        }
        if ($entity?->commonsFileName !== null) {
            $fileUrl = 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($entity->commonsFileName);
            $html .= '<br><a href="' . e($fileUrl) . '" rel="noopener noreferrer" target="_blank"><img src="' . e($fileUrl) . '" alt="' . e(I18N::translate('Image on Wikimedia Commons')) . '" loading="lazy" style="max-width:500px;max-height:500px;width:auto;height:auto"></a>';
        }
        if ($entity === null) {
            $html .= ' — <small>' . e(I18N::translate('Wikidata details are currently unavailable.')) . '</small>';
        }
        $wikidataProvider = (new ExternalProviderRegistry())->byAuthority('https://www.wikidata.org/entity/');
        $genwikiShown = [];
        $wikidataReference = $wikidataProvider?->fetch(
            new \Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier('wikidata', $identifier->qid(), 'https://www.wikidata.org/entity/', $identifier->entityUrl()),
            $language,
        );
        if ($wikidataReference !== null) {
                    $assignmentUrl = $location->canEdit() ? self::assignmentUrl(['tree' => $location->tree()->name(), 'xref' => $location->xref()]) : null;
                    $html .= $renderer->crossReferenceHtml($wikidataReference, $externalIdentifiers, $assignmentUrl);
        }
        $html .= $renderer->sourceHtml('Wikidata', 'https://www.wikidata.org/') . '</section>';
        $assignmentUrl = $location->canEdit() ? self::assignmentUrl(['tree' => $location->tree()->name(), 'xref' => $location->xref()]) : null;
        $html .= $renderer->externalInformationHtml($externalIdentifiers, $language, 'wikidata', $location->fullName(), $assignmentUrl, $location->gedcom(), $genwikiShown, $sharedCoordinates);
        $html .= $renderer->geoNamesHtml($location->fullName(), $language);
        $html .= $renderer->nominatimHtml($renderer->nominatimPlaceName($location->gedcom(), $this->nominatimPlaceContext($place, $location->fullName())), $language, $location->gedcom());
        $html .= '<div class="d-flex gap-2 flex-wrap mt-2">';
        if ($location->canEdit()) {
            $html .= '<a class="btn btn-primary btn-sm" href="' . e(self::assignmentUrl(['tree' => $location->tree()->name(), 'xref' => $location->xref()])) . '">' . e(I18N::translate('Assign external identifier')) . '</a>';
        }
        if ($domusUrl !== '') {
            $html .= '<a class="btn btn-primary btn-sm" href="' . e($domusUrl) . '" rel="noopener noreferrer" target="_blank">' . e(I18N::translate('Show in Domus')) . '</a>';
        }
        $html .= '</div>';

        return GenericViewElement::create($html);
    }

    /**
     * Return the immediate parent place as context for geocoder searches.
     *
     * The first NAME in the shared-place GEDCOM is selected by
     * nominatimPlaceName(); this method supplies only the next place level so
     * alternate NAME values cannot replace the address or local place.
     */
    private function nominatimPlaceContext(PlaceStructure $place, string $fallback): string
    {
        $parent = $place->parent();
        if ($parent !== null && trim($parent->getGedcomName()) !== '') {
            return $parent->getGedcomName();
        }

        return $fallback;
    }

    /** Render one canonical GenWiki page and optionally offer its ID for EXID. */


    /**
     * Use the first NAME and the first place-context component for the
     * geocoder query. Vesta's primaryPlace() may select another NAME after
     * language/date sorting, but alternate names should not replace the
     * record's first name for an address lookup. Adding the local place keeps
     * common street names from resolving to an unrelated city.
     */








    /** @return array<string,list<string>> */

    /** @param array<string,int|float> $population */

    /** @param array<string,int|float> $population */

    /** @return array{0:int,1:int,2:string} */







    /** @param list<\Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier> $identifiers */

    private static function showConsistentReferences(): bool
    {
        return Site::getPreference(self::SHOW_CONSISTENT_REFERENCES_PREFERENCE, '1') === '1';
    }

    /** @param list<object{qid:string,from:?string,until:?string}> $relations @param array<string,object{qid:string,label:?string,birthDate:?string,deathDate:?string}> $people */


    public function gov2html(GovReference $gov, Tree $tree): ?GenericViewElement
    {
        return null;
    }

    public function map2html(MapCoordinates $map): ?GenericViewElement
    {
        return null;
    }

    public function loc2linkIcon(LocReference $loc): ?string
    {
        return null;
    }

    public function title(): string
    {
        return I18N::translate('External Places');
    }

    public function description(): string
    {
        return I18N::translate('Links shared places to Wikidata, FactGrid and GOV and displays read-only external place information, optionally including GeoNames context.');
    }

    public function getAdminAction(): ResponseInterface
    {
        $this->layout = 'layouts/administration';
        $trees = Registry::container()->get(TreeService::class)->all();
        $radiusExceptions = NearbyDiscoverySettings::exceptions();
        $typeFilters = PlaceTypeFilterSettings::levels();
        $typeLabels = [];
        $language = explode('-', str_replace('_', '-', I18N::languageTag()))[0] ?: 'en';
        $wikibaseClient = new ReadOnlyWikibaseClient();
        foreach ($typeFilters as $level => $filters) {
            foreach (['wikidata', 'factgrid'] as $provider) {
                $providerTypes = $filters[$provider] ?? [];
                $providerLabels = $provider === 'wikidata'
                    ? array_map(static fn (array $entity): array => (array) ($entity['labels'] ?? []), $wikibaseClient->entities($provider, $providerTypes, $language))
                    : $wikibaseClient->labels($provider, $providerTypes, $language);
                foreach ($providerTypes as $qid) {
                    $labels = $providerLabels[$qid] ?? [];
                    $label = $labels[$language]['value'] ?? $labels['en']['value'] ?? null;
                    $resolvedLabel = is_string($label) && $label !== '' ? $label : $qid;
                    $typeLabels[$level][$provider][$qid] = $resolvedLabel;
                }
            }
            foreach (['gov', 'geonames'] as $provider) {
                foreach ($filters[$provider] ?? [] as $value) {
                    $label = $provider === 'geonames' ? PlaceTypeFilterSettings::geonamesLabel($value) : PlaceTypeFilterSettings::govLabel($value, $language);
                    $typeLabels[$level][$provider][$value] = I18N::translate($label);
                }
            }
        }

        return $this->viewResponse(self::MODULE_NAME . '::configuration', [
            'all_trees' => $trees,
            'default_radius_km' => NearbyDiscoverySettings::globalRadius(),
            'radius_exceptions' => $radiusExceptions,
            'coordinate_tolerances' => CoordinateConsistencySettings::all(),
            'enabled_providers' => ExternalProviderSettings::enabled(),
            'provider_labels' => ExternalProviderSettings::labels(),
            'type_filters' => $typeFilters,
            'type_labels' => $typeLabels,
            'show_consistent_references' => self::showConsistentReferences(),
            'title' => $this->title(),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $providers = is_array($body['providers'] ?? null) ? array_map('strval', $body['providers']) : [];
        ExternalProviderSettings::save($providers);
        Site::setPreference(self::SHOW_CONSISTENT_REFERENCES_PREFERENCE, isset($body['show-consistent-references']) ? '1' : '0');
        $coordinateSettingsChanged = false;
        if (is_array($body['coordinate-tolerances'] ?? null)) {
            CoordinateConsistencySettings::save($body['coordinate-tolerances']);
            $coordinateSettingsChanged = true;
        }
        if (in_array('geonames', $providers, true)) {
            $geoNamesStatus = (new GeoNamesProvider())->configurationStatus();
            if (!$geoNamesStatus['active']) {
                FlashMessages::addMessage(I18N::translate('GeoNames is not enabled in webtrees. Enable it in Control panel / Geographical data / Geolocation / GeoNames.'), 'warning');
            } elseif (!$geoNamesStatus['username']) {
                FlashMessages::addMessage(I18N::translate('No GeoNames username is configured. Enter it in Control panel / Geographical data / Geolocation / GeoNames.'), 'warning');
            }
        }
        $typeFilters = is_array($body['type-filters'] ?? null) ? $body['type-filters'] : [];
        $reset = trim((string) ($body['reset-type-filter'] ?? ''));
        $resetAll = isset($body['reset-all-type-filters']);
        // Keep the legacy variable available for the success-message branch
        // below; a normal settings save has no reset action.
        $resetProvider = '';
        $filterReset = false;
        if ($resetAll) {
            PlaceTypeFilterSettings::resetAll();
            $filterReset = true;
            FlashMessages::addMessage(I18N::translate('All provider filter types for all hierarchy levels were reset to their defaults.'), 'success');
        } elseif ($reset !== '') {
            [$resetLevel, $resetProvider] = array_pad(explode(':', $reset, 2), 2, '');
            if ($resetLevel === 'house') { PlaceTypeFilterSettings::reset($resetProvider); }
            else {
                $defaults = PlaceTypeFilterSettings::levels();
                $defaults[$resetLevel][$resetProvider] = PlaceTypeFilterSettings::defaultsForLevel($resetLevel)[$resetProvider] ?? [];
                PlaceTypeFilterSettings::saveLevels($defaults);
            }
            $providerLabel = [
                'wikidata' => 'Wikidata',
                'factgrid' => 'FactGrid',
                'gov' => 'GOV',
                'geonames' => 'GeoNames',
            ][$resetProvider] ?? $resetProvider;
            $levelLabel = PlaceTypeFilterSettings::levelLabel($resetLevel);
            FlashMessages::addMessage(I18N::translate('The filter types for the "%s" level and %s were reset to their defaults.', I18N::translate($levelLabel), $providerLabel), 'success');
        } else {
            PlaceTypeFilterSettings::saveLevels($typeFilters);
        }
        $globalInput = str_replace(',', '.', trim((string) ($body['global-radius-km'] ?? '')));
        $globalRadius = is_numeric($globalInput) ? (float) $globalInput : NearbyDiscoverySettings::DEFAULT_RADIUS_KM;
        $oldGlobalRadius = NearbyDiscoverySettings::globalRadius();
        $oldExceptions = NearbyDiscoverySettings::exceptions();
        $exceptions = is_array($body['radius-exceptions'] ?? null) ? array_map('strval', $body['radius-exceptions']) : [];

        // A selected tree can be added without requiring a long form row for
        // every tree.  Values equal to the global radius are deliberately not
        // stored: they are not exceptions.
        // Accept the previous hyphenated field names as well, so a cached
        // configuration page cannot silently discard an added exception.
        $exceptionAction = (string) ($body['radius_exception_action'] ?? $body['radius-exception-action'] ?? '');
        $exceptionTree = trim((string) ($body['radius_exception_tree'] ?? $body['radius-exception-tree'] ?? ''));
        $exceptionValue = trim((string) ($body['radius_exception_value'] ?? $body['radius-exception-value'] ?? ''));
        $exceptionRemoved = false;
        $parsedExceptionValue = NearbyDiscoverySettings::parse($exceptionValue);
        if (($exceptionAction === 'add' || ($exceptionTree !== '' && $exceptionValue !== '')) && $exceptionTree !== '' && $parsedExceptionValue !== null) {
            if ($parsedExceptionValue === NearbyDiscoverySettings::normalise((string) $globalRadius)) {
                unset($exceptions[$exceptionTree]);
                $exceptionRemoved = true;
            } else {
                $exceptions[$exceptionTree] = (string) $parsedExceptionValue;
            }
        } elseif ($exceptionAction === 'add') {
            FlashMessages::addMessage(I18N::translate('Select a family tree and enter a radius before adding an exception.'), 'warning');
        }
        NearbyDiscoverySettings::save($globalRadius, $exceptions);
        // Compare normalised values. Form fields contain strings, whereas
        // persisted exceptions are read back as floats; comparing the raw
        // arrays would report a false change on every save.
        $savedExceptions = NearbyDiscoverySettings::exceptions();
        $radiusChanged = NearbyDiscoverySettings::normalise((string) $oldGlobalRadius) !== NearbyDiscoverySettings::normalise((string) $globalRadius)
            || $oldExceptions !== $savedExceptions;

        if ($exceptionTree !== '' && $parsedExceptionValue !== null) {
            $trees = Registry::container()->get(TreeService::class)->all();
            $tree = $trees->first(static fn (Tree $candidate): bool => (string) $candidate->id() === $exceptionTree);
            $treeTitle = $tree instanceof Tree ? $tree->title() : $exceptionTree;
            $message = $exceptionRemoved
                ? I18N::translate('The exception for family tree %s was removed. The default radius of %s km applies.', $treeTitle, number_format($globalRadius, 1))
                : I18N::translate('The nearby-search radius for family tree %s is now %s km.', $treeTitle, number_format($parsedExceptionValue, 1));
            FlashMessages::addMessage($message, 'success');
        } elseif (!$filterReset && $resetProvider === '') {
            $message = $coordinateSettingsChanged
                ? I18N::translate('Coordinate consistency settings have been updated.')
                : I18N::translate($radiusChanged ? 'Nearby search settings have been updated.' : 'External Places settings have been updated.');
            FlashMessages::addMessage($message, 'success');
        }

        return redirect($this->getConfigLink());
    }

    /**
     * Privacy information consumed opportunistically by hh_legal_notice.
     *
     * There is intentionally no interface dependency: External Places must
     * also load on installations that do not use the Legal Notice module.
     *
     * @return array{
     *     third_party_services:list<array{
     *         service_id:string,
     *         name:string,
     *         url:string,
     *         country:string,
     *         privacy_url:string,
     *         description:string,
     *         data:list<string>
     *     }>,
     *     security_measures:list<string>
     * }
     */
    public function privacyNotices(): array
    {
        return [
            'third_party_services' => [[
                'service_id'  => 'wikimedia-foundation',
                'name'        => 'Wikimedia Foundation (Wikidata)',
                'url'         => 'https://www.wikidata.org/',
                'country'     => 'United States',
                'privacy_url' => 'https://foundation.wikimedia.org/wiki/Policy:Privacy_policy',
                'description' => I18N::translate('The module retrieves public place information from Wikidata. Editors can also search Wikidata to assign an item to a shared place.'),
                'data'        => [
                    I18N::translate('Wikidata item identifiers and the requested display language.'),
                    I18N::translate('Search text entered by an editor.'),
                    I18N::translate('Coordinates and the configured radius for an editor-requested nearby search.'),
                    I18N::translate('The server IP address and technical request metadata.'),
                ],
            ], [
                'service_id'  => 'factgrid',
                'name'        => 'FactGrid',
                'url'         => 'https://database.factgrid.de/',
                'country'     => 'Germany',
                'privacy_url' => 'https://database.factgrid.de/wiki/FactGrid:Privacy_policy',
                'description' => I18N::translate('The module retrieves public place information from FactGrid when a shared place has a typed FactGrid identifier.'),
                'data'        => [I18N::translate('FactGrid item identifiers and the requested display language.')],
            ], [
                'service_id'  => 'gov',
                'name'        => 'GOV',
                'url'         => 'https://gov.genealogy.net/',
                'country'     => 'Germany',
                'privacy_url' => 'https://www.genealogy.net/impressum/',
                'description' => I18N::translate('The module retrieves public place information from GOV when a shared place has a typed GOV identifier.'),
                'data'        => [I18N::translate('GOV identifiers and the requested display language.')],
            ], [
                'service_id'  => 'geonames',
                'name'        => 'GeoNames',
                'url'         => 'https://www.geonames.org/',
                'country'     => 'International',
                'privacy_url' => 'https://www.geonames.org/terms-of-service.html',
                'description' => I18N::translate('The module retrieves public contextual place information from GeoNames when the provider is enabled and a GeoNames username is configured.'),
                'data'        => [I18N::translate('The shared-place name, requested display language and the server IP address.')],
            ], [
                'service_id'  => 'nominatim',
                'name'        => 'Nominatim / OpenStreetMap',
                'url'         => 'https://nominatim.openstreetmap.org/',
                'country'     => 'International',
                'privacy_url' => 'https://operations.osmfoundation.org/policies/nominatim/',
                'description' => I18N::translate('The module retrieves public contextual place information from Nominatim when the provider is enabled.'),
                'data'        => [I18N::translate('The shared-place name, requested display language and the server IP address.')],
            ]],
            'security_measures' => [
                I18N::translate('Wikidata responses are cached locally to reduce external requests.'),
            ],
        ];
    }

    public function customModuleAuthorName(): string
    {
        return 'Hermann Hartenthaler';
    }

    public function customModuleVersion(): string
    {
        return trim((string) file_get_contents(__DIR__ . '/../version.txt'));
    }

    public function customModuleLatestVersionUrl(): string
    {
        return 'https://raw.githubusercontent.com/' . self::GITHUB_USER . '/' . self::MODULE_NAME . '/main/version.txt';
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/' . self::GITHUB_USER . '/' . self::MODULE_NAME;
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }

    public function customTranslations(string $language): array
    {
        $file = $this->resourcesFolder() . 'lang/' . $language . '.mo';
        return file_exists($file) ? (new Translation($file))->asArray() : [];
    }
}
