<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Fisharebest\Webtrees\Webtrees;

/** Small bounded JSON cache shared by the non-Wikidata provider adapters. */
final class ExternalProviderCache
{
    public const TTL = 86400;

    /** @return array<string,mixed>|null */
    public function read(string $provider, string $identifier, int $ttlSeconds = self::TTL): ?array
    {
        $file = $this->file($provider, $identifier);
        if (!is_file($file) || filemtime($file) === false || time() - (int) filemtime($file) >= $ttlSeconds) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $data */
    public function write(string $provider, string $identifier, array $data): void
    {
        $file = $this->file($provider, $identifier);
        $directory = dirname($file);
        if (!is_dir($directory)) { @mkdir($directory, 0775, true); }
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded !== false) { @file_put_contents($file, $encoded, LOCK_EX); }
    }

    /** Enforce the public provider's minimum interval between requests. */
    public function allowRequest(string $provider, float $intervalSeconds = 1.0): bool
    {
        $file = $this->file($provider, '__rate_limit__');
        $directory = dirname($file);
        if (!is_dir($directory)) { @mkdir($directory, 0775, true); }
        $handle = @fopen($file, 'c+');
        if ($handle === false) { return false; }
        try {
            if (!@flock($handle, LOCK_EX)) { return false; }
            $last = trim((string) stream_get_contents($handle));
            $now = microtime(true);
            if ($last !== '' && is_numeric($last) && $now - (float) $last < $intervalSeconds) {
                return false;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $now);
            fflush($handle);
            return true;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function file(string $provider, string $identifier): string
    {
        $safe = preg_replace('/[^a-z0-9_-]+/i', '-', $provider) ?: 'external';
        return Webtrees::DATA_DIR . 'cache/hh_external_places/' . $safe . '-' . md5($identifier) . '.json';
    }
}
