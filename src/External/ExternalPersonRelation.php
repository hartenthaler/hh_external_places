<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

/** A dated relationship between an external place and a person or organisation. */
final class ExternalPersonRelation
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $from,
        public readonly ?string $until,
    ) {
    }
}
