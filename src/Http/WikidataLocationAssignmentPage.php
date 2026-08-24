<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\ExternalIdService;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\ExternalPlacesModule;
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
        $language        = explode('-', str_replace('_', '-', I18N::languageTag()))[0] ?: 'en';
        $submittedSearch = trim(Validator::queryParams($request)->string('search', ''));
        $providerKey      = Validator::queryParams($request)->string('provider', 'wikidata');
        if (!in_array($providerKey, ['wikidata', 'factgrid', 'gov'], true)) {
            $providerKey = 'wikidata';
        }
        $nameFact        = $location->facts(['NAME'])->first();
        $locationName    = $nameFact === null ? $location->xref() : trim(strip_tags($nameFact->value()));
        $search          = $submittedSearch === '' ? $locationName : $submittedSearch;
        $client          = new WikidataClient();
        $govProvider      = (new ExternalProviderRegistry())->byKey('gov');
        $wikibaseClient   = new ReadOnlyWikibaseClient();
        $nearbyRequested = ($request->getQueryParams()['nearby'] ?? '') === '1';
        $coordinates     = LocationCoordinates::fromGedcom($location->gedcom());
        $radiusKm        = NearbyDiscoverySettings::radius($tree);
        $query           = [];
        parse_str($request->getUri()->getQuery(), $query);
        $routeParameter = $query['route'] ?? null;
        $searchUrl      = (string) $request->getUri()->withQuery('');

        $current = (new ExternalIdService())->wikidataIdentifiers($location->gedcom())->identifier();
        $entity  = $current === null ? null : $client->fetch($current, $language);

        return $this->viewResponse('hh_external_places::assignment', [
            'assignment_url' => ExternalPlacesModule::assignmentUrl(['tree' => $tree->name(), 'xref' => $location->xref()]),
            'provider_key'   => $providerKey,
            'candidates'     => $providerKey === 'wikidata' && $submittedSearch !== '' ? $client->search($submittedSearch, $language) : [],
            'external_candidates' => $providerKey === 'gov' && $submittedSearch !== '' && $govProvider !== null && method_exists($govProvider, 'search') ? $govProvider->search($submittedSearch, $language) : [],
            'factgrid_candidates' => $providerKey === 'factgrid' && $submittedSearch !== '' ? $wikibaseClient->search('factgrid', $submittedSearch, $language) : [],
            'factgrid_nearby_candidates' => $providerKey === 'factgrid' && $nearbyRequested && $coordinates !== null ? $wikibaseClient->nearby('factgrid', $coordinates['latitude'], $coordinates['longitude'], $radiusKm, $language) : [],
            'coordinates'    => $coordinates,
            'current'        => $current,
            'entity'         => $entity,
            'external_identifiers' => (new ExternalProviderRegistry())->parse($location->gedcom()),
            'location'       => $location,
            'location_name'  => $locationName,
            'has_search'     => $submittedSearch !== '',
            'nearby_candidates' => $providerKey === 'wikidata' && $nearbyRequested && $coordinates !== null ? $client->nearby($coordinates['latitude'], $coordinates['longitude'], $radiusKm, $language, $locationName) : [],
            'external_nearby_candidates' => $nearbyRequested && $providerKey === 'gov' && $coordinates !== null && $govProvider !== null && method_exists($govProvider, 'nearby') ? $govProvider->nearby($coordinates['latitude'], $coordinates['longitude'], $radiusKm) : [],
            'nearby_requested' => $nearbyRequested,
            'nearby_radius_km' => $radiusKm,
            'search'         => $search,
            'search_url'     => $searchUrl,
            'route_parameter' => is_string($routeParameter) ? $routeParameter : null,
            'title'          => I18N::translate('Assign Wikidata item'),
            'tree'           => $tree,
        ]);
    }
}
