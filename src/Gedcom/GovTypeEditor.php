<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

/** Adds a GOV vocabulary type to the TYPE block for the requested period. */
final class GovTypeEditor
{
    public function add(string $gedcom, string $typeId, string $typeLabel = '', ?string $date = null): string
    {
        $typeId = trim($typeId);
        $typeLabel = trim($typeLabel);
        $date = $date === null ? null : trim($date);
        if ($typeLabel === '') {
            $typeLabel = 'GOV type ' . $typeId;
        }
        if (preg_match('/^\d+$/', $typeId) !== 1) {
            return $gedcom;
        }

        [$targetStart, $targetEnd] = $date === null ? [null, null] : GovTypeValidator::dateRange($date);
        foreach (GovTypeValidator::entries($gedcom) as $entry) {
            if ($entry['value'] === $typeId && GovTypeValidator::sameRange($entry['start'], $entry['end'], $targetStart, $targetEnd)) {
                return $gedcom;
            }
        }

        $lines = preg_split('/\R/u', rtrim($gedcom)) ?: [];
        foreach (GovTypeValidator::blocks($gedcom) as $block) {
            if (!GovTypeValidator::sameRange($block['start'], $block['end'], $targetStart, $targetEnd)) {
                continue;
            }
            $insert = $block['endLine'] + 1;
            array_splice($lines, $insert, 0, ['2 _GOVTYPE ' . $typeId]);
            return implode("\n", $lines) . "\n";
        }

        $newBlock = ['1 TYPE ' . $typeLabel];
        if ($date !== null && $date !== '') {
            $newBlock[] = '2 DATE ' . $date;
        }
        $newBlock[] = '2 _GOVTYPE ' . $typeId;
        return rtrim($gedcom) . "\n" . implode("\n", $newBlock) . "\n";
    }
}
