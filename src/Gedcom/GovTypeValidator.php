<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

/** Reads the human-readable and GOV type assertions from TYPE blocks. */
final class GovTypeValidator
{
    /** @return list<array{value:string,label:string,start:int|null,end:int|null,date:string|null,block:int,startLine:int,endLine:int}> */
    public static function entries(string $gedcom): array
    {
        $entries = [];
        foreach (self::blocks($gedcom) as $block) {
            foreach ($block['values'] as $value) {
                $entries[] = [
                    'value' => $value,
                    'label' => $block['label'],
                    'start' => $block['start'],
                    'end' => $block['end'],
                    'date' => $block['date'],
                    'block' => $block['block'],
                    'startLine' => $block['startLine'],
                    'endLine' => $block['endLine'],
                ];
            }
        }
        return $entries;
    }

    /** @return list<array{label:string,values:list<string>,start:int|null,end:int|null,date:string|null,block:int,startLine:int,endLine:int}> */
    public static function blocks(string $gedcom): array
    {
        $lines = preg_split('/\R/u', $gedcom) ?: [];
        $blocks = [];
        $current = null;
        $blockNumber = 0;

        $finish = static function (?array &$block, int $endLine) use (&$blocks): void {
            if ($block === null) {
                return;
            }
            $block['endLine'] = $endLine;
            $blocks[] = $block;
            $block = null;
        };

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/^1 TYPE(?:\s+(.*))?$/u', $line, $match) === 1) {
                $finish($current, $lineNumber - 1);
                $current = [
                    'label' => trim($match[1] ?? ''),
                    'values' => [],
                    'start' => null,
                    'end' => null,
                    'date' => null,
                    'block' => $blockNumber++,
                    'startLine' => $lineNumber,
                    'endLine' => $lineNumber,
                ];
                continue;
            }

            if (preg_match('/^1 /u', $line) === 1) {
                $finish($current, $lineNumber - 1);
                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^2 _GOVTYPE\s+(\d+)\s*$/u', $line, $match) === 1) {
                $current['values'][] = $match[1];
            } elseif (preg_match('/^2 DATE\s+(.+)$/u', $line, $match) === 1) {
                $current['date'] = trim($match[1]);
                [$current['start'], $current['end']] = self::dateRange($current['date']);
            }
        }
        $finish($current, count($lines) - 1);

        return $blocks;
    }

    /** @return list<string> */
    public static function values(string $gedcom): array
    {
        return array_values(array_unique(array_map(static fn (array $entry): string => $entry['value'], self::entries($gedcom))));
    }

    /** @param string|list<string>|null $externalTypeId */
    public static function compare(string $gedcom, string|array|null $externalTypeId): array
    {
        $entries = self::entries($gedcom);
        $values = self::values($gedcom);
        $externalValues = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), is_array($externalTypeId) ? $externalTypeId : [$externalTypeId]),
            static fn (string $value): bool => $value !== '' && preg_match('/^\d+$/', $value) === 1,
        )));
        $matched = array_values(array_intersect($externalValues, $values));

        if ($entries === []) {
            return ['state' => 'missing', 'values' => [], 'entries' => [], 'matched' => []];
        }

        $overlap = false;
        foreach ($entries as $index => $entry) {
            foreach (array_slice($entries, $index + 1) as $other) {
                // Repeating the same GOV type is harmless. Only different
                // types occupying the same interval are contradictory.
                if ($entry['value'] === $other['value']) {
                    continue;
                }
                if (self::overlaps($entry['start'], $entry['end'], $other['start'], $other['end'])) {
                    $overlap = true;
                    break 2;
                }
            }
        }

        if ($overlap) {
            return ['state' => 'inconsistent', 'values' => $values, 'entries' => $entries, 'matched' => $matched];
        }
        if ($externalValues === []) {
            return ['state' => 'missing', 'values' => $values, 'entries' => $entries, 'matched' => []];
        }
        if ($matched === []) {
            return ['state' => 'inconsistent', 'values' => $values, 'entries' => $entries, 'matched' => []];
        }

        return ['state' => 'consistent', 'values' => $values, 'entries' => $entries, 'matched' => $matched];
    }

    /** @return array{0:int|null,1:int|null} */
    public static function dateRange(string $date): array
    {
        $date = strtoupper(trim($date));
        if ($date === '') {
            return [null, null];
        }

        if (preg_match('/^(?:FROM|BET)\s+(.+?)\s+(?:TO|AND)\s+(.+)$/u', $date, $match) === 1) {
            return [self::dateStart($match[1]), self::dateEnd($match[2])];
        }
        if (preg_match('/^(?:TO|BEF|BEFORE|UNTIL|BIS)\s+(.+)$/u', $date, $match) === 1) {
            return [null, self::dateStart($match[1])];
        }
        if (preg_match('/^(?:FROM|AFTER|AFT|AB)\s+(.+)$/u', $date, $match) === 1) {
            return [self::dateStart($match[1]), null];
        }

        // ABT/EST/CAL values are retained as an approximate period.
        $date = preg_replace('/^(?:ABT|EST|CAL)\s+/u', '', $date) ?? $date;
        return [self::dateStart($date), self::dateEnd($date)];
    }

    public static function sameRange(?int $startA, ?int $endA, ?int $startB, ?int $endB): bool
    {
        return $startA === $startB && $endA === $endB;
    }

    private static function overlaps(?int $startA, ?int $endA, ?int $startB, ?int $endB): bool
    {
        // Intervals are half-open. This makes “bis 1972” and “ab 1972” adjacent rather than overlapping.
        if ($endA !== null && $startB !== null && $endA <= $startB) {
            return false;
        }
        if ($endB !== null && $startA !== null && $endB <= $startA) {
            return false;
        }
        return true;
    }

    private static function dateStart(string $date): ?int
    {
        $parts = self::dateParts($date);
        return $parts === null ? null : $parts[0] * 10000 + $parts[1] * 100 + $parts[2];
    }

    private static function dateEnd(string $date): ?int
    {
        $parts = self::dateParts($date);
        if ($parts === null) {
            return null;
        }
        [$year, $month, $day, $precision] = $parts;
        if ($precision === 'day') {
            $next = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->modify('+1 day');
        } elseif ($precision === 'month') {
            $next = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('+1 month');
        } else {
            $next = new \DateTimeImmutable(sprintf('%04d-01-01', $year + 1));
        }
        return ((int) $next->format('Y')) * 10000 + ((int) $next->format('m')) * 100 + (int) $next->format('d');
    }

    /** @return array{0:int,1:int,2:int,3:string}|null */
    private static function dateParts(string $date): ?array
    {
        $date = trim($date);
        if (preg_match('/^(\d{1,2})\s+([A-Z]{3})\s+(\d{4})$/u', $date, $match) === 1) {
            $month = self::monthNumber($match[2]);
            return $month === null ? null : [(int) $match[3], $month, (int) $match[1], 'day'];
        }
        if (preg_match('/^([A-Z]{3})\s+(\d{4})$/u', $date, $match) === 1) {
            $month = self::monthNumber($match[1]);
            return $month === null ? null : [(int) $match[2], $month, 1, 'month'];
        }
        if (preg_match('/^(\d{4})$/u', $date, $match) === 1) {
            return [(int) $match[1], 1, 1, 'year'];
        }
        return null;
    }

    private static function monthNumber(string $month): ?int
    {
        $months = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
        return $months[$month] ?? null;
    }
}
