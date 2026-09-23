<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

/** Provider-neutral address value used by the external-information renderer. */
final class ExternalAddress
{
    public function __construct(
        public readonly ?string $houseNumber = null,
        public readonly ?string $street = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $city = null,
        public readonly ?string $administrativeArea = null,
        public readonly ?string $freeText = null,
        public readonly ?string $from = null,
        public readonly ?string $until = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->houseNumber === null
            && $this->street === null
            && $this->postalCode === null
            && $this->city === null
            && $this->administrativeArea === null
            && $this->freeText === null;
    }

    public function key(): string
    {
        return implode('|', [
            $this->houseNumber ?? '',
            $this->street ?? '',
            $this->postalCode ?? '',
            $this->city ?? '',
            $this->administrativeArea ?? '',
            $this->freeText ?? '',
            $this->from ?? '',
            $this->until ?? '',
        ]);
    }
}
