<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Infrastructure;

use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/** Versioned schema for the shared raw Wikibase response cache. */
final class WikibaseCacheSchema
{
    public const CURRENT_VERSION = 3;
    public const TABLE = 'hh_external_places_cache';

    public function ensureSchema(int $currentVersion): int
    {
        // The table may have been removed manually by an administrator while
        // the module preference still reports a current schema version.
        if (!DB::schema()->hasTable(self::TABLE)) {
            DB::schema()->create(self::TABLE, static function ($table): void {
                $table->increments('id');
                $table->string('provider', 32);
                $table->string('qid', 32);
                $table->string('language', 16);
                $table->text('payload');
                $table->unsignedInteger('fetched_at');
                $table->unsignedInteger('expires_at');

                $table->unique(['provider', 'qid', 'language'], 'hh_external_places_cache_provider_qid_language');
                $table->index('expires_at', 'hh_external_places_cache_expiry');
            });
        }

        // Version 2 contained mapped Wikidata-only payloads. They cannot be
        // safely interpreted as raw responses and are therefore disposable.
        if ($currentVersion < 2 && DB::schema()->hasTable(self::TABLE)) {
            DB::table(self::TABLE)->delete();
        }

        // Version 3 makes the cache provider-aware and stores the raw API
        // response so WikidataClient and WikibaseProvider share one source.
        if ($currentVersion < 3 && DB::schema()->hasTable(self::TABLE)) {
            if (!DB::schema()->hasColumn(self::TABLE, 'provider')) {
                DB::schema()->table(self::TABLE, static function ($table): void {
                    $table->string('provider', 32)->default('wikidata');
                });
            }

            // Existing rows use the old typed payload shape. Remove them as
            // part of the one-time migration rather than mixing formats.
            DB::table(self::TABLE)->delete();

            try {
                DB::schema()->table(self::TABLE, static function ($table): void {
                    $table->dropUnique('hh_external_places_cache_qid_language');
                });
            } catch (Throwable) {
                // New installations already have only the provider-aware key.
            }

            try {
                DB::schema()->table(self::TABLE, static function ($table): void {
                    $table->unique(['provider', 'qid', 'language'], 'hh_external_places_cache_provider_qid_language');
                });
            } catch (Throwable) {
                // The index may already exist after an interrupted upgrade.
            }
        }

        return self::CURRENT_VERSION;
    }
}
