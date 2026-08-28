<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

/** Small, provider-neutral read model used by the shared-place summary. */
final class ExternalInformation
{
    /** @param array<string,list<string>> $references @param list<array{label:string,value:string}> $details @param array<string,ExternalPerson> $people @param list<ExternalPersonRelation> $owners @param list<ExternalPersonRelation> $occupants @param array<int,int|float> $population */
    public function __construct(
        public readonly string $provider,
        public readonly string $value,
        public readonly string $url,
        public readonly ?string $label,
        public readonly ?string $description,
        public readonly ?string $imageUrl,
        public readonly array $types,
        public readonly array $references,
        public readonly array $details = [],
        public readonly array $people = [],
        public readonly array $owners = [],
        public readonly array $occupants = [],
        public readonly array $population = [],
        public readonly ?string $typeId = null,
    ) {
    }
}
