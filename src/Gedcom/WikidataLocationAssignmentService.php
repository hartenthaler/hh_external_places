<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Location;
use Fisharebest\Webtrees\Registry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\WikidataIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalPerson;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\WikipediaLink;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\PlaceTypeFilterSettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\LanguageCode;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;

/**
 * Applies an explicit Wikidata assignment to a shared-place record.
 *
 * This is deliberately the only write boundary for the module.  A future
 * request handler can use the boolean result to return an appropriate 403
 * response or flash message, while this service never changes a record the
 * current webtrees user may not edit.
 */
final class WikidataLocationAssignmentService
{
    public function __construct(private readonly WikidataExternalIdEditor $editor = new WikidataExternalIdEditor(), private readonly ExternalIdEditor $externalEditor = new ExternalIdEditor(), private readonly GovTypeEditor $govTypeEditor = new GovTypeEditor(), private readonly CoordinateEditor $coordinateEditor = new CoordinateEditor(), private readonly AddressEditor $addressEditor = new AddressEditor(), private readonly PersonAssociationEditor $personEditor = new PersonAssociationEditor())
    {
    }

    /**
     * Replace the typed Wikidata external identifier on a shared place.
     * Other external identifiers are preserved verbatim.
     */
    public function assign(Location $location, WikidataIdentifier $identifier): bool
    {
        if (!$location->canEdit()) {
            return false;
        }

        $location->updateRecord($this->withUpdatedChange($this->editor->replace($location->gedcom(), $identifier)), false);

        return true;
    }

    /** Remove only typed Wikidata external identifiers from a shared place. */
    public function remove(Location $location): bool
    {
        if (!$location->canEdit()) {
            return false;
        }

        $location->updateRecord($this->withUpdatedChange($this->editor->remove($location->gedcom())), false);

        return true;
    }

    public function addExternalIdentifier(Location $location, ExternalIdentifier $identifier): bool
    {
        if (!$location->canEdit()) {
            return false;
        }

        $updated = $this->externalEditor->add($location->gedcom(), $identifier);
        if ($updated === rtrim($location->gedcom()) . "\n") {
            return false;
        }
        $location->updateRecord($this->withUpdatedChange($updated), false);

        return true;
    }

    public function removeExternalIdentifier(Location $location, ExternalIdentifier $identifier): bool
    {
        if (!$location->canEdit()) { return false; }
        $updated = $this->externalEditor->remove($location->gedcom(), $identifier);
        if ($updated === rtrim($location->gedcom()) . "\n") { return false; }
        $location->updateRecord($this->withUpdatedChange($updated), false);
        return true;
    }

    public function addGovType(Location $location, string $typeId, ?string $date = null): bool
    {
        if (!$location->canEdit() || preg_match('/^\d+$/', $typeId) !== 1) { return false; }
        $updated = $this->govTypeEditor->add($location->gedcom(), $typeId, PlaceTypeFilterSettings::govLabel($typeId), $date);
        if ($updated === $location->gedcom()) { return false; }
        $location->updateRecord($this->withUpdatedChange($updated), false);
        return true;
    }

    public function addCoordinates(Location $location, Coordinates $coordinates): bool
    {
        if (!$location->canEdit() || preg_match('/(?:^|\n)1 MAP\b|(?:^|\n)[1-9] LATI\s|(?:^|\n)[1-9] LONG\s/', $location->gedcom()) === 1) { return false; }
        $updated = $this->coordinateEditor->add($location->gedcom(), $coordinates);
        if ($updated === $location->gedcom()) { return false; }
        $location->updateRecord($this->withUpdatedChange($updated), false);
        return true;
    }

    /** Add one explicitly accepted external address below the shared place. */
    public function addAddress(Location $location, array $address): bool
    {
        if (!$location->canEdit()) { return false; }
        $updated = $this->addressEditor->add($location->gedcom(), $address);
        if ($updated === $location->gedcom()) { return false; }
        $location->updateRecord($this->withUpdatedChange($updated), false);
        return true;
    }

    /** Add one explicitly accepted population observation below the shared place. */
    public function addPopulation(Location $location, string $period, int|float $value, string $provider, string $externalId, string $sourceUrl): bool
    {
        if (!$location->canEdit()) {
            return false;
        }
        $updated = (new PopulationEditor())->add($location->gedcom(), $period, $value, $provider, $externalId, $sourceUrl);
        if ($updated === $location->gedcom()) {
            return false;
        }
        $location->updateRecord($this->withUpdatedChange($updated), false);

        return true;
    }

    /** Add one explicitly accepted provider image as an external media object. */
    public function addImage(Location $location, string $url, string $provider, string $externalId, ?string $title = null): bool
    {
        if (!$location->canEdit()) {
            return false;
        }

        $editor = new MediaEditor();
        $mediaXref = $editor->add($location, $url, $provider, $externalId, $title);
        if ($mediaXref === null) {
            return false;
        }
        $updated = rtrim($location->gedcom()) . "\n1 OBJE @" . $mediaXref . "@\n";
        $location->updateRecord($this->withUpdatedChange($updated), false);

        return true;
    }

