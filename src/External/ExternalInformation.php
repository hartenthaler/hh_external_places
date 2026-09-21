<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;

/** Small, provider-neutral read model used by the shared-place summary. */
final class ExternalInformation
{
    /** @param array<string,list<string>> $references @param list<array{label:string,value:string,period?:string,from?:string,until?:string}> $details @param array<string,ExternalPerson> $people @param list<ExternalPersonRelation> $owners @param list<ExternalPersonRelation> $occupants @param array<string,int|float> $population @param list<list<array{label:string,value:string,url:string}>> $hierarchies @param list<string> $typeIds */
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
        public readonly array $hierarchies = [],
        public readonly ?Coordinates $coordinates = null,
        public readonly array $typeIds = [],
    ) {
    }
}
