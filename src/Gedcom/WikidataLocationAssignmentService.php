<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Location;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\WikidataIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier;
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
    public function __construct(private readonly WikidataExternalIdEditor $editor = new WikidataExternalIdEditor(), private readonly ExternalIdEditor $externalEditor = new ExternalIdEditor(), private readonly GovTypeEditor $govTypeEditor = new GovTypeEditor(), private readonly CoordinateEditor $coordinateEditor = new CoordinateEditor(), private readonly AddressEditor $addressEditor = new AddressEditor())
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
}
