<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Http\Exceptions\HttpBadRequestException;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\WikidataIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\WikidataLocationAssignmentService;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\MoreI18N;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function redirect;

/** Handles a CSRF-protected Wikidata assignment submitted for one shared place. */
final class WikidataLocationAssignmentAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $xref = Validator::attributes($request)->isXref()->string('xref');

        // This both checks the tree-bound record and acquires webtrees' edit lock.
        $location = Auth::checkLocationAccess(Registry::locationFactory()->make($xref, $tree), true);
        if (!$location->canEdit()) {
            FlashMessages::addMessage(I18N::translate('You are not authorized to modify this shared place.'), 'danger');
            return redirect($location->url());
        }
        $operation = Validator::parsedBody($request)->string('operation', 'assign');
        $service   = new WikidataLocationAssignmentService();

        if ($operation === 'remove') {
            $service->remove($location);
            FlashMessages::addMessage(I18N::translate('The Wikidata item has been removed.'), 'success');
        } elseif ($operation === 'assign') {
            $qid        = Validator::parsedBody($request)->string('qid', '');
            $identifier = WikidataIdentifier::tryFrom($qid);

            if ($identifier === null) {
                throw new HttpBadRequestException(I18N::translate('Invalid Wikidata identifier.'));
            }

            $service->assign($location, $identifier);
            FlashMessages::addMessage(I18N::translate('The Wikidata item has been assigned.'), 'success');
        } elseif ($operation === 'add-external-id') {
            $provider = (new ExternalProviderRegistry())->byKey(Validator::parsedBody($request)->string('provider', ''));
            $identifier = $provider?->identifier(Validator::parsedBody($request)->string('external_id', ''));
            if ($identifier === null) {
                throw new HttpBadRequestException(I18N::translate('Invalid external identifier.'));
            }
            $added = $service->addExternalIdentifier($location, $identifier);
            FlashMessages::addMessage(
                $added ? I18N::translate('The external identifier has been added.') : I18N::translate('The external identifier was not added because this provider already has an identifier.'),
                $added ? 'success' : 'danger',
            );
        } elseif ($operation === 'remove-external-id') {
            $provider = (new ExternalProviderRegistry())->byKey(Validator::parsedBody($request)->string('provider', ''));
            $identifier = $provider?->identifier(Validator::parsedBody($request)->string('external_id', ''));
            if ($identifier === null) { throw new HttpBadRequestException(I18N::translate('Invalid external identifier.')); }
            $removed = $service->removeExternalIdentifier($location, $identifier);
            FlashMessages::addMessage($removed ? I18N::translate('The external identifier has been removed.') : I18N::translate('The external identifier was not found.'), $removed ? 'success' : 'danger');
        } else {
            throw new HttpBadRequestException(I18N::translate('Invalid Wikidata assignment operation.'));
        }

        return redirect($location->url());
    }
}
