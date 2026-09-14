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
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Infrastructure\WikidataCacheSchema;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Infrastructure\WikidataCacheRepository;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\ExternalIdService;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\GovTypeValidator;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalInformation;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderSettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\GeoNamesProvider;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\GeoNamesLanguage;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\NominatimProvider;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\GovExternalIdentifierCatalog;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\PlaceTypeFilterSettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikibase\ReadOnlyWikibaseClient;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domus\DomusMapLinkProvider;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\WikidataLocationAssignmentPage;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\ExternalInformationPage;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata\WikidataClient;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata\NearbyDiscoverySettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata\LocationCoordinates;
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
    private const CACHE_SCHEMA_VERSION_PREFERENCE = 'wikidata_cache_schema_version';
    private const ASSIGNMENT_ROUTE_NAME = 'hh-external-places.assignment-page';
    private const ASSIGNMENT_ROUTE_PATH = '/tree/{tree}/external-place/{xref}/assignment';
    private const EXTERNAL_INFORMATION_ROUTE_PATH = '/tree/{tree}/external-place/{xref}/information';
    // Keep the site preference below webtrees' setting_name column limit.
    private const SHOW_CONSISTENT_REFERENCES_PREFERENCE = 'HH_EP_SHOW_CONSISTENT';

    public function boot(): void
    {
        $currentVersion = (int) $this->getPreference(self::CACHE_SCHEMA_VERSION_PREFERENCE, '0');
        $targetVersion  = (new WikidataCacheSchema())->ensureSchema($currentVersion);

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
        $coordinates = LocationCoordinates::fromGedcom($location->gedcom());
        // Domus deep-links are meaningful only for a known Wikidata item.
        // Do not show a generic Domus start-page button for places without a
        // Wikidata assignment.
        $domusUrl = $wikidataEnabled && $identifier !== null
            ? (new DomusMapLinkProvider())->url($identifier, $coordinates)
            : '';
        if ($identifier === null || !$wikidataEnabled) {
            $language = explode('-', str_replace('_', '-', I18N::languageTag()))[0] ?: 'en';
            $geoNamesHtml = $this->geoNamesHtml($location->fullName(), $language);
            $nominatimHtml = $this->nominatimHtml($place->getGedcomName() !== '' ? $place->getGedcomName() : $location->fullName(), $language, $location->gedcom());
            if ($externalIdentifiers === [] && !$location->canEdit() && $geoNamesHtml === '' && $nominatimHtml === '') {
                return null;
            }

            $assignmentUrl = $location->canEdit() ? self::assignmentUrl(['tree' => $location->tree()->name(), 'xref' => $location->xref()]) : null;
            $html = $this->externalInformationHtml($externalIdentifiers, $language, '', $location->fullName(), $assignmentUrl, $location->gedcom());
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
        $cache    = new WikidataCacheRepository();
        $entity   = $cache->find($identifier, $language);
        if ($entity === null) {
            $entity = (new WikidataClient())->fetch($identifier, $language);
            if ($entity !== null) {
                $cache->store($entity, $language);
            }
        }

        $label = $entity?->label ?? $identifier->qid();
        $html  = '<section class="mt-4">' . $this->providerHeading('wikidata', I18N::translate('Wikidata'));
        $html .= '<a href="' . e($identifier->entityUrl()) . '" rel="noopener noreferrer" target="_blank">' . e($label) . '</a> (' . e($identifier->qid()) . ')';
        if ($entity?->description !== null) {
            $html .= ' — ' . e($entity->description);
        }
        if ($entity !== null && $entity->instanceOfQids !== []) {
            $typeLabels = (new WikidataClient())->labels($entity->instanceOfQids, $language);
            $types      = array_map(static fn (string $qid): string => $typeLabels[$qid] ?? $qid, $entity->instanceOfQids);
            $html .= '<br>' . e(MoreI18N::xlate('Type')) . ': ' . e(implode(', ', $types));
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
            $html .= $this->personRelationsHtml(MoreI18N::xlate('Owner'), $entity->owners, $people);
            $html .= $this->personRelationsHtml(I18N::translate('Occupants'), $entity->occupants, $people);
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
                    $html .= $this->crossReferenceHtml($wikidataReference, $externalIdentifiers, $assignmentUrl);
        }
        $html .= $this->sourceHtml('Wikidata', 'https://www.wikidata.org/') . '</section>';
        $assignmentUrl = $location->canEdit() ? self::assignmentUrl(['tree' => $location->tree()->name(), 'xref' => $location->xref()]) : null;
        $html .= $this->externalInformationHtml($externalIdentifiers, $language, 'wikidata', $location->fullName(), $assignmentUrl, $location->gedcom(), $genwikiShown);
        $html .= $this->geoNamesHtml($location->fullName(), $language);
        $html .= $this->nominatimHtml($place->getGedcomName() !== '' ? $place->getGedcomName() : $location->fullName(), $language, $location->gedcom());
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
     * Render all non-Wikidata provider data and explicitly report cross-links.
     * External data remains read-only; adding a missing ID is a separate action.
     *
     * @param list<\Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier> $identifiers
     */
    private function externalInformationHtml(array $identifiers, string $language, string $skip = '', string $placeName = '', ?string $assignmentUrl = null, string $gedcom = '', array &$genwikiShown = []): string
    {
        if ($identifiers === []) {
            return '';
        }

        $registry = new ExternalProviderRegistry();
        $html = '';
        foreach ($registry->all() as $provider) {
            if ($provider->key() === $skip || !ExternalProviderSettings::isEnabled($provider->key())) {
                continue;
            }
            foreach ($identifiers as $identifier) {
                if ($identifier->provider !== $provider->key()) {
                    continue;
                }
                $information = $provider->fetch($identifier, $language);
                $displayLabel = trim(strip_tags($information?->label ?? $identifier->value));
                if ($provider->key() === 'gov' && ($displayLabel === $identifier->value || $displayLabel === '')) {
                    $displayLabel = $placeName !== '' ? trim(strip_tags($placeName)) : $identifier->value;
                }
                $showIdentifier = $displayLabel !== $identifier->value;
                $html .= '<section class="mt-4">' . $this->providerHeading($provider->key(), $provider->label())
                    . '<a href="' . e($identifier->url) . '" rel="noopener noreferrer" target="_blank">'
                    . e($displayLabel) . '</a>' . ($showIdentifier ? ' <small>(' . e($identifier->value) . ')</small>' : '');
                if ($information?->description !== null) {
                    $description = $provider->key() === 'gov'
                        ? I18N::translate($information->description)
                        : $information->description;
                    if ($provider->key() === 'geonames') {
                        $description = $this->externalDetailValue('Feature', $description);
                    }
                    $html .= ' — ' . e($description);
                }
                foreach ($information?->hierarchies ?? [] as $hierarchy) {
                    $parts = [];
                    foreach (array_reverse($hierarchy) as $node) {
                        $parts[] = '<a href="' . e($node['url']) . '" title="' . e($this->externalDetailLabel($node['label'])) . '" target="_blank" rel="noopener noreferrer">' . e($node['value']) . '</a>';
                    }
                    if ($parts !== []) {
                        $html .= '<br><small>' . e(I18N::translate('Hierarchy')) . ': ' . implode(', ', $parts) . '</small>';
                    }
                }
                foreach ($information?->details ?? [] as $detail) {
                    $value = $this->externalDetailValue($detail['label'], $detail['value']);
                    $detailLabel = $this->externalDetailLabel($detail['label']);
                    $alternateLanguage = null;
                    if (in_array($provider->key(), ['geonames', 'gov'], true) && str_starts_with($detail['label'], 'Alternate name (')) {
                        preg_match('/^Alternate name \(([a-z]{2,3}(?:[-_][a-z]{2,4})?)\)$/i', $detail['label'], $languageMatch);
                        $alternateLanguage = strtolower(explode('-', str_replace('_', '-', $languageMatch[1] ?? ''))[0]);
                        $sameLanguage = $this->locationNamesByLanguage($gedcom)[$alternateLanguage] ?? [];
                        if (in_array($value, $sameLanguage, true) && !self::showConsistentReferences()) {
                            continue;
                        }
                    }
                    $html .= '<br><small>' . e($detailLabel) . ': ' . ($detail['label'] === 'External identifier' ? $this->externalIdentifierHtml($value) : e($value)) . '</small>';
                    if (in_array($provider->key(), ['geonames', 'gov'], true) && str_starts_with($detail['label'], 'Alternate name (')) {
                        $locNames = $this->locationNamesByLanguage($gedcom);
                        $sameLanguage = $locNames[$alternateLanguage] ?? [];
                        if (in_array($value, $sameLanguage, true)) {
                            if (self::showConsistentReferences()) {
                                $html .= ' <span class="text-success">(' . e(I18N::translate('consistent with the shared place name')) . ')</span>';
                            }
                        } elseif ($sameLanguage !== []) {
                            $html .= ' <span class="text-danger">(' . e(I18N::translate('inconsistent with the shared place name')) . ')</span>';
                        } elseif ($assignmentUrl !== null) {
                            $html .= ' <form method="post" action="' . e($assignmentUrl) . '" class="d-inline">' . csrf_field() . '<input type="hidden" name="operation" value="add-location-name"><input type="hidden" name="name" value="' . e($value) . '"><input type="hidden" name="language" value="' . e($alternateLanguage) . '"><button class="btn btn-sm btn-outline-primary" type="submit">' . e(I18N::translate('Add place name')) . '</button></form>';
                        }
                    }
                }
                if (($information?->population ?? []) !== []) {
                    $html .= $this->populationHtml($information->population ?? []);
                }
                if ($information?->imageUrl !== null) {
                    $html .= '<br><img src="' . e($information->imageUrl) . '" alt="" loading="lazy" style="max-width:500px;max-height:500px;width:auto;height:auto">';
                }
                if ($information !== null) {
                    if ($provider->key() === 'gov' && $information->typeId !== null) {
                        $typeStatus = GovTypeValidator::compare($gedcom, $information->typeId);
                        if ($typeStatus['state'] === 'consistent' && self::showConsistentReferences()) {
                            $html .= '<br><small>' . e(I18N::translate('GOV place type is consistent with the shared place.')) . '</small>';
                        } elseif ($typeStatus['state'] === 'inconsistent') {
                            $html .= '<br><span class="text-danger"><strong>' . e(I18N::translate('GOV place type is inconsistent with the shared place.')) . '</strong></span>';
                        } elseif ($assignmentUrl !== null) {
                            $html .= '<br><span class="text-warning">' . e(I18N::translate('GOV place type is missing from the shared place.')) . '</span>';
                            $html .= ' <form method="post" action="' . e($assignmentUrl) . '" class="d-inline">' . csrf_field() . '<input type="hidden" name="operation" value="add-gov-type"><input type="hidden" name="gov_type" value="' . e($information->typeId) . '"><button class="btn btn-sm btn-outline-primary" type="submit">' . e(I18N::translate('Add GOV place type')) . '</button></form>';
                        }
                    }
                    $html .= $this->crossReferenceHtml($information, $identifiers, $assignmentUrl);
                    $html .= $this->externalPersonRelationsHtml($information);
                }
                $html .= $this->sourceHtml($provider->label(), $this->providerHomepage($provider->key())) . '</section>';
                if ($provider->key() === 'genwiki') {
                    $genwikiShown[] = $identifier->url;
                }
            }
        }
        return $html;
    }

    /** Render one canonical GenWiki page and optionally offer its ID for EXID. */
    private function genwikiReferenceHtml(string $url, array $identifiers, ?string $assignmentUrl, array &$shown): string
    {
        $pageId = null;
        if (preg_match('~[?&]curid=([1-9][0-9]{0,11})~i', $url, $match) === 1) {
            $pageId = $match[1];
        } elseif (preg_match('~^https?://(?:www\\.)?wiki\\.genealogy\\.net/[^?#]+$~i', $url) !== 1) {
            return '';
        }
        $canonical = $pageId === null ? $url : 'https://wiki.genealogy.net/?curid=' . $pageId;
        if (in_array($canonical, $shown, true)) {
            return '';
        }
        $shown[] = $canonical;
        $matching = false;
        foreach ($identifiers as $identifier) {
            if ($pageId !== null && $identifier->provider === 'genwiki' && $identifier->value === $pageId) {
                $matching = true;
                break;
            }
        }
        // An assigned GenWiki identifier is rendered by the GenWiki provider
        // block (including its title and introduction). Hide only the shorter
        // cross-reference line when consistent values are configured to be
        // suppressed; otherwise show it with its consistency marker.
        if ($matching && !self::showConsistentReferences()) {
            return '';
        }
        $displayValue = $pageId ?? $canonical;
        $html = '<br><small>' . e(I18N::translate('Reference to GenWiki')) . ': <a href="' . e($canonical) . '" rel="noopener noreferrer" target="_blank">' . e($displayValue) . '</a>';
        if ($matching) {
            $html .= ' <span class="text-success">(' . e(I18N::translate('consistent')) . ')</span>';
        } elseif ($pageId !== null && $assignmentUrl !== null) {
            $html .= ' <form method="post" action="' . e($assignmentUrl) . '" class="d-inline">' . csrf_field()
                . '<input type="hidden" name="operation" value="add-external-id">'
                . '<input type="hidden" name="provider" value="genwiki">'
                . '<input type="hidden" name="external_id" value="' . e($pageId) . '">'
                . '<button class="btn btn-sm btn-outline-primary py-0" type="submit">' . e(I18N::translate('Assign')) . '</button></form>';
        }
        return $html . '</small>';
    }

    private function geoNamesHtml(string $placeName, string $language): string
    {
        if (!ExternalProviderSettings::isEnabled('geonames')) { return ''; }
        $information = (new GeoNamesProvider())->lookup($placeName, $language);
        if ($information === null) { return ''; }
        $html = '<section class="mt-4">' . $this->providerHeading('geonames', I18N::translate('GeoNames')) . '<a href="' . e($information['url']) . '" rel="noopener noreferrer" target="_blank">' . e($information['label']) . '</a>';
        foreach ($information['details'] as $detail) {
            $html .= '<br><small>' . e($this->externalDetailLabel($detail['label'])) . ': ' . e($this->externalDetailValue($detail['label'], $detail['value'])) . '</small>';
        }
        return $html . $this->sourceHtml('GeoNames', 'https://www.geonames.org/') . '</section>';
    }

    private function nominatimHtml(string $placeName, string $language, string $gedcom = ''): string
    {
        if (!ExternalProviderSettings::isEnabled('nominatim')) {
            return '';
        }
        $provider = new NominatimProvider();
        $information = $provider->lookup($placeName, $language, $this->nominatimLayer($gedcom));
        if ($information === null) {
            return '<div class="alert alert-secondary small"><strong>Nominatim diagnostic:</strong> ' . e($provider->diagnostic() !== '' ? $provider->diagnostic() : 'no result') . '</div>';
        }
        $html = '<section class="mt-4">' . $this->providerHeading('nominatim', 'Nominatim') . '<a href="' . e($information['url']) . '" rel="noopener noreferrer" target="_blank">' . e($information['label']) . '</a>';
        if ($information['description'] !== null && $information['description'] !== '') {
            $html .= ' — ' . e($information['description']);
        }
        if ($provider->diagnostic() !== '') {
            $html .= '<div class="alert alert-info small mt-2"><strong>Nominatim diagnostic:</strong> ' . e($provider->diagnostic()) . '</div>';
        }
        if (is_array($information['geometry'] ?? null)) {
            $mapId = 'nominatim-map-' . substr(md5($information['url']), 0, 10);
            $geometry = json_encode($information['geometry'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $html .= '<div id="' . e($mapId) . '" style="height:320px;min-height:240px" class="mt-3 rounded border" role="img" aria-label="' . e(I18N::translate('Map showing the Nominatim geometry')) . '"></div>';
            // Dedicated module pages do not necessarily load webtrees map
            // assets. Load Leaflet only when a polygon is actually present.
        $html .= '<script>(function(){const el=document.getElementById(' . json_encode($mapId, JSON_THROW_ON_ERROR) . ');const geometry=' . $geometry . ';function draw(){if(!el||typeof L === "undefined"){return;}const map=L.map(el);L.tileLayer("https://{s}.tile.openstreetmap.de/{z}/{x}/{y}.png",{attribution:"&copy; OpenStreetMap contributors"}).addTo(map);const layer=L.geoJSON({type:"Feature",geometry:geometry},{style:{color:"#3388ff",weight:2,fillColor:"#3388ff",fillOpacity:0.35}}).addTo(map);map.fitBounds(layer.getBounds(),{padding:[12,12]});setTimeout(function(){map.invalidateSize();},100);}if(typeof L!=="undefined"){draw();return;}if(!document.querySelector("[data-hh-leaflet]")){const css=document.createElement("link");css.rel="stylesheet";css.href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css";document.head.appendChild(css);const script=document.createElement("script");script.src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js";script.async=true;script.dataset.hhLeaflet="1";script.onload=draw;document.head.appendChild(script);}else{const timer=setInterval(function(){if(typeof L!=="undefined"){clearInterval(timer);draw();}},50);}})();</script>';
        }
        return $html . $this->sourceHtml('Nominatim', 'https://nominatim.openstreetmap.org/') . '</section>';
    }

    private function nominatimLayer(string $gedcom): ?string
    {
        if (preg_match('/^1 TYPE .*?(Landkreis|county)/im', $gedcom)) { return 'county'; }
        if (preg_match('/^1 TYPE .*?(Bundesland|state|Land)/im', $gedcom)) { return 'state'; }
        if (preg_match('/^1 TYPE .*?(Stadt|city|town)/im', $gedcom)) { return 'city'; }
        if (preg_match('/^1 TYPE .*?(Dorf|village|Ortsteil)/im', $gedcom)) { return 'locality'; }
        if (preg_match('/^1 TYPE .*?(Haus|Hof|house|building)/im', $gedcom)) { return 'house'; }
        return null;
    }

    private function sourceHtml(string $name, string $url): string
    {
        return '<br><small>' . e(MoreI18N::xlate('Source')) . ': <a href="' . e($url) . '" rel="noopener noreferrer" target="_blank">' . e($name) . '</a></small>';
    }

    private function providerHeading(string $provider, string $label): string
    {
        $iconUrl = match ($provider) {
            'wikidata' => 'https://www.wikidata.org/static/favicon/wikidata.ico',
            'factgrid' => 'https://database.factgrid.de/favicon.ico',
            'gov' => 'https://gov.genealogy.net/favicon.ico',
            'geonames' => 'https://www.geonames.org/favicon.ico',
            'nominatim' => 'https://nominatim.openstreetmap.org/favicon.ico',
            default => null,
        };

        $icon = $iconUrl === null ? '' : '<img class="me-1" src="' . e($iconUrl) . '" width="20" height="20" alt="" aria-hidden="true" loading="lazy" referrerpolicy="no-referrer" style="vertical-align:-0.2em">';

        return '<h3 class="h4 mb-2" style="display:flex;flex-flow:row nowrap;align-items:center;justify-content:flex-start;text-align:left;gap:.4rem;white-space:nowrap">' . $icon . e($label) . '</h3>';
    }

    private function providerHomepage(string $provider): string
    {
        return match ($provider) {
            'factgrid' => 'https://database.factgrid.de/',
            'gov' => 'https://gov.genealogy.net/',
            'geonames' => 'https://www.geonames.org/',
            'genwiki' => 'https://wiki.genealogy.net/',
            'nominatim' => 'https://nominatim.openstreetmap.org/',
            default => 'https://www.wikidata.org/',
        };
    }

    private function externalDetailLabel(string $label): string
    {
        if (preg_match('/^Alternate name \(([a-z]{2,3}(?:[-_][a-z]{2,4})?)\)$/i', $label, $matches) === 1) {
            return I18N::translate('Alternate name') . ' (' . strtolower($matches[1]) . ')';
        }
        return match (strtolower($label)) {
            'country' => MoreI18N::xlate('Country'),
            'region' => MoreI18N::xlate('Region'),
            'street' => MoreI18N::xlate('Street'),
            'postal code' => MoreI18N::xlate('Postal code'),
            'place' => MoreI18N::xlate('Place'),
            'population' => I18N::translate('Population'),
            'external identifier' => I18N::translate('External identifier'),
            'administrative area' => I18N::translate('Administrative area'),
            'feature' => I18N::translate('Feature'),
            'elevation' => I18N::translate('Elevation'),
            'house number' => I18N::translate('House number'),
            'municipality' => I18N::translate('Municipality'),
            'county' => I18N::translate('County'),
            'state' => I18N::translate('State'),
            'type' => I18N::translate('Type'),
            'area' => I18N::translate('Area'),
            'continent' => I18N::translate('Continent'),
            'independent political entity' => I18N::translate('Independent political entity'),
            'first-order administrative division' => I18N::translate('First-order administrative division'),
            'second-order administrative division' => I18N::translate('Second-order administrative division'),
            'third-order administrative division' => I18N::translate('Third-order administrative division'),
            'fourth-order administrative division' => I18N::translate('Fourth-order administrative division'),
            'populated place' => I18N::translate('populated place'),
            default => $label,
        };
    }

    /** @return array<string,list<string>> */
    private function locationNamesByLanguage(string $gedcom): array
    {
        $names = [];
        $currentName = null;
        foreach (preg_split('/\r?\n/', $gedcom) ?: [] as $line) {
            if (preg_match('/^1 NAME(?: |$)(.*)$/', $line, $match) === 1 && trim($match[1]) !== '') {
                $currentName = trim($match[1]);
                $names[''] ??= [];
                $names[''][] = $currentName;
            } elseif ($currentName !== null && preg_match('/^2 LANG (.+)$/', $line, $match) === 1) {
                $language = strtolower(trim($match[1]));
                $language = GeoNamesLanguage::code($language);
                $names[$language][] = $currentName;
            }
        }
        foreach ($names as $language => $values) { $names[$language] = array_values(array_unique($values)); }
        return $names;
    }

    /** @param array<string,int|float> $population */
    private function populationHtml(array $population): string
    {
        if ($population === []) { return ''; }
        ksort($population, SORT_NUMERIC);
        $html = '<div class="d-flex flex-wrap gap-3 align-items-start mt-2"><div><strong>' . e(I18N::translate('Population')) . '</strong><table class="table table-sm mb-0"><thead><tr><th>' . e(MoreI18N::xlate('Year')) . '</th><th>' . e(I18N::translate('Population')) . '</th></tr></thead><tbody>';
        foreach ($population as $year => $value) {
            $html .= '<tr><td>' . e((string) $year) . '</td><td>' . e(I18N::number($value)) . '</td></tr>';
        }
        $html .= '</tbody></table></div>' . $this->populationChartHtml($population) . '</div>';
        return $html;
    }

    /** @param array<string,int|float> $population */
    private function populationChartHtml(array $population): string
    {
        if (count($population) < 3) {
            return '';
        }

        $points = array_keys($population);
        $values = array_values($population);
        $min = min($values); $max = max($values); $range = $max - $min ?: 1;
        $coordinates = [];
        $last = max(1, count($values) - 1);
        foreach ($values as $index => $value) {
            $x = 35 + (270 * $index / $last);
            $y = 145 - (115 * ((float) $value - $min) / $range);
            $coordinates[] = round($x, 2) . ',' . round($y, 2);
        }
        $yearLabel = MoreI18N::xlate('Year');
        $populationLabel = I18N::translate('Population');
        $svg = '<svg viewBox="0 0 320 190" width="320" height="190" role="img" aria-label="' . e($populationLabel) . '"><line x1="35" y1="145" x2="305" y2="145" stroke="currentColor" stroke-opacity=".35"/><line x1="35" y1="20" x2="35" y2="145" stroke="currentColor" stroke-opacity=".35"/><polyline fill="none" stroke="currentColor" stroke-width="2" points="' . e(implode(' ', $coordinates)) . '"/>';
        foreach ($values as $index => $value) {
            [$x, $y] = explode(',', $coordinates[$index]);
            $svg .= '<circle cx="' . e($x) . '" cy="' . e($y) . '" r="3" fill="currentColor"><title>' . e((string) $points[$index] . ': ' . I18N::number($value)) . '</title></circle>';
        }
        $svg .= '<text x="170" y="178" text-anchor="middle" font-size="11">' . e($yearLabel) . '</text>';
        $svg .= '<text x="11" y="83" text-anchor="middle" font-size="11" transform="rotate(-90 11 83)">' . e($populationLabel) . '</text>';
        return '<div>' . $svg . '</svg></div>';
    }

    private function externalDetailValue(string $label, string $value): string
    {
        if ($label === 'Feature') {
            // GeoNames may vary the capitalization of feature labels. Use a
            // canonical message id so translations (e.g. "populated place")
            // are found reliably.
            return match (strtolower(trim($value))) {
                'populated place' => I18N::translate('populated place'),
                default => I18N::translate($value),
            };
        }
        if ($label === 'Population' && is_numeric(trim($value))) {
            return I18N::number((float) $value);
        }

        if ($label !== 'Elevation' || trim($value) === '') {
            return $value;
        }

        return $value . ' ' . I18N::translate('m above sea level');
    }

    private function externalIdentifierHtml(string $value): string
    {
        $value = trim($value);
        $catalogEntry = GovExternalIdentifierCatalog::forValue($value);
        $url = GovExternalIdentifierCatalog::url($value);
        $display = $url === null ? e($value) : '<a href="' . e($url) . '" rel="noopener noreferrer" target="_blank">' . e($value) . '</a>';
        if ($catalogEntry !== null) {
            $display .= ' — ' . e(I18N::translate($catalogEntry['description']));
        }
        return $display;
    }

    private function externalPersonRelationsHtml(ExternalInformation $information): string
    {
        if ($information->owners === [] && $information->occupants === []) {
            return '';
        }

        $html = '';
        foreach ([MoreI18N::xlate('Owner') => $information->owners, I18N::translate('Occupants') => $information->occupants] as $heading => $relations) {
            if ($relations === []) { continue; }
            $html .= '<h5 class="mt-3">' . e($heading) . '</h5><div class="table-responsive"><table class="table table-sm"><thead><tr>'
                . '<th>' . e(MoreI18N::xlate('Name')) . '</th><th>' . e(MoreI18N::xlate('Birth')) . '</th><th>' . e(MoreI18N::xlate('Death')) . '</th><th>' . e(MoreI18N::xlate('From')) . '</th><th>' . e(I18N::translate('Until')) . '</th></tr></thead><tbody>';
            foreach ($relations as $relation) {
                $person = $information->people[$relation->id] ?? null;
                $label = $person?->label ?? $relation->id;
                $html .= '<tr><td><a href="' . e($person?->url ?? '#') . '" rel="noopener noreferrer" target="_blank">' . e($label) . '</a> <small>(' . e($relation->id) . ')</small>';
                foreach ($person?->externalLinks ?? [] as $linkLabel => $linkUrl) {
                    $html .= ' · <a href="' . e($linkUrl) . '" rel="noopener noreferrer" target="_blank">' . e($linkLabel) . '</a>';
                }
                $html .= '</td><td>' . $this->displayWikidataDate($person?->birthDate) . '</td><td>' . $this->displayWikidataDate($person?->deathDate) . '</td><td>' . $this->displayWikidataDate($relation->from) . '</td><td>' . $this->displayWikidataDate($relation->until) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        return $html;
    }

    /** @param list<\Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier> $identifiers */
    private function crossReferenceHtml(ExternalInformation $information, array $identifiers, ?string $assignmentUrl = null): string
    {
        $html = '';
        foreach ($information->references as $provider => $values) {
            foreach ($values as $value) {
                $displayValue = $value;
                $url = null;
                if ($provider === 'genwiki' && preg_match('~[?&]curid=([1-9][0-9]{0,11})~i', $value, $match) === 1) {
                    $displayValue = $match[1];
                    $url = 'https://wiki.genealogy.net/?curid=' . $displayValue;
                }
                $comparisonValue = $provider === 'genwiki' ? $displayValue : $value;
                $referenceIdentifier = (new ExternalProviderRegistry())->byKey($provider)?->identifier($comparisonValue);
                if ($referenceIdentifier !== null) { $url ??= $referenceIdentifier->url; }
                $matching = false;
                foreach ($identifiers as $identifier) {
                    if ($identifier->provider === $provider && $identifier->value === $comparisonValue) {
                        $matching = true;
                        break;
                    }
                }
                if ($matching && !self::showConsistentReferences()) {
                    continue;
                }
                $providerLabel = ['wikidata' => 'Wikidata', 'factgrid' => 'FactGrid', 'gov' => 'GOV', 'geonames' => 'GeoNames', 'genwiki' => 'GenWiki'][$provider] ?? $provider;
                $html .= '<br><span class="small">' . e(I18N::translate('Reference to %s', $providerLabel)) . ': '
                    . ($url === null ? e($displayValue) : '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($displayValue) . '</a>')
                    . ' — <span class="' . ($matching ? 'text-success' : 'text-warning') . '">' . e($matching ? I18N::translate('consistent') : I18N::translate('not present in this shared place')) . '</span>';
                $html .= '</span>';
                if (!$matching && $assignmentUrl !== null) {
                    $html .= ' <form method="post" action="' . e($assignmentUrl) . '" class="d-inline ms-1">'
                        . csrf_field()
                        . '<input type="hidden" name="operation" value="add-external-id">'
                        . '<input type="hidden" name="provider" value="' . e($provider) . '">'
                        . '<input type="hidden" name="external_id" value="' . e($value) . '">'
                        . '<button class="btn btn-sm btn-outline-primary py-0" type="submit">' . e(I18N::translate('Assign')) . '</button></form>';
                }
            }
        }
        return $html;
    }

    private static function showConsistentReferences(): bool
    {
        return Site::getPreference(self::SHOW_CONSISTENT_REFERENCES_PREFERENCE, '1') === '1';
    }

    /** @param list<object{qid:string,from:?string,until:?string}> $relations @param array<string,object{qid:string,label:?string,birthDate:?string,deathDate:?string}> $people */
    private function personRelationsHtml(string $heading, array $relations, array $people): string
    {
        if ($relations === []) {
            return '';
        }

        $html = '<h5 class="mt-3">' . e($heading) . '</h5><div class="table-responsive"><table class="table table-sm"><thead><tr>'
            . '<th>' . e(MoreI18N::xlate('Name')) . '</th>'
            . '<th>' . e(MoreI18N::xlate('Birth')) . '</th><th>' . e(MoreI18N::xlate('Death')) . '</th>'
            . '<th>' . e(MoreI18N::xlate('From')) . '</th><th>' . e(I18N::translate('Until')) . '</th></tr></thead><tbody>';
        foreach ($relations as $relation) {
            $person = $people[$relation->qid] ?? null;
            $label = $person?->label ?? $relation->qid;
            $personLinks = '';
            foreach ($person?->externalLinks ?? [] as $linkLabel => $linkUrl) {
                $personLinks .= ' · <a href="' . e($linkUrl) . '" rel="noopener noreferrer" target="_blank">' . e($linkLabel) . '</a>';
            }
            $html .= '<tr><td><a href="https://www.wikidata.org/entity/' . e($relation->qid) . '" rel="noopener noreferrer" target="_blank">' . e($label) . '</a> <small>(' . e($relation->qid) . ')</small>' . $personLinks . '</td>'
                . '<td>' . $this->displayWikidataDate($person?->birthDate) . '</td><td>' . $this->displayWikidataDate($person?->deathDate) . '</td>'
                . '<td>' . $this->displayWikidataDate($relation->from) . '</td><td>' . $this->displayWikidataDate($relation->until) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private function displayWikidataDate(?string $date): string
    {
        if ($date === null || preg_match('/^(\\d{4,})(?:-(\\d{2})(?:-(\\d{2}))?)?$/', $date, $parts) !== 1) {
            return '';
        }

        $gedcom = $parts[1];
        if (isset($parts[2])) {
            $months = ['01' => 'JAN', '02' => 'FEB', '03' => 'MAR', '04' => 'APR', '05' => 'MAY', '06' => 'JUN', '07' => 'JUL', '08' => 'AUG', '09' => 'SEP', '10' => 'OCT', '11' => 'NOV', '12' => 'DEC'];
            $gedcom = $months[$parts[2]] . ' ' . $parts[1];
            if (isset($parts[3])) {
                $gedcom = ltrim($parts[3], '0') . ' ' . $gedcom;
            }
        }

        return (new Date($gedcom))->display();
    }

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
                    $label = $provider === 'geonames' ? PlaceTypeFilterSettings::geonamesLabel($value) : PlaceTypeFilterSettings::govLabel($value);
                    $typeLabels[$level][$provider][$value] = I18N::translate($label);
                }
            }
        }

        return $this->viewResponse(self::MODULE_NAME . '::configuration', [
            'all_trees' => $trees,
            'default_radius_km' => NearbyDiscoverySettings::globalRadius(),
            'radius_exceptions' => $radiusExceptions,
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
        // Keep the legacy variable available for the success-message branch
        // below; a normal settings save has no reset action.
        $resetProvider = '';
        if ($reset !== '') {
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
            $levelLabel = [
                'planet' => 'Planet (Earth)',
                'federation' => 'Federation / international organisation',
                'country' => 'State / country',
                'house' => 'House / farm',
            ][$resetLevel] ?? $resetLevel;
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
        } elseif ($resetProvider === '') {
            FlashMessages::addMessage(I18N::translate($radiusChanged ? 'Nearby search settings have been updated.' : 'External Places settings have been updated.'), 'success');
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
