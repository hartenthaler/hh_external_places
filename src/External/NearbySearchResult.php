<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

/**
 * Common state for an editor-requested nearby search.
 *
 * Provider clients keep their own API and candidate formats, but the request
 * state and empty-result handling must be identical for every provider.
 */
final class NearbySearchResult
{
    /** @var array<string,string> */
    private const EMPTY_MESSAGES = [
        'wikidata' => 'No nearby Wikidata items were found.',
        'factgrid' => 'No nearby FactGrid items were found.',
        'gov'      => 'No GOV places were found.',
    ];

    /** @param list<mixed> $candidates */
    private function __construct(
        private readonly bool $requested,
        private readonly array $candidates,
        private readonly string $emptyMessage,
    ) {
    }

    /** @param list<mixed> $candidates */
    public static function forProvider(string $provider, bool $requested, array $candidates): self
    {
        $message = self::EMPTY_MESSAGES[$provider] ?? '';

        return new self($requested && $message !== '', $candidates, $message);
    }

    public function isEmpty(): bool
    {
        return $this->requested && $this->candidates === [];
    }

    public function emptyMessage(): string
    {
        return $this->emptyMessage;
    }
}
