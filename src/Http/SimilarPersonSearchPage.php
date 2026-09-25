<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\RequestHandlers\MergeRecordsPage;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\ExternalPlacesModule;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\SimilarPersonFinder;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\MoreI18N;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function route;
use function strip_tags;

/** Show duplicate candidates for a newly imported individual. */
final class SimilarPersonSearchPage implements RequestHandlerInterface
{
    use ViewResponseTrait;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $xref = Validator::attributes($request)->isXref()->string('xref');
        $personXref = Validator::attributes($request)->isXref()->string('person');
        $location = Auth::checkLocationAccess(Registry::locationFactory()->make($xref, $tree), true);

        if (!$location->canEdit()) {
            FlashMessages::addMessage(I18N::translate('You are not authorized to search for similar people.'), 'warning');

            return redirect($location->url());
        }

        $person = Registry::individualFactory()->make($personXref, $tree);
        if (!$person instanceof Individual || !$person->canShowName()) {
            FlashMessages::addMessage(I18N::translate('The individual for the similarity search could not be found.'), 'danger');

            return redirect($location->url());
        }

        $candidates = (new SimilarPersonFinder())->find($tree, $person);
        foreach ($candidates as &$candidate) {
            $candidate['merge_url'] = null;
            if (Auth::isAdmin()) {
                try {
                    $candidate['merge_url'] = route(MergeRecordsPage::class, [
                        'tree'  => $tree->name(),
                        'xref1' => $person->xref(),
                        'xref2' => $candidate['individual']->xref(),
                    ]);
                } catch (\Throwable) {
                    // Older webtrees versions may not expose the core route.
                }
            }
        }
        unset($candidate);

        return $this->viewResponse('hh_external_places::similar-people', [
            'tree'          => $tree,
            'location'      => $location,
            'person'        => $person,
            'candidates'    => $candidates,
            'information_url' => ExternalPlacesModule::externalInformationUrl([
                'tree' => $tree->name(),
                'xref' => $location->xref(),
            ]),
            'person_name'   => trim(strip_tags($person->fullName())),
        ]);
    }
}
