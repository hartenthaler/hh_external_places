<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Presentation;

use Fisharebest\Webtrees\I18N;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\CoordinateConsistencySettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalInformation;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderSettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\GovTypeValidator;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\PlaceNameRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;

/** Builds the compact, provider-neutral status overview for a shared place. */
final class ConsistencySummary
{
    /**
     * @param list<object{provider:string,value:string}> $identifiers
     */
    public static function render(array $identifiers, string $language, string $gedcom, ?Coordinates $sharedCoordinates, bool $showConsistent): string
    {
        $rows = [];
        $registry = new ExternalProviderRegistry();
        $byProvider = [];
        foreach ($identifiers as $identifier) {
            $byProvider[$identifier->provider] = $identifier;
        }
        foreach (ExternalProviderSettings::enabled() as $providerKey) {
            $provider = $registry->byKey($providerKey);
            if ($provider === null || !isset($byProvider[$providerKey])) {
                continue;
            }
            $identifier = $byProvider[$providerKey];
            $information = $provider->fetch($identifier, $language);
            $anchor = '#external-provider-' . $providerKey;
            if (!$information instanceof ExternalInformation) {
                $rows[] = self::row($provider->label(), I18N::translate('Provider data'), 'unavailable', I18N::translate('The provider data is currently unavailable.'), $anchor);
                continue;
            }

            $localNames = PlaceNameRegistry::byLanguage($gedcom);
            $providerName = trim(strip_tags((string) ($information->label ?? '')));
            $allLocalNames = array_merge(...array_values($localNames ?: [[]]));
            if ($providerName === '') {
                $rows[] = self::row($provider->label(), I18N::translate('Place name'), 'unavailable', I18N::translate('The provider does not supply a place name.'), $anchor);
            } elseif ($allLocalNames === []) {
                $rows[] = self::row($provider->label(), I18N::translate('Place name'), 'missing', I18N::translate('The shared place has no name to compare.'), $anchor);
            } else {
                $sameName = in_array($providerName, array_map(static fn (string $name): string => trim(strip_tags($name)), $allLocalNames), true);
                $rows[] = self::row($provider->label(), I18N::translate('Place name'), $sameName ? 'match' : 'mismatch', $sameName ? I18N::translate('The place name matches.') : I18N::translate('The place name differs.'), $anchor);
            }

            if ($information->coordinates === null) {
                $rows[] = self::row($provider->label(), I18N::translate('Coordinates'), 'unavailable', I18N::translate('The provider does not supply coordinates.'), $anchor);
            } elseif ($sharedCoordinates === null) {
                $rows[] = self::row($provider->label(), I18N::translate('Coordinates'), 'missing', I18N::translate('The shared place has no coordinates to compare.'), $anchor);
            } else {
                $level = CoordinateConsistencySettings::hierarchyForGedcom($gedcom);
                $tolerance = CoordinateConsistencySettings::forLevel($level);
                if ($tolerance === null) {
                    $rows[] = self::row($provider->label(), I18N::translate('Coordinates'), 'unavailable', I18N::translate('No coordinate tolerance is configured for this hierarchy level.'), $anchor);
                } else {
                    $distance = $sharedCoordinates->distanceTo($information->coordinates);
                    $consistent = $distance <= $tolerance;
                    $unit = $level === 'house' ? 'm' : 'km';
                    $value = $unit === 'm' ? $distance : $distance / 1000.0;
                    $limit = $unit === 'm' ? $tolerance : $tolerance / 1000.0;
                    $detail = I18N::translate('Distance: %s %s (tolerance %s %s).', number_format($value, $unit === 'm' ? 2 : 1), $unit, number_format($limit, $unit === 'm' ? 2 : 0), $unit);
                    $rows[] = self::row($provider->label(), I18N::translate('Coordinates'), $consistent ? 'match' : 'mismatch', $detail, $anchor);
                }
            }

            if ($providerKey === 'gov' && ($information->typeIds !== [] || $information->typeId !== null)) {
                $typeIds = $information->typeIds !== [] ? $information->typeIds : [$information->typeId];
                $typeStatus = GovTypeValidator::compare($gedcom, $typeIds);
                $state = match ($typeStatus['state']) {
                    'consistent' => 'match',
                    'inconsistent' => 'mismatch',
                    'missing' => 'missing',
                    default => 'unavailable',
                };
                $detail = match ($state) {
                    'match' => I18N::translate('The GOV place type matches.'),
                    'mismatch' => I18N::translate('The GOV place type differs.'),
                    'missing' => I18N::translate('The shared place has no GOV place type.'),
                    default => I18N::translate('The GOV place type is unavailable.'),
                };
                $rows[] = self::row($provider->label(), I18N::translate('Place type'), $state, $detail, $anchor);
            }

            // GOV type blocks may be dated, but the provider-neutral read
            // model currently exposes no comparable external type intervals.
            // Report that limitation explicitly instead of implying that the
            // local and external periods match.
            if ($providerKey === 'gov' && GovTypeValidator::entries($gedcom) !== []) {
                $rows[] = self::row($provider->label(), I18N::translate('Validity date range'), 'unavailable', I18N::translate('The provider does not supply a comparable validity range.'), $anchor);
            }
        }

        if ($rows === []) {
            return '';
        }
        $html = '<section id="external-consistency-summary" class="alert alert-light border mt-3" aria-labelledby="external-consistency-summary-title">';
        $html .= '<h3 id="external-consistency-summary-title" class="h5">' . e(I18N::translate('Consistency summary')) . '</h3>';
        $html .= '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>' . e(I18N::translate('Provider')) . '</th><th>' . e(I18N::translate('Field')) . '</th><th>' . e(I18N::translate('Status')) . '</th><th>' . e(I18N::translate('Details')) . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if ($row['state'] === 'match' && !$showConsistent) {
                continue;
            }
            $statusLabel = self::statusLabel($row['state']);
            $statusClass = match ($row['state']) {
                'match' => 'text-success',
                'mismatch' => 'text-danger',
                'missing' => 'text-warning',
                default => 'text-muted',
            };
            $html .= '<tr><td>' . e($row['provider']) . '</td><td>' . e($row['field']) . '</td><td><span class="' . e($statusClass) . '"><strong>' . e($statusLabel) . '</strong></span></td><td><a href="' . e($row['anchor']) . '">' . e($row['detail']) . '</a></td></tr>';
        }
        if (!str_contains($html, '<tr><td>')) {
            return '';
        }
        return $html . '</tbody></table></div></section>';
    }

    /** @return array{provider:string,field:string,state:string,detail:string,anchor:string} */
    private static function row(string $provider, string $field, string $state, string $detail, string $anchor): array
    {
        return compact('provider', 'field', 'state', 'detail', 'anchor');
    }

    private static function statusLabel(string $state): string
    {
        return match ($state) {
            'match' => I18N::translate('Match'),
            'mismatch' => I18N::translate('Mismatch'),
            'missing' => I18N::translate('Missing'),
            default => I18N::translate('Unavailable'),
        };
    }
}
