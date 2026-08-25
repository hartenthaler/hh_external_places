<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier;

final class ExternalProviderRegistry
{
    /** @var list<string> */
    private array $errors = [];

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<ExternalProvider> */
    public function all(): array
    {
        return [
            new WikibaseProvider('wikidata', ['authority' => 'https://www.wikidata.org/entity/', 'label' => 'Wikidata', 'type' => 'P31', 'image' => 'P18', 'factgrid' => 'P8168', 'wikidata' => null, 'gov' => 'P2503', 'geonames' => 'P1566', 'wikitree' => 'P2949', 'owner' => 'P127', 'occupant' => 'P466', 'begin' => 'P580', 'end' => 'P582']),
            new WikibaseProvider('factgrid', ['authority' => 'https://database.factgrid.de/entity/', 'label' => 'FactGrid', 'type' => 'P2', 'image' => 'P189', 'factgrid' => null, 'wikidata' => 'P771', 'gov' => 'P1073', 'geonames' => 'P418', 'wikitree' => 'P2949', 'owner' => 'P126', 'occupant' => 'P239', 'begin' => 'P49', 'end' => 'P50']),
            new GovProvider(),
            new GeoNamesProvider(),
        ];
    }

    public function byAuthority(string $authority): ?ExternalProvider
    {
        foreach ($this->all() as $provider) { if ($provider->authorityUri() === trim($authority)) { return $provider; } }
        return null;
    }

    public function byKey(string $key): ?ExternalProvider
    {
        foreach ($this->all() as $provider) { if ($provider->key() === trim($key)) { return $provider; } }
        return null;
    }

    /** @return list<ExternalIdentifier> */
    public function parse(string $gedcom): array
    {
        $this->errors = [];
        $identifiers = [];
        $govExid = [];
        $govTag = [];
        $lines = preg_split('/\R/u', $gedcom) ?: [];
        for ($index = 0, $count = count($lines); $index < $count; ++$index) {
            $line = $lines[$index];
            if (preg_match('/^1 (?:_EXID|EXID) (.+)$/', $line, $match) !== 1) { continue; }
            $value = $match[1];
            $block = [$line];
            for (++$index; $index < $count && preg_match('/^1 /', $lines[$index]) !== 1; ++$index) { $block[] = $lines[$index]; }
            --$index;
            $authority = null;
            foreach ($block as $nested) { if (preg_match('/^2 TYPE (.+)$/', $nested, $type) === 1) { $authority = $type[1]; break; } }
            $provider = $authority === null ? null : $this->byAuthority($authority);
            $identifier = $provider?->identifier($value);
            if ($identifier === null) { continue; }
            if ($identifier->provider === 'gov') { $govExid[] = $identifier; } else { $identifiers[] = $identifier; }
        }

        for ($index = 0, $count = count($lines); $index < $count; ++$index) {
            if (preg_match('/^1 _GOV (.+)$/', $lines[$index], $match) !== 1) { continue; }
            $provider = $this->byKey('gov');
            $identifier = $provider?->identifier($match[1]);
            if ($identifier === null) {
                $this->errors[] = 'The shared place contains an invalid _GOV identifier.';
            } else {
                $govTag[] = $identifier;
            }
        }

        if ($this->errors !== []) {
            // Do not select a fallback while the GOV part is malformed.
        } elseif (count($govTag) > 1 || ($govTag !== [] && $govExid !== [])) {
            $this->errors[] = 'The shared place contains multiple GOV identifiers; exactly one _GOV value is allowed.';
        } elseif ($govTag !== []) {
            $identifiers[] = $govTag[0];
        } elseif (count($govExid) > 1) {
            $this->errors[] = 'The shared place contains multiple GOV identifiers; exactly one value is allowed.';
        } elseif ($govExid !== []) {
            $identifiers[] = $govExid[0];
        }

        return $identifiers;
    }
}
