<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier;

/** Identifier adapter for numeric GenWiki MediaWiki page IDs. */
final class GenWikiProvider implements ExternalProvider
{
    public const AUTHORITY_URI = 'https://wiki.genealogy.net/?curid=';
    public function key(): string { return 'genwiki'; }
    public function label(): string { return 'GenWiki'; }
    public function authorityUri(): string { return self::AUTHORITY_URI; }
    public function identifier(string $value): ?ExternalIdentifier
    {
        $value = trim($value);
        if (str_starts_with($value, self::AUTHORITY_URI)) { $value = substr($value, strlen(self::AUTHORITY_URI)); }
        if (preg_match('/^[1-9][0-9]{0,11}$/', $value) !== 1) { return null; }
        return new ExternalIdentifier($this->key(), $value, self::AUTHORITY_URI, self::AUTHORITY_URI . rawurlencode($value));
    }
    public function fetch(ExternalIdentifier $identifier, string $language): ?ExternalInformation { return null; }
}
