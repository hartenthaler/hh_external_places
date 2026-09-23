<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

/** Appends a structured external address below a shared-place record. */
final class AddressEditor
{
    /**
     * @param array{house_number?:string,street?:string,postal_code?:string,city?:string,from?:string,until?:string,provider?:string,external_id?:string,source_url?:string} $address
     */
    public function add(string $gedcom, array $address): string
    {
        $values = [];
        foreach (['house_number', 'street', 'postal_code', 'city', 'from', 'until', 'provider', 'external_id', 'source_url'] as $key) {
            $value = trim((string) ($address[$key] ?? ''));
            if ($value === '' || preg_match('/[\r\n]/', $value) === 1 || mb_strlen($value) > 240) {
                $value = '';
            }
            $values[$key] = $value;
        }

        if ($values['provider'] !== '' && !in_array($values['provider'], ['wikidata', 'factgrid'], true)) {
            return $gedcom;
        }
        if ($values['external_id'] !== '' && preg_match('/^Q[1-9][0-9]*$/', $values['external_id']) !== 1) {
            return $gedcom;
        }
        if ($values['source_url'] !== '' && filter_var($values['source_url'], FILTER_VALIDATE_URL) === false) {
            return $gedcom;
        }

        if ($values['house_number'] === '' && $values['street'] === '' && $values['postal_code'] === '' && $values['city'] === '') {
            return $gedcom;
        }

        foreach ($this->addresses($gedcom) as $existing) {
            if ($this->sameAddress($existing, $values)) {
                return $gedcom;
            }
        }

        // A shared-place record starts at level 0.  _ADDR is therefore a
        // direct child at level 1; level 2 belongs to the address itself.
        // Using level 2 here would attach the address to the preceding level-1
        // element (for example _EXID), producing _LOC:_EXID:_ADDR.
        $block = ['1 _ADDR'];
        if ($values['house_number'] !== '') { $block[] = '2 _HNO ' . $values['house_number']; }
        if ($values['street'] !== '') { $block[] = '2 ADR1 ' . $values['street']; }
        if ($values['postal_code'] !== '') { $block[] = '2 POST ' . $values['postal_code']; }
        if ($values['city'] !== '') { $block[] = '2 CITY ' . $values['city']; }
        $date = $this->dateValue($values['from'], $values['until']);
        if ($date !== '') { $block[] = '2 DATE ' . $date; }
        if ($values['provider'] !== '' && $values['external_id'] !== '') {
            $source = 'Imported from ' . $values['provider'] . ' ' . $values['external_id'];
            if ($values['source_url'] !== '') { $source .= ': ' . $values['source_url']; }
            $block[] = '2 NOTE ' . $source;
        }

        $lines = preg_split('/\R/u', rtrim($gedcom)) ?: [];
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

    /** @return list<array<string,string>> */
    private function addresses(string $gedcom): array
    {
        $lines = preg_split('/\R/u', $gedcom) ?: [];
        $result = [];
        for ($index = 0, $count = count($lines); $index < $count; ++$index) {
            if (preg_match('/^1 _ADDR(?:\s|$)/', $lines[$index]) !== 1) { continue; }
            $address = array_fill_keys(['house_number', 'street', 'postal_code', 'city', 'from', 'until'], '');
            for (++$index; $index < $count; ++$index) {
                $line = $lines[$index];
                if (preg_match('/^1 /', $line) === 1) { --$index; break; }
                if (preg_match('/^2 _HNO (.*)$/', $line, $match) === 1) { $address['house_number'] = trim($match[1]); }
                elseif (preg_match('/^2 ADR1 (.*)$/', $line, $match) === 1) { $address['street'] = trim($match[1]); }
                elseif (preg_match('/^2 POST (.*)$/', $line, $match) === 1) { $address['postal_code'] = trim($match[1]); }
                elseif (preg_match('/^2 CITY (.*)$/', $line, $match) === 1) { $address['city'] = trim($match[1]); }
                elseif (preg_match('/^2 DATE (.*)$/', $line, $match) === 1) {
                    if (preg_match('/^FROM (.+) TO (.+)$/i', trim($match[1]), $date) === 1) { $address['from'] = trim($date[1]); $address['until'] = trim($date[2]); }
                    elseif (preg_match('/^FROM (.+)$/i', trim($match[1]), $date) === 1) { $address['from'] = trim($date[1]); }
                    elseif (preg_match('/^TO (.+)$/i', trim($match[1]), $date) === 1) { $address['until'] = trim($date[1]); }
                }
            }
            $result[] = $address;
        }
        return $result;
    }

    /** @param array<string,string> $existing @param array<string,string> $candidate */
    private function sameAddress(array $existing, array $candidate): bool
    {
        foreach (['house_number', 'street', 'postal_code', 'city', 'from', 'until'] as $key) {
            if (mb_strtolower(trim($existing[$key] ?? '')) !== mb_strtolower(trim($candidate[$key] ?? ''))) { return false; }
        }
        return true;
    }

    private function dateValue(string $from, string $until): string
    {
        if ($from !== '' && $until !== '') { return 'FROM ' . $from . ' TO ' . $until; }
        if ($from !== '') { return 'FROM ' . $from; }
        if ($until !== '') { return 'TO ' . $until; }
        return '';
    }
}
