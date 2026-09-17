<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Infrastructure;

use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/** Shared expiring cache for raw Wikidata and FactGrid API responses. */
final class WikibaseCacheRepository
{
    public const DEFAULT_TTL = 604800; // Seven days.

    /** @return array<string,mixed>|null */
    public function find(string $provider, string $qid, string $language): ?array
    {
        if (!$this->validKey($provider, $qid, $language)) {
            return null;
        }

        try {
            $row = DB::table(WikibaseCacheSchema::TABLE)
                ->where('provider', '=', $provider)
                ->where('qid', '=', $qid)
                ->where('language', '=', $language)
                ->where('expires_at', '>', time())
                ->first();
        } catch (Throwable) {
            return null;
        }

        if ($row === null || !is_string($row->payload)) {
            return null;
        }

        try {
            $payload = json_decode($row->payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        // Only raw wbgetentities responses belong in this shared cache. This
        // rejects payloads written by the former typed Wikidata cache.
        return is_array($payload) && is_array($payload['entities'] ?? null) ? $payload : null;
    }

    /** @param array<string,mixed> $payload */
    public function store(string $provider, string $qid, string $language, array $payload, int $ttl = self::DEFAULT_TTL): void
    {
        if (!$this->validKey($provider, $qid, $language) || !is_array($payload['entities'] ?? null)) {
            return;
        }

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $now = time();
            DB::table(WikibaseCacheSchema::TABLE)->updateOrInsert(
                ['provider' => $provider, 'qid' => $qid, 'language' => $language],
                ['payload' => $encoded, 'fetched_at' => $now, 'expires_at' => $now + max(60, $ttl)],
            );
        } catch (Throwable) {
            // A cache failure must never make external place information fail.
        }
    }

    private function validKey(string $provider, string $qid, string $language): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $provider) === 1
            && preg_match('/^Q[1-9][0-9]*$/', $qid) === 1
            && preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/', $language) === 1;
    }
}