    /**
     * Create one external person and link it to the shared place.
     *
     * @return 'added'|'already-associated'|'invalid-provider'|'not-authorized'|'link-failed'
     */
    public function addPerson(Location $location, ExternalPerson $person, string $relationship, ?string $from = null, ?string $until = null): string
    {
        if (!$location->canEdit()) {
            return 'not-authorized';
        }

        $provider = (new ExternalProviderRegistry())->byKey($person->provider);
        $identifier = $provider?->identifier($person->id);
        if ($identifier === null) {
            return 'invalid-provider';
        }
        if (in_array($person->provider . ':' . $person->id, $this->personEditor->associatedExternalKeys($location), true)) {
            return 'already-associated';
        }

        [$name, $given, $surname] = $this->personName($person->label ?? $person->id, $person->id);
        $gedcom = "0 @@ INDI\n1 NAME " . $name;
        if ($given !== '') {
            $gedcom .= "\n2 GIVN " . $given;
        }
        if ($surname !== '') {
            $gedcom .= "\n2 SURN " . $surname;
        }
        $gedcom .= "\n1 SEX " . ($person->sex ?? 'U');
        if (($birth = $this->personDate($person->birthDate)) !== null) {
            $gedcom .= "\n1 BIRT\n2 DATE " . $birth;
        }
        if (($death = $this->personDate($person->deathDate)) !== null) {
            $gedcom .= "\n1 DEAT\n2 DATE " . $death;
        }
        $place = trim(preg_replace('/[\r\n]+/u', ' ', strip_tags($location->fullName())) ?? '');
        $place = mb_substr($place, 0, 240);
        $eventTag = $relationship === 'Owner' ? 'PROP' : 'RESI';
        if ($place !== '') {
            $gedcom .= "\n1 " . $eventTag . "\n2 PLAC " . $place;
            $gedcom .= "\n3 _LOC @" . $location->xref() . "@";
            if (($period = $this->periodDate($from, $until)) !== null) {
                $gedcom .= "\n2 DATE " . $period;
            }
        }
        $gedcom .= "\n";
        foreach ($this->personIdentifiers($person, $identifier) as $personIdentifier) {
            $gedcom = $this->externalEditor->add($gedcom, $personIdentifier);
        }
        $gedcom .= '1 NOTE Imported from ' . $person->provider . ' ' . $person->id;
        if ($person->url !== '') {
            $gedcom .= ': ' . $person->url;
        }
        $gedcom .= "\n";

        $individual = $location->tree()->createIndividual($gedcom);
        $updated = $this->personEditor->add($location, $individual->xref(), $person, $relationship);
        if ($updated === $location->gedcom()) {
            return 'link-failed';
        }
        $location->updateRecord($this->withUpdatedChange($updated), false);

        return 'added';
    }

    /** Add a missing shared-place NAME line, preserving all existing names. */
    public function addLocationName(Location $location, string $name, string $language = ''): bool
    {
        if (!$location->canEdit() || trim($name) === '' || mb_strlen($name) > 240 || preg_match('/[\r\n]/', $name) === 1) { return false; }
        $name = trim($name);
        $requestedLanguage = LanguageCode::normalize($language);
        $currentName = null;
        $currentLanguage = '';
        foreach (preg_split('/\r?\n/', $location->gedcom()) ?: [] as $line) {
            if (preg_match('/^1 NAME(?: |$)(.*)$/', $line, $match) === 1) {
                $currentName = trim($match[1]);
                $currentLanguage = '';
            } elseif ($currentName !== null && preg_match('/^2 LANG (.+)$/', $line, $match) === 1) {
                $currentLanguage = LanguageCode::normalize($match[1]);
            }
            if ($currentName === $name && $currentLanguage === $requestedLanguage) { return false; }
        }
        $updated = rtrim($location->gedcom()) . "\n1 NAME " . $name;
        $gedcomLanguage = LanguageCode::gedcom($language);
        if ($gedcomLanguage !== null) {
            $updated .= "\n2 LANG " . $gedcomLanguage;
        }
        $updated .= "\n";
        $location->updateRecord($this->withUpdatedChange($updated), false);
        return true;
    }

    /**
     * webtrees does not add CHAN data to _LOC records.  Vesta shared places
     * already use this conventional block, so update it with the same logic
     * that webtrees applies to its record types with native CHAN support.
     */
    private function withUpdatedChange(string $gedcom): string
    {
        if (preg_match('/\n1 CHAN(?:\n[2-9].*)*/', $gedcom, $match) === 1) {
            return strtr($gedcom, [$match[0] => $this->updatedChangeBlock($match[0])]);
        }

        return rtrim($gedcom) . $this->updatedChangeBlock("\n1 CHAN") . "\n";
    }

