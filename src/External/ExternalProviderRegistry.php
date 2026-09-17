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
        $providers = [];
        foreach (WikibasePropertyCatalog::all() as $key => $definition) {
            $providers[] = new WikibaseProvider($key, $definition);
        }

        return [
            ...$providers,
            new GovProvider(),
            new GeoNamesProvider(),
            new GenWikiProvider(),
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
