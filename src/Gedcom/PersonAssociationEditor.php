<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

use Fisharebest\Webtrees\Location;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Registry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalPerson;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;

/** Reads and writes the provider-person links below a shared place. */
final class PersonAssociationEditor
{
    /**
     * Return provider/id keys for people already associated with a location.
     *
     * @return list<string>
     */
    public function associatedExternalKeys(Location $location): array
    {
        $keys = [];
        foreach ($this->associatedXrefs($location->gedcom()) as $xref) {
            try {
                $individual = Registry::individualFactory()->make($xref, $location->tree());
            } catch (\Throwable) {
                continue;
            }
            if ($individual === null) {
                continue;
            }
            foreach ((new ExternalProviderRegistry())->parse($individual->gedcom()) as $identifier) {
                $keys[] = $identifier->provider . ':' . $identifier->value;
            }
        }

        return array_values(array_unique($keys));
    }

    /** Append one provider-person association to a shared place. */
    public function add(Location $location, string $xref, ExternalPerson $person, string $relationship): string
    {
        if (preg_match('/^' . Gedcom::REGEX_XREF . '$/', $xref) !== 1) {
            return $location->gedcom();
        }

        $provider = preg_replace('/[^a-z0-9_-]/i', '', $person->provider) ?? '';
        $externalId = preg_replace('/[^A-Za-z0-9_.-]/', '', $person->id) ?? '';
        if ($provider === '' || $externalId === '') {
            return $location->gedcom();
        }

        foreach ($this->associatedXrefs($location->gedcom()) as $existing) {
            if ($existing === $xref) {
                return $location->gedcom();
            }
        }

        $relation = trim(preg_replace('/[\r\n]+/', ' ', $relationship) ?? '');
        $source = 'Imported from ' . $provider . ' ' . $externalId;
        if ($person->url !== '') {
            $source .= ': ' . $person->url;
        }
        $block = ['1 _ASSO @' . $xref . '@'];
        if ($relation !== '') {
            $block[] = '2 RELA ' . mb_substr($relation, 0, 120);
        }
        $block[] = '2 NOTE ' . mb_substr($source, 0, 240);

        $lines = preg_split('/\R/u', rtrim($location->gedcom())) ?: [];
        $insertAt = count($lines);
        foreach ($lines as $index => $line) {
            if (preg_match('/^1 CHAN(?:\s|$)/', $line) === 1) {
                $insertAt = $index;
                break;
            }
        }
        array_splice($lines, $insertAt, 0, $block);

        return implode("\n", $lines) . "\n";
    }

    /** @return list<string> */
    private function associatedXrefs(string $gedcom): array
    {
        $xrefs = [];
        foreach (preg_split('/\R/u', $gedcom) ?: [] as $line) {
            if (preg_match('/^1 _ASSO @([^@]+)@$/', trim($line), $match) === 1) {
                $xrefs[] = $match[1];
            }
        }

        return array_values(array_unique($xrefs));
    }
}