    private function updatedChangeBlock(string $gedcom): string
    {
        $gedcom = preg_replace('/\n2 (DATE|_WT_USER).*(\n[3-9].*)*/', '', $gedcom) ?? $gedcom;

        return $gedcom
            . "\n2 DATE " . strtoupper(date('d M Y'))
            . "\n3 TIME " . date('H:i:s')
            . "\n2 _WT_USER " . Auth::user()->userName();
    }

    private function personDate(?string $date): ?string
    {
        if ($date === null || preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/', trim($date), $match) !== 1) {
            return null;
        }
        $months = ['', 'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
        if (!isset($match[2]) || $match[2] === '') {
            return $match[1];
        }
        $month = (int) $match[2];
        if ($month < 1 || $month > 12) {
            return null;
        }
        if (!isset($match[3]) || $match[3] === '') {
            return $months[$month] . ' ' . $match[1];
        }
        return ltrim($match[3], '0') . ' ' . $months[$month] . ' ' . $match[1];
    }

    /** @return array{0:string,1:string,2:string} */
    private function personName(string $label, string $fallback): array
    {
        $label = trim(preg_replace('/[\r\n]+/u', ' ', strip_tags($label)) ?? '');
        if ($label === '' || mb_strlen($label) > 240) {
            $label = $fallback;
        }
        $label = trim($label);
        $given = '';
        $surname = '';
        if (preg_match('~^(.*?)\s*/([^/]+)/\s*$~u', $label, $match) === 1) {
            $given = trim($match[1]);
            $surname = trim($match[2]);
        } elseif (str_contains($label, ',')) {
            [$surname, $given] = array_pad(array_map('trim', explode(',', $label, 2)), 2, '');
        } else {
            $parts = preg_split('/\s+/u', $label) ?: [];
            $surname = (string) array_pop($parts);
            $given = trim(implode(' ', $parts));
        }
        $name = $given !== '' && $surname !== '' ? $given . ' /' . $surname . '/' : '/' . ($surname !== '' ? $surname : $label) . '/';
        $name = Registry::elementFactory()->make('INDI:NAME')->canonical($name);
        return [$name, $given, $surname];
    }

    private function periodDate(?string $from, ?string $until): ?string
    {
        $start = $this->personDate($from);
        $end = $this->personDate($until);
        if ($start !== null && $end !== null) {
            return 'FROM ' . $start . ' TO ' . $end;
        }
        if ($start !== null) {
            return 'FROM ' . $start;
        }
        if ($end !== null) {
            return 'TO ' . $end;
        }
        return null;
    }

    /** @return list<ExternalIdentifier> */
    private function personIdentifiers(ExternalPerson $person, ExternalIdentifier $primary): array
    {
        $identifiers = [$primary];
        $seen = [$primary->authorityUri . ':' . $primary->value => true];
        foreach ($person->externalLinks as $url) {
            if (!is_string($url)) {
                continue;
            }
            $definitions = [
                '~^https?://(?:www\\.)?wikidata\\.org/(?:entity|wiki)/([Qq][1-9][0-9]*)/?$~i' => ['wikidata', 'https://www.wikidata.org/entity/', 'https://www.wikidata.org/entity/'],
                '~^https?://database\\.factgrid\\.de/entity/(Q[1-9][0-9]*)/?$~i' => ['factgrid', 'https://database.factgrid.de/entity/', 'https://database.factgrid.de/entity/'],
                '~^https?://wiki\\.genealogy\\.net/(?:\\?[^#]*?curid=|w/index\\.php\\?[^#]*?curid=)([1-9][0-9]{0,11})$~i' => ['genwiki', 'https://wiki.genealogy.net/?curid=', 'https://wiki.genealogy.net/?curid='],
                '~^https?://(?:www\\.)?wikitree\\.com/wiki/([^/?#]+)$~i' => ['wikitree', 'https://www.wikitree.com/wiki/', 'https://www.wikitree.com/wiki/'],
            ];
            if (($wikipedia = WikipediaLink::parse($url)) !== null) {
                $value = $wikipedia['title'];
                $authority = $wikipedia['authority'];
                $key = $authority . ':' . $value;
                if (!isset($seen[$key])) {
                    $identifiers[] = new ExternalIdentifier('wikipedia', $value, $authority, $wikipedia['url']);
                    $seen[$key] = true;
                }
                continue;
            }
            foreach ($definitions as $pattern => [$provider, $authority, $baseUrl]) {
                if (preg_match($pattern, $url, $match) !== 1) {
                    continue;
                }
                $value = $match[1];
                if ($provider === 'wikidata') {
                    $value = strtoupper($value[0]) . substr($value, 1);
                } elseif ($provider === 'wikitree') {
                    $value = rawurldecode($value);
                }
                $key = $authority . ':' . $value;
                if (!isset($seen[$key])) {
                    $identifiers[] = new ExternalIdentifier($provider, $value, $authority, $baseUrl . rawurlencode($value));
                    $seen[$key] = true;
                }
                break;
            }
        }
        return $identifiers;
    }
}
