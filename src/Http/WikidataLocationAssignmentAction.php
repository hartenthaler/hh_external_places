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
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalPerson;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\WikidataLocationAssignmentService;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;
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
        } elseif ($operation === 'add-gov-type') {
            $typeId = Validator::parsedBody($request)->string('gov_type', '');
            $date = Validator::parsedBody($request)->string('gov_date', '');
            $added = $service->addGovType($location, $typeId, $date !== '' ? $date : null);
            FlashMessages::addMessage($added ? I18N::translate('The GOV place type has been added.') : I18N::translate('The GOV place type was not added.'), $added ? 'success' : 'danger');
        } elseif ($operation === 'add-location-name') {
            $name = Validator::parsedBody($request)->string('name', '');
            $language = Validator::parsedBody($request)->string('language', '');
            $added = $service->addLocationName($location, $name, $language);
            FlashMessages::addMessage($added ? I18N::translate('The place name has been added.') : I18N::translate('The place name was not added.'), $added ? 'success' : 'danger');
        } elseif ($operation === 'add-coordinates') {
            $latitude = Validator::parsedBody($request)->string('latitude', '');
            $longitude = Validator::parsedBody($request)->string('longitude', '');
            $coordinates = Coordinates::fromStrings($latitude, $longitude);
            if ($coordinates === null) { throw new HttpBadRequestException(I18N::translate('Invalid coordinates.')); }
            $added = $service->addCoordinates($location, $coordinates);
            FlashMessages::addMessage($added ? I18N::translate('The coordinates have been added to the shared place.') : I18N::translate('The coordinates were not added because coordinates already exist.'), $added ? 'success' : 'danger');
        } elseif ($operation === 'add-address') {
            $address = [];
            foreach (['house_number', 'street', 'postal_code', 'city', 'from', 'until', 'provider', 'external_id', 'source_url'] as $field) {
                $address[$field] = Validator::parsedBody($request)->string('address_' . $field, '');
            }
            $added = $service->addAddress($location, $address);
            FlashMessages::addMessage($added ? I18N::translate('The address has been added to the shared place.') : I18N::translate('The address was not added because it already exists or is empty.'), $added ? 'success' : 'danger');
        } elseif ($operation === 'add-person') {
            $providerKey = Validator::parsedBody($request)->string('person_provider', '');
            $provider = (new ExternalProviderRegistry())->byKey($providerKey);
            $externalId = Validator::parsedBody($request)->string('person_id', '');
            $identifier = $provider?->identifier($externalId);
            $label = trim(Validator::parsedBody($request)->string('person_label', ''));
            $birthDate = trim(Validator::parsedBody($request)->string('person_birth', ''));
            $deathDate = trim(Validator::parsedBody($request)->string('person_death', ''));
            $sex = strtoupper(trim(Validator::parsedBody($request)->string('person_sex', '')));
            $wikidataId = strtoupper(trim(Validator::parsedBody($request)->string('person_wikidata', '')));
            $factgridId = strtoupper(trim(Validator::parsedBody($request)->string('person_factgrid', '')));
            $wikiTreeId = trim(Validator::parsedBody($request)->string('person_wikitree', ''));
            $relationship = Validator::parsedBody($request)->string('person_relationship', '');
            $from = trim(Validator::parsedBody($request)->string('person_from', ''));
            $until = trim(Validator::parsedBody($request)->string('person_until', ''));
            if ($identifier === null || !in_array($providerKey, ['wikidata', 'factgrid'], true)) {
                throw new HttpBadRequestException(I18N::translate('The external provider identifier is invalid.'));
            }
            if ($label === '' || mb_strlen($label) > 240 || preg_match('/[\r\n]/', $label) === 1) {
                throw new HttpBadRequestException(I18N::translate('The external person has no usable name.'));
            }
            if (!in_array($relationship, ['Owner', 'Occupant'], true)) {
                throw new HttpBadRequestException(I18N::translate('The external-person relationship is invalid.'));
            }
            if ($sex !== '' && !in_array($sex, ['M', 'F', 'U', 'X'], true)) {
                throw new HttpBadRequestException(I18N::translate('The external person has an invalid sex value.'));
            }
            $externalLinks = [];
            if ($wikidataId !== '' && preg_match('/^Q[1-9][0-9]*$/', $wikidataId) === 1) {
                $externalLinks['Wikidata'] = 'https://www.wikidata.org/entity/' . $wikidataId;
            }
            if ($factgridId !== '' && preg_match('/^Q[1-9][0-9]*$/', $factgridId) === 1) {
                $externalLinks['Factgrid'] = 'https://database.factgrid.de/entity/' . $factgridId;
            }
            if ($wikiTreeId !== '' && preg_match('/^[\p{L}][\p{L}\p{M}0-9._-]{0,119}$/u', $wikiTreeId) === 1) {
                $externalLinks['WikiTree'] = 'https://www.wikitree.com/wiki/' . rawurlencode($wikiTreeId);
            }
            $person = new ExternalPerson($providerKey, $externalId, $identifier->url, $label, $birthDate !== '' ? $birthDate : null, $deathDate !== '' ? $deathDate : null, $externalLinks, $sex !== '' ? $sex : null);
            $result = $service->addPerson($location, $person, $relationship, $from !== '' ? $from : null, $until !== '' ? $until : null);
            $message = match ($result) {
                'added' => I18N::translate('The external person has been added to the shared place.'),
                'already-associated' => I18N::translate('This external person is already linked to the shared place.'),
                'invalid-provider' => I18N::translate('The external person was not added because the provider identifier is invalid.'),
                'not-authorized' => I18N::translate('You are not authorized to add a person to this shared place.'),
                default => I18N::translate('The individual was created, but it could not be linked to the shared place.'),
            };
            FlashMessages::addMessage($message, $result === 'added' ? 'success' : 'danger');
        } else {
            throw new HttpBadRequestException(I18N::translate('Invalid Wikidata assignment operation.'));
        }

        return redirect($location->url());
    }
}
