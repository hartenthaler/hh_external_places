<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

/** Adds idempotent demographic population data to a shared-place record. */
final class PopulationEditor
{
    public function add(string $gedcom, string $period, int|float $value, string $provider, string $externalId, string $sourceUrl): string
    {
        $period = trim($period);
        $date = $this->gedcomDate($period);
        $number = $this->number($value);
        if ($number === null) {
            return $gedcom;
        }

        if ($this->contains($gedcom, $period, $number)) {
            return $gedcom;
        }

        $source = $provider . ' ' . $externalId;
        if ($sourceUrl !== '') {
            $source .= ': ' . $sourceUrl;
        }
        $source = trim(preg_replace('/[\r\n]+/u', ' ', $source) ?? '');
        // GEDCOM-L defines demographic data directly below _LOC as _DMGD.
        // It is not an event (_LOC:EVEN); TYPE and DATE qualify the value.
        $lines = [
            '1 _DMGD ' . $number,
            '2 TYPE POPULATION',
        ];
        if ($date !== null) {
            $lines[] = '2 DATE ' . $date;
        }
        $result = rtrim($gedcom) . "\n" . implode("\n", $lines) . "\n";
        if ($source !== '') {
            // _DMGD has no NOTE substructure in GEDCOM-L. Keep provenance as
            // a valid record-level note instead of inventing a child tag.
            $result .= '1 NOTE Population imported from ' . $source . "\n";
        }

        return $result;
    }

    public function contains(string $gedcom, string $period, int|float|string $value): bool
    {
        $number = $this->number($value);
        if ($number === null) {
            return false;
        }
        $date = $this->gedcomDate(trim($period));
        preg_match_all('/^1 _DMGD(?: ([^\r\n]*))?(.*?)(?=^1 |\z)/ms', $gedcom, $blocks, PREG_SET_ORDER);
        foreach ($blocks as $block) {
            if (trim((string) ($block[1] ?? '')) !== $number) {
                continue;
            }
            if (preg_match('/^2 TYPE POPULATION$/mi', (string) ($block[2] ?? '')) !== 1) {
                continue;
            }
            $existingDate = null;
            if (preg_match('/^2 DATE (.+)$/mi', (string) ($block[2] ?? ''), $match) === 1) {
                $existingDate = trim($match[1]);
            }
            if ($existingDate === $date) {
                return true;
            }
        }

        return false;
    }

    private function gedcomDate(string $period): ?string
    {
        if ($period === '') {
            return null;
        }
        if (preg_match('/^(ab|from)\s+(.+)$/iu', $period, $match) === 1) {
            return 'FROM ' . strtoupper(trim($match[2]));
        }
        if (preg_match('/^(bis|until|to)\s+(.+)$/iu', $period, $match) === 1) {
            return 'TO ' . strtoupper(trim($match[2]));
        }

        return strtoupper($period);
    }

    private function number(int|float|string $value): ?string
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            return null;
        }

        return (string) ($value == (int) $value ? (int) $value : $value);
    }
}
