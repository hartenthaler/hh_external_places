<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

/** Reads GOV type values from the TYPE blocks of a shared-place record. */
final class GovTypeValidator
{
    /** @return list<array{value:string,start:int|null,end:int|null}> */
    public static function entries(string $gedcom): array
    {
        $lines = preg_split('/\R/u', $gedcom) ?: [];
        $entries = [];
        $typeBlock = null;
        foreach ($lines as $line) {
            if (preg_match('/^1 TYPE /', $line) === 1) {
                $typeBlock = ['value' => null, 'start' => null, 'end' => null];
                continue;
            }
            if (preg_match('/^1 /', $line) === 1) {
                if ($typeBlock !== null && $typeBlock['value'] !== null) { $entries[] = $typeBlock; }
                $typeBlock = null;
                continue;
            }
            if ($typeBlock === null) { continue; }
            if (preg_match('/^2 _GOVTYPE (\d+)$/', $line, $match) === 1) {
                $typeBlock['value'] = $match[1];
            } elseif (preg_match('/^2 DATE (.+)$/', $line, $match) === 1) {
                [$typeBlock['start'], $typeBlock['end']] = self::dateRange($match[1]);
            }
        }
        if ($typeBlock !== null && $typeBlock['value'] !== null) { $entries[] = $typeBlock; }
        return $entries;
    }

    /** @return list<string> */
    public static function values(string $gedcom): array
    {
        return array_map(static fn (array $entry): string => $entry['value'], self::entries($gedcom));
    }

    /** @return array{state:string,values:list<string>} */
    public static function compare(string $gedcom, ?string $externalTypeId): array
    {
        $entries = self::entries($gedcom);
        $values = array_map(static fn (array $entry): string => $entry['value'], $entries);
        if ($entries === []) {
            return ['state' => 'missing', 'values' => []];
        }
        $overlap = false;
        foreach ($entries as $index => $entry) {
            foreach (array_slice($entries, $index + 1) as $other) {
                $startsBeforeEnd = $entry['end'] === null || $other['start'] === null || $entry['end'] >= $other['start'];
                $endsAfterStart = $other['end'] === null || $entry['start'] === null || $other['end'] >= $entry['start'];
                if ($startsBeforeEnd && $endsAfterStart) { $overlap = true; break 2; }
            }
        }
        if ($overlap || $externalTypeId === null || in_array($externalTypeId, $values, true) === false) {
            return ['state' => 'inconsistent', 'values' => $values];
        }
        return ['state' => 'consistent', 'values' => $values];
    }

    /** @return array{0:int|null,1:int|null} */
    private static function dateRange(string $date): array
    {
        preg_match_all('/\b(\d{4})\b/', strtoupper($date), $matches);
        $years = array_map('intval', $matches[1] ?? []);
        if ($years === []) { return [null, null]; }
        if (str_contains(strtoupper($date), 'FROM') || str_contains(strtoupper($date), 'BET')) {
            return [$years[0], $years[1] ?? $years[0]];
        }
        return [$years[0], $years[0]];
    }
}
