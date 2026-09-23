<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

/** A bounded, read-only person or organisation record from an external provider. */
final class ExternalPerson
{
    /** @param array<string,string> $externalLinks */
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly string $url,
        public readonly ?string $label,
        public readonly ?string $birthDate,
        public readonly ?string $deathDate,
        public readonly array $externalLinks = [],
        public readonly ?string $sex = null,
    ) {
    }
}
