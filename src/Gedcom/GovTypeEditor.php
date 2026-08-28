<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

/** Adds a GOV vocabulary type to the first TYPE block without changing other data. */
final class GovTypeEditor
{
    public function add(string $gedcom, string $typeId): string
    {
        $typeId = trim($typeId);
        if (preg_match('/^\d+$/', $typeId) !== 1 || preg_match('/^2 _GOVTYPE /m', $gedcom) === 1) {
            return $gedcom;
        }

        $lines = preg_split('/\R/u', rtrim($gedcom)) ?: [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^1 TYPE /', $line) !== 1) {
                continue;
            }
            $insert = $index + 1;
            while (isset($lines[$insert]) && preg_match('/^1 /', $lines[$insert]) !== 1) {
                ++$insert;
            }
            array_splice($lines, $insert, 0, ['2 _GOVTYPE ' . $typeId]);
            return implode("\n", $lines) . "\n";
        }

        return rtrim($gedcom) . "\n1 TYPE place\n2 _GOVTYPE " . $typeId . "\n";
    }
}
