<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom;

use Fisharebest\Webtrees\Location;
use Fisharebest\Webtrees\Registry;
use Throwable;

/** Creates a linked media object for a provider image without downloading it. */
final class MediaEditor
{
    public function contains(Location $location, string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        foreach ($this->mediaXrefs($location->gedcom()) as $xref) {
            $media = Registry::mediaFactory()->make($xref, $location->tree());
            if ($media !== null && preg_match('/^1 FILE (.+)$/mi', $media->gedcom(), $match) === 1 && trim($match[1]) === $url) {
                return true;
            }
        }

        return false;
    }

    public function add(Location $location, string $url, string $provider, string $externalId, ?string $title = null): ?string
    {
        if (!preg_match('~^https?://[^\s<>"\']+$~i', trim($url))) {
            return null;
        }
        $url = trim($url);
        if ($this->contains($location, $url)) {
            return null;
        }

        $title = trim(strip_tags((string) ($title ?? '')));
        $title = trim(preg_replace('/[\r\n]+/u', ' ', $title) ?? '');
        $title = mb_substr($title !== '' ? $title : $externalId, 0, 240);
        $note = trim(preg_replace('/[\r\n]+/u', ' ', 'Imported from ' . $provider . ' ' . $externalId . ': ' . $url) ?? '');
        $form = $this->form($url);

        try {
            $media = $location->tree()->createRecord("0 @@ OBJE\n1 FILE " . $url . "\n2 FORM " . $form . "\n1 TITL " . $title . "\n1 NOTE " . $note . "\n");
        } catch (Throwable) {
            return null;
        }

        return $media->xref();
    }

    /** @return list<string> */
    private function mediaXrefs(string $gedcom): array
    {
        preg_match_all('/^1 OBJE @([^@]+)@$/m', $gedcom, $matches);
        return array_values(array_unique(array_map('strval', $matches[1] ?? [])));
    }

    private function form(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ['gif', 'jpeg', 'jpg', 'png', 'webp', 'svg'], true) ? strtoupper($extension) : 'JPG';
    }
}
