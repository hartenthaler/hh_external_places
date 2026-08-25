<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule;

use Fisharebest\Localization\Translation;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Registry;
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
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalInformation;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderSettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\GeoNamesProvider;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domus\DomusMapLinkProvider;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\WikidataLocationAssignmentPage;
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

    public function boot(): void
    {
        $currentVersion = (int) $this->getPreference(self::CACHE_SCHEMA_VERSION_PREFERENCE, '0');
        $targetVersion  = (new WikidataCacheSchema())->ensureSchema($currentVersion);

        if ($targetVersion !== $currentVersion) {
            $this->setPreference(self::CACHE_SCHEMA_VERSION_PREFERENCE, (string) $targetVersion);
        }

        View::registerNamespace(self::MODULE_NAME, $this->resourcesFolder() . 'views/');
        $router = Registry::routeFactory()->routeMap();
        if (method_exists($router, 'add')) {
            // webtrees 2.3 identifies routes by their request-handler class.
            $router->add(self::ASSIGNMENT_ROUTE_PATH, WikidataLocationAssignmentPage::class);
        } else {
            // webtrees 2.2 uses an explicit route name and HTTP verb map.
            $router->get(
                self::ASSIGNMENT_ROUTE_NAME,
                self::ASSIGNMENT_ROUTE_PATH,
                WikidataLocationAssignmentPage::class,
            )->allows(['GET', 'POST']);
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

    public function plac2html(PlaceStructure $place): ?GenericViewElement
    {
        $location = $place->getLocation();
        if ($location === null) {
            return null;
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
        $domusUrl = $wikidataEnabled ? (new DomusMapLinkProvider())->url($identifier, $coordinates) : '';
        if ($identifier === null || !$wikidataEnabled) {
            $language = explode('-', str_replace('_', '-', I18N::languageTag()))[0] ?: 'en';
            $geoNamesHtml = $this->geoNamesHtml($location->fullName(), $language);
            if ($externalIdentifiers === [] && !$location->canEdit() && $geoNamesHtml === '') {
                return null;
            }

            $html = $this->externalInformationHtml($externalIdentifiers, $language, '', $location->fullName());
            $html .= $geoNamesHtml;
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
        $html  = '<br><br><strong>' . e(I18N::translate('Wikidata')) . ':</strong> ';
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
            $html .= '<br><a href="' . e($fileUrl) . '" rel="noopener noreferrer" target="_blank">' . e(I18N::translate('Image on Wikimedia Commons')) . '</a>';
        }
        if ($entity === null) {
            $html .= ' — <small>' . e(I18N::translate('Wikidata details are currently unavailable.')) . '</small>';
        }
        $wikidataProvider = (new ExternalProviderRegistry())->byAuthority('https://www.wikidata.org/entity/');
        $wikidataReference = $wikidataProvider?->fetch(
            new \Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier('wikidata', $identifier->qid(), 'https://www.wikidata.org/entity/', $identifier->entityUrl()),
            $language,
        );
        if ($wikidataReference !== null) {
            $html .= $this->crossReferenceHtml($wikidataReference, $externalIdentifiers);
        }
        $html .= '<br><small>' . e(MoreI18N::xlate('Source')) . ': Wikidata</small>';
        $html .= $this->externalInformationHtml($externalIdentifiers, $language, 'wikidata', $location->fullName());
        $html .= $this->geoNamesHtml($location->fullName(), $language);
        $html .= '<div class="d-flex gap-2 flex-wrap mt-2">';
        if ($domusUrl !== '') {
            $html .= '<a class="btn btn-primary btn-sm" href="' . e($domusUrl) . '" rel="noopener noreferrer" target="_blank">' . e(I18N::translate('Show in Domus')) . '</a>';
        }

        if ($location->canEdit()) {
            $html .= '<a class="btn btn-primary btn-sm" href="' . e(self::assignmentUrl(['tree' => $location->tree()->name(), 'xref' => $location->xref()])) . '">' . e(I18N::translate('Assign external identifier')) . '</a>';
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
    private function externalInformationHtml(array $identifiers, string $language, string $skip = '', string $placeName = ''): string
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
                $html .= '<section class="mt-3"><strong>' . e($provider->label()) . ':</strong> '
                    . '<a href="' . e($identifier->url) . '" rel="noopener noreferrer" target="_blank">'
                    . e($displayLabel) . '</a>' . ($showIdentifier ? ' <small>(' . e($identifier->value) . ')</small>' : '');
                if ($information?->description !== null) {
                    $html .= ' — ' . e($information->description);
                }
                foreach ($information?->details ?? [] as $detail) {
                    $html .= '<br><small>' . e($this->externalDetailLabel($detail['label'])) . ': ' . e($this->externalDetailValue($detail['label'], $detail['value'])) . '</small>';
                }
                if ($information?->imageUrl !== null) {
                    $html .= '<br><img src="' . e($information->imageUrl) . '" alt="" loading="lazy" style="max-width:500px;max-height:500px;width:auto;height:auto">';
                }
                if ($information !== null) {
                    foreach ($information->references['genwiki'] ?? [] as $genwikiUrl) {
                        $html .= '<br><small>' . e(I18N::translate('Article in GenWiki')) . ': <a href="' . e($genwikiUrl) . '" rel="noopener noreferrer" target="_blank">' . e($genwikiUrl) . '</a></small>';
                    }
                    $html .= $this->crossReferenceHtml($information, $identifiers);
                    $html .= $this->externalPersonRelationsHtml($information);
                }
                $html .= '<br><small>' . e(MoreI18N::xlate('Source')) . ': ' . e($provider->label()) . '</small></section>';
            }
        }
        return $html;
    }

    private function geoNamesHtml(string $placeName, string $language): string
    {
        if (!ExternalProviderSettings::isEnabled('geonames')) { return ''; }
        $information = (new GeoNamesProvider())->lookup($placeName, $language);
        if ($information === null) { return ''; }
        $html = '<section class="mt-3"><strong>' . e(I18N::translate('GeoNames')) . ':</strong> <a href="' . e($information['url']) . '" rel="noopener noreferrer" target="_blank">' . e($information['label']) . '</a>';
        foreach ($information['details'] as $detail) {
            $html .= '<br><small>' . e($this->externalDetailLabel($detail['label'])) . ': ' . e($this->externalDetailValue($detail['label'], $detail['value'])) . '</small>';
        }
        return $html . '<br><small>' . e(MoreI18N::xlate('Source')) . ': GeoNames</small></section>';
    }

    private function externalDetailLabel(string $label): string
    {
        return match ($label) {
            'Country', 'Region', 'Population' => MoreI18N::xlate($label),
            'Administrative area' => I18N::translate('Administrative area'),
            'Feature' => I18N::translate('Feature'),
            'Elevation' => I18N::translate('Elevation'),
            default => $label,
        };
    }

    private function externalDetailValue(string $label, string $value): string
    {
        if ($label !== 'Elevation' || trim($value) === '') {
            return $value;
        }

        return $value . ' ' . I18N::translate('m above sea level');
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
    private function crossReferenceHtml(ExternalInformation $information, array $identifiers): string
    {
        $html = '';
        foreach ($information->references as $provider => $values) {
            if ($provider === 'genwiki') { continue; }
            foreach ($values as $value) {
                $matching = false;
                foreach ($identifiers as $identifier) {
                    if ($identifier->provider === $provider && $identifier->value === $value) {
                        $matching = true;
                        break;
                    }
                }
                $providerLabel = ['wikidata' => 'Wikidata', 'factgrid' => 'FactGrid', 'gov' => 'GOV', 'geonames' => 'GeoNames'][$provider] ?? $provider;
                $html .= '<br><small>' . e(I18N::translate('Reference to %s', $providerLabel)) . ': '
                    . e($value) . ' — ' . e($matching ? I18N::translate('consistent') : I18N::translate('not present in this shared place')) . '</small>';
            }
        }
        return $html;
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

        return $this->viewResponse(self::MODULE_NAME . '::configuration', [
            'all_trees' => $trees,
            'default_radius_km' => NearbyDiscoverySettings::globalRadius(),
            'radius_exceptions' => $radiusExceptions,
            'enabled_providers' => ExternalProviderSettings::enabled(),
            'provider_labels' => ExternalProviderSettings::labels(),
            'title' => $this->title(),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $providers = is_array($body['providers'] ?? null) ? array_map('strval', $body['providers']) : [];
        ExternalProviderSettings::save($providers);
        $globalInput = str_replace(',', '.', trim((string) ($body['global-radius-km'] ?? '')));
        $globalRadius = is_numeric($globalInput) ? (float) $globalInput : NearbyDiscoverySettings::DEFAULT_RADIUS_KM;
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

        if ($exceptionTree !== '' && $parsedExceptionValue !== null) {
            $trees = Registry::container()->get(TreeService::class)->all();
            $tree = $trees->first(static fn (Tree $candidate): bool => (string) $candidate->id() === $exceptionTree);
            $treeTitle = $tree instanceof Tree ? $tree->title() : $exceptionTree;
            $message = $exceptionRemoved
                ? I18N::translate('The exception for family tree %s was removed. The default radius of %s km applies.', $treeTitle, number_format($globalRadius, 1))
                : I18N::translate('The nearby-search radius for family tree %s is now %s km.', $treeTitle, number_format($parsedExceptionValue, 1));
            FlashMessages::addMessage($message, 'success');
        } else {
            FlashMessages::addMessage(I18N::translate('Nearby search settings have been updated.'), 'success');
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
