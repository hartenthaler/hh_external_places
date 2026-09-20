<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier;

/** Appends one validated external identifier without changing other EXID blocks. */
final class ExternalIdEditor
{
    public function remove(string $gedcom, ExternalIdentifier $identifier): string
    {
        $lines = preg_split('/\R/', rtrim($gedcom)) ?: [];
        $out = [];
        $skip = false;
        foreach ($lines as $line) {
            if ($identifier->provider === 'gov' && preg_match('/^1 _GOV ' . preg_quote($identifier->value, '/') . '$/', $line) === 1) { $skip = true; continue; }
            if (preg_match('/^1 (?:_EXID|EXID) ' . preg_quote($identifier->value, '/') . '$/', $line) === 1) { $skip = true; continue; }
            if ($skip && preg_match('/^2 TYPE ' . preg_quote($identifier->authorityUri, '/') . '$/', $line) === 1) { $skip = false; continue; }
            $skip = false;
            $out[] = $line;
        }
        return rtrim(implode("\n", $out)) . "\n";
    }
    public function add(string $gedcom, ExternalIdentifier $identifier): string
    {
        if ($identifier->provider === 'gov') {
            foreach (preg_split('/\R/u', $gedcom) ?: [] as $line) {
                if (preg_match('/^1 _GOV (.+)$/', $line, $match) === 1 || (preg_match('/^1 (?:_EXID|EXID) (.+)$/', $line, $match) === 1 && $match[1] === $identifier->value)) {
                    return rtrim($gedcom) . "\n";
                }
            }
            return rtrim($gedcom) . "\n1 _GOV " . $identifier->value . "\n";
        }

        $lines = preg_split('/\R/u', rtrim($gedcom)) ?: [];
        $authority = preg_quote($identifier->authorityUri, '/');
        for ($index = 0, $count = count($lines); $index < $count; ++$index) {
            if (preg_match('/^1 (?:_EXID|EXID) (.+)$/', $lines[$index], $match) !== 1) { continue; }
            $block = [$lines[$index]];
            for (++$index; $index < $count && preg_match('/^1 /', $lines[$index]) !== 1; ++$index) { $block[] = $lines[$index]; }
            --$index;
            if ($match[1] === $identifier->value && preg_grep('/^2 TYPE ' . $authority . '$/', $block) !== []) {
                return rtrim($gedcom) . "\n";
            }
        }

        return rtrim($gedcom) . "\n1 " . $this->newIdentifierTag() . " " . $identifier->value . "\n2 TYPE " . $identifier->authorityUri . "\n";
    }

    private function newIdentifierTag(): string
    {
        $servicesClass = 'Hartenthaler\\Webtrees\\Module\\ExidModule\\ExidServices';
        if (class_exists($servicesClass) && method_exists($servicesClass, 'preferredTag')) {
            try {
                $tag = $servicesClass::preferredTag();
                if (in_array($tag, ['EXID', '_EXID'], true)) {
                    return $tag;
                }
            } catch (\Throwable) {
                // Keep the legacy fallback when the optional module is unavailable.
            }
        }

        return '_EXID';
    }
}
