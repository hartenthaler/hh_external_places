<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\ExternalIdService;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\ExternalPlacesModule;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderSettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\GeoNamesProvider;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\PlaceTypeFilterSettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\MoreI18N;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata\WikidataClient;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata\LocationCoordinates;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikidata\NearbyDiscoverySettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Wikibase\ReadOnlyWikibaseClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function parse_str;
/** Search and review Wikidata items before explicitly assigning one to a shared place. */
final class WikidataLocationAssignmentPage implements RequestHandlerInterface
{
    use ViewResponseTrait;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            return (new WikidataLocationAssignmentAction())->handle($request);
        }

        $tree     = Validator::attributes($request)->tree();
        $xref     = Validator::attributes($request)->isXref()->string('xref');
        $location = Auth::checkLocationAccess(Registry::locationFactory()->make($xref, $tree), true);
        $canEdit         = $location->canEdit();
        $language        = explode('-', str_replace('_', '-', I18N::languageTag()))[0] ?: 'en';
        $submittedSearch = trim(Validator::queryParams($request)->string('search', ''));
        $providerKey      = Validator::queryParams($request)->string('provider', 'wikidata');
        $enabledProviders = array_values(array_intersect(['wikidata', 'factgrid', 'gov', 'geonames'], ExternalProviderSettings::enabled()));
        if (!in_array($providerKey, $enabledProviders, true)) {
            $providerKey = $enabledProviders[0] ?? 'wikidata';
        }
        $providerEnabled = in_array($providerKey, $enabledProviders, true);
        $nameFact        = $location->facts(['NAME'])->first();
        $locationName    = $nameFact === null ? $location->xref() : trim(strip_tags($nameFact->value()));
        $search          = $submittedSearch === '' ? $locationName : $submittedSearch;
        $client          = new WikidataClient();
        $govProvider      = (new ExternalProviderRegistry())->byKey('gov');
        $geoNamesProvider = new GeoNamesProvider();
        $wikibaseClient   = new ReadOnlyWikibaseClient();
        $nearbyRequested = ($request->getQueryParams()['nearby'] ?? '') === '1';
        $requestedFilter = (string) ($request->getQueryParams()['filter'] ?? '');
        $filterLevel      = in_array($requestedFilter, PlaceTypeFilterSettings::LEVELS, true) ? $requestedFilter : null;
        $houseOnly       = $filterLevel !== null;
        if ($providerKey === 'geonames' && ($submittedSearch !== '' || $nearbyRequested)) {
            $geoNamesStatus = $geoNamesProvider->configurationStatus();
            if (!$geoNamesStatus['active']) {
                FlashMessages::addMessage(I18N::translate('GeoNames is not enabled in webtrees. Enable it in Control panel / Geographical data / Geolocation / GeoNames.'), 'warning');
            } elseif (!$geoNamesStatus['username']) {
                FlashMessages::addMessage(I18N::translate('No GeoNames username is configured. Enter it in Control panel / Geographical data / Geolocation / GeoNames.'), 'warning');
            }
        }
        $coordinates     = LocationCoordinates::fromGedcom($location->gedcom());
        $radiusKm        = NearbyDiscoverySettings::radius($tree);
        $query           = [];
        parse_str($request->getUri()->getQuery(), $query);
        $routeParameter = $query['route'] ?? null;
        $searchUrl      = (string) $request->getUri()->withQuery('');

        $factgridNearbyCandidates = [];
        if ($providerEnabled && $providerKey === 'factgrid' && $nearbyRequested && $coordinates !== null) {
            $factgridNearbyCandidates = $wikibaseClient->nearby('factgrid', $coordinates['latitude'], $coordinates['longitude'], $radiusKm, $language, $houseOnly, $filterLevel ?? 'house');
        }

        $current = (new ExternalIdService())->wikidataIdentifiers($location->gedcom())->identifier();
        $entity  = $current === null ? null : $client->fetch($current, $language);

        return $this->viewResponse('hh_external_places::assignment', [
            'assignment_url' => ExternalPlacesModule::assignmentUrl(['tree' => $tree->name(), 'xref' => $location->xref()]),
            'provider_key'   => $providerKey,
            'enabled_providers' => $enabledProviders,
            'candidates'     => $providerEnabled && $providerKey === 'wikidata' && $submittedSearch !== '' ? $client->search($submittedSearch, $language, $houseOnly, $filterLevel ?? 'house') : [],
            'external_candidates' => $providerEnabled && $providerKey === 'gov' && $submittedSearch !== '' && $govProvider !== null && method_exists($govProvider, 'search') ? array_values(array_filter($govProvider->search($submittedSearch, $language), static fn (array $candidate): bool => !$houseOnly || PlaceTypeFilterSettings::matches('gov', $candidate, $filterLevel ?? 'house'))) : [],
            'factgrid_candidates' => $providerEnabled && $providerKey === 'factgrid' && $submittedSearch !== '' ? $wikibaseClient->search('factgrid', $submittedSearch, $language, $houseOnly, $filterLevel ?? 'house') : [],
            'geonames_candidates' => $providerEnabled && $providerKey === 'geonames' && $submittedSearch !== '' ? $geoNamesProvider->search($submittedSearch, $language, $houseOnly, $filterLevel ?? 'house') : [],
            'factgrid_nearby_candidates' => $factgridNearbyCandidates,
            'coordinates'    => $coordinates,
            'current'        => $current,
            'entity'         => $entity,
            'external_identifiers' => (new ExternalProviderRegistry())->parse($location->gedcom()),
            'location'       => $location,
            'location_name'  => $locationName,
            'has_search'     => $submittedSearch !== '',
            'nearby_candidates' => $providerEnabled && $providerKey === 'wikidata' && $nearbyRequested && $coordinates !== null ? $client->nearby($coordinates['latitude'], $coordinates['longitude'], $radiusKm, $language, $locationName, $houseOnly, $filterLevel ?? 'house') : [],
            'external_nearby_candidates' => $providerEnabled && $nearbyRequested && $providerKey === 'gov' && $coordinates !== null && $govProvider !== null && method_exists($govProvider, 'nearby') ? array_values(array_filter($govProvider->nearby($coordinates['latitude'], $coordinates['longitude'], $radiusKm), static fn (array $candidate): bool => !$houseOnly || PlaceTypeFilterSettings::matches('gov', $candidate, $filterLevel ?? 'house'))) : [],
            'nearby_requested' => $nearbyRequested,
            'house_only'      => $houseOnly,
            'filter_level'    => $filterLevel,
            'can_edit'        => $canEdit,
            'nearby_radius_km' => $radiusKm,
            'search'         => $search,
            'search_url'     => $searchUrl,
            'route_parameter' => is_string($routeParameter) ? $routeParameter : null,
            'title'          => I18N::translate('Assign Wikidata item'),
            'tree'           => $tree,
        ]);
    }
}
