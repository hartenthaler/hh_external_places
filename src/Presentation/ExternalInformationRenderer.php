<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Presentation;

use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\I18N;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalInformation;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalAddress;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderRegistry;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\ExternalProviderSettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\GeoNamesProvider;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\GovExternalIdentifierCatalog;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\LanguageCode;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\NominatimProvider;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\External\CoordinateConsistencySettings;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\AddressEditor;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Gedcom\GovTypeValidator;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Geo\Coordinates;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\MoreI18N;

final class ExternalInformationRenderer
{
    public function __construct(private readonly bool $showConsistentReferences)
    {
    }

    private function showConsistentReferences(): bool
    {
        return $this->showConsistentReferences;
    }

    public function externalInformationHtml(array $identifiers, string $language, string $skip = '', string $placeName = '', ?string $assignmentUrl = null, string $gedcom = '', array &$genwikiShown = [], ?Coordinates $sharedCoordinates = null, array $associatedPersonKeys = []): string
    {
        if ($identifiers === []) {
            return '';
        }

        $registry = new ExternalProviderRegistry();
        $html = '';
        foreach ($registry->all() as $provider) {
            if ($provider->key() === $skip || !ExternalProviderSettings::isEnabled($provider->key())) {
                continue;
            }
            foreach ($identifiers as $identifier) {
                if ($identifier->provider !== $provider->key()) {
                    continue;
                }
                $information = $provider->fetch($identifier, $language);
                $displayLabel = trim(strip_tags($information?->label ?? $identifier->value));
                if ($provider->key() === 'gov' && ($displayLabel === $identifier->value || $displayLabel === '')) {
                    $displayLabel = $placeName !== '' ? trim(strip_tags($placeName)) : $identifier->value;
                }
                $showIdentifier = $displayLabel !== $identifier->value;
                $html .= '<section class="mt-4">' . $this->providerHeading($provider->key(), $provider->label())
                    . '<a href="' . e($identifier->url) . '" rel="noopener noreferrer" target="_blank">'
                    . e($displayLabel) . '</a>' . ($showIdentifier ? ' <small>(' . e($identifier->value) . ')</small>' : '');
                if ($information?->description !== null) {
                    $description = $provider->key() === 'gov'
                        ? I18N::translate($information->description)
                        : $information->description;
                    if ($provider->key() === 'geonames') {
                        $description = $this->externalDetailValue('Feature', $description);
                    }
                    $html .= ' — ' . e($description);
                }
                foreach ($information?->hierarchies ?? [] as $hierarchy) {
                    $parts = [];
                    foreach (array_reverse($hierarchy) as $node) {
                        $parts[] = '<a href="' . e($node['url']) . '" title="' . e($this->externalDetailLabel($node['label'])) . '" target="_blank" rel="noopener noreferrer">' . e($node['value']) . '</a>';
                    }
                    if ($parts !== []) {
                        $html .= '<br><small>' . e(I18N::translate('Hierarchy')) . ': ' . implode(', ', $parts) . '</small>';
                    }
                }
                foreach ($information?->details ?? [] as $detail) {
                    $value = $this->externalDetailValue($detail['label'], $detail['value']);
                    $detailLabel = $this->externalDetailLabel($detail['label']);
                    $alternateLanguage = null;
                    // Any provider may expose language-labelled place names;
                    // keep comparison provider-neutral and normalize codes
                    // through the shared LanguageCode helper.
                    if (str_starts_with($detail['label'], 'Alternate name (')) {
                        preg_match('/^Alternate name \(([a-z]{2,3}(?:[-_][a-z]{2,4})?)\)$/i', $detail['label'], $languageMatch);
                        $alternateLanguage = LanguageCode::normalize($languageMatch[1] ?? '');
                        $sameLanguage = $this->locationNamesByLanguage($gedcom)[$alternateLanguage] ?? [];
                        if (in_array($value, $sameLanguage, true) && !$this->showConsistentReferences()) {
                            continue;
                        }
                    }
                    $period = '';
                    if (is_string($detail['period'] ?? null) && trim($detail['period']) !== '') {
                        $period = ' — ' . I18N::translate('Period') . ': ' . trim($detail['period']);
                    } else {
                        $periodParts = [];
                        if (is_string($detail['from'] ?? null) && trim($detail['from']) !== '') {
                            $periodParts[] = MoreI18N::xlate('From') . ': ' . trim($detail['from']);
                        }
                        if (is_string($detail['until'] ?? null) && trim($detail['until']) !== '') {
                            $periodParts[] = MoreI18N::xlate('Until') . ': ' . trim($detail['until']);
                        }
                        if ($periodParts !== []) {
                            $period = ' — ' . implode(', ', $periodParts);
                        }
                    }
                    $html .= '<br><small>' . e($detailLabel) . ': ' . ($detail['label'] === 'External identifier' ? $this->externalIdentifierHtml($value) : e($value)) . e($period) . '</small>';
                    if (str_starts_with($detail['label'], 'Alternate name (')) {
                        $locNames = $this->locationNamesByLanguage($gedcom);
                        $sameLanguage = $locNames[$alternateLanguage] ?? [];
                        if (in_array($value, $sameLanguage, true)) {
                            if ($this->showConsistentReferences()) {
                                $html .= ' <span class="text-success">(' . e(I18N::translate('consistent with the shared place name')) . ')</span>';
                            }
                        } elseif ($sameLanguage !== []) {
                            $html .= ' <span class="text-danger">(' . e(I18N::translate('inconsistent with the shared place name')) . ')</span>';
                        } elseif ($assignmentUrl !== null) {
                            $html .= ' <form method="post" action="' . e($assignmentUrl) . '" class="d-inline">' . csrf_field() . '<input type="hidden" name="operation" value="add-location-name"><input type="hidden" name="name" value="' . e($value) . '"><input type="hidden" name="language" value="' . e($alternateLanguage) . '"><button class="btn btn-sm btn-outline-primary" type="submit">' . e(I18N::translate('Add place name')) . '</button></form>';
                        }
                    }
                }
                if ($information !== null && $information->addresses !== []) {
                    $html .= $this->addressesHtml($information, $assignmentUrl, $gedcom);
                }
                if (($information?->population ?? []) !== []) {
                    $html .= $this->populationHtml($information->population ?? []);
                }
                if ($information?->coordinates !== null) {
                    $html .= $this->coordinateConsistencyHtml($information->coordinates, $sharedCoordinates, $provider->label(), $gedcom, $assignmentUrl);
                }
                if ($information?->imageUrl !== null) {
                    $html .= '<br><img src="' . e($information->imageUrl) . '" alt="" loading="lazy" style="max-width:500px;max-height:500px;width:auto;height:auto">';
                }
                if ($information !== null) {
                    if ($provider->key() === 'gov' && ($information->typeIds !== [] || $information->typeId !== null)) {
                        $providerTypeIds = $information->typeIds !== [] ? $information->typeIds : [$information->typeId];
                        $typeStatus = GovTypeValidator::compare($gedcom, $providerTypeIds);
                        if ($typeStatus['state'] === 'consistent' && $this->showConsistentReferences()) {
                            $html .= '<br><small>' . e(I18N::translate('GOV place type is consistent with the shared place.')) . '</small>';
                        } elseif ($typeStatus['state'] === 'inconsistent') {
                            $html .= '<br><span class="text-danger"><strong>' . e(I18N::translate('GOV place type is inconsistent with the shared place.')) . '</strong></span>';
                        } elseif ($assignmentUrl !== null) {
                            $html .= '<br><span class="text-warning">' . e(I18N::translate('GOV place type is missing from the shared place.')) . '</span>';
                            $typeToAdd = $providerTypeIds[0] ?? null;
                            if ($typeToAdd !== null) {
                                $html .= ' <form method="post" action="' . e($assignmentUrl) . '" class="d-inline">' . csrf_field() . '<input type="hidden" name="operation" value="add-gov-type"><input type="hidden" name="gov_type" value="' . e($typeToAdd) . '"><button class="btn btn-sm btn-outline-primary" type="submit">' . e(I18N::translate('Add GOV place type')) . '</button></form>';
                            }
                        }
                    }
                    $html .= $this->crossReferenceHtml($information, $identifiers, $assignmentUrl);
                    $html .= $this->externalPersonRelationsHtml($information, $assignmentUrl, $associatedPersonKeys);
                }
                $html .= $this->sourceHtml($provider->label(), $this->providerHomepage($provider->key())) . '</section>';
                if ($provider->key() === 'genwiki') {
                    $genwikiShown[] = $identifier->url;
                }
            }
        }
        return $html;
    }

    public function addressesHtml(ExternalInformation $information, ?string $assignmentUrl, string $gedcom = ''): string
    {
        $addressEditor = new AddressEditor();
        $existingAddresses = $addressEditor->read($gedcom);
        $rows = '';
        foreach ($information->addresses as $address) {
            if (!$address instanceof ExternalAddress) {
                continue;
            }
            $street = $address->street ?? $address->freeText ?? '';
            $candidate = [
                'house_number' => $address->houseNumber ?? '',
                'street' => $street,
                'postal_code' => $address->postalCode ?? '',
                'city' => $address->city ?? '',
                'from' => $address->from ?? '',
                'until' => $address->until ?? '',
            ];
            $consistent = false;
            foreach ($existingAddresses as $existingAddress) {
                if ($addressEditor->matches($existingAddress, $candidate)) {
                    $consistent = true;
                    break;
                }
            }
            if ($consistent && !$this->showConsistentReferences()) {
                continue;
            }
            $row = '<tr><td>' . e($address->houseNumber ?? '') . '</td><td>' . e($street) . '</td><td>' . e($address->postalCode ?? '') . '</td><td>' . e($address->city ?? '') . '</td><td>' . e($address->administrativeArea ?? '') . '</td><td>' . e($address->from ?? '') . '</td><td>' . e($address->until ?? '') . '</td><td>';
            if ($consistent) {
                $row .= '<span class="text-success">' . e(I18N::translate('consistent')) . '</span>';
            } elseif ($assignmentUrl !== null) {
                $row .= '<form method="post" action="' . e($assignmentUrl) . '" class="d-inline">' . csrf_field()
                    . '<input type="hidden" name="operation" value="add-address">'
                    . '<input type="hidden" name="address_house_number" value="' . e($address->houseNumber ?? '') . '">'
                    . '<input type="hidden" name="address_street" value="' . e($street) . '">'
                    . '<input type="hidden" name="address_postal_code" value="' . e($address->postalCode ?? '') . '">'
                    . '<input type="hidden" name="address_city" value="' . e($address->city ?? '') . '">'
                    . '<input type="hidden" name="address_from" value="' . e($address->from ?? '') . '">'
                    . '<input type="hidden" name="address_until" value="' . e($address->until ?? '') . '">'
                    . '<input type="hidden" name="address_provider" value="' . e($information->provider) . '">'
                    . '<input type="hidden" name="address_external_id" value="' . e($information->value) . '">'
                    . '<input type="hidden" name="address_source_url" value="' . e($information->url) . '">'
                    . '<button class="btn btn-sm btn-outline-primary" type="submit">' . e(I18N::translate('Add address')) . '</button></form>';
            }
            $rows .= $row . '</td></tr>';
        }
        if ($rows === '') {
            return '';
        }
        return '<h5 class="mt-3">' . e(MoreI18N::xlate('Addresses')) . '</h5><div class="table-responsive"><table class="table table-sm"><thead><tr>'
            . '<th>' . e(I18N::translate('House number')) . '</th><th>' . e(MoreI18N::xlate('Street')) . '</th><th>' . e(MoreI18N::xlate('Postal code')) . '</th><th>' . e(MoreI18N::xlate('Place')) . '</th><th>' . e(MoreI18N::xlate('Administrative area')) . '</th><th>' . e(MoreI18N::xlate('From')) . '</th><th>' . e(MoreI18N::xlate('Until')) . '</th><th></th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    private function genwikiReferenceHtml(string $url, array $identifiers, ?string $assignmentUrl, array &$shown): string
    {
        $pageId = null;
        if (preg_match('~[?&]curid=([1-9][0-9]{0,11})~i', $url, $match) === 1) {
            $pageId = $match[1];
        } elseif (preg_match('~^https?://(?:www\\.)?wiki\\.genealogy\\.net/[^?#]+$~i', $url) !== 1) {
            return '';
        }
        $canonical = $pageId === null ? $url : 'https://wiki.genealogy.net/?curid=' . $pageId;
        if (in_array($canonical, $shown, true)) {
            return '';
        }
        $shown[] = $canonical;
        $matching = false;
        foreach ($identifiers as $identifier) {
            if ($pageId !== null && $identifier->provider === 'genwiki' && $identifier->value === $pageId) {
                $matching = true;
                break;
            }
        }
        // An assigned GenWiki identifier is rendered by the GenWiki provider
        // block (including its title and introduction). Hide only the shorter
        // cross-reference line when consistent values are configured to be
        // suppressed; otherwise show it with its consistency marker.
        if ($matching && !$this->showConsistentReferences()) {
            return '';
        }
        $displayValue = $pageId ?? $canonical;
        $html = '<br><small>' . e(I18N::translate('Reference to GenWiki')) . ': <a href="' . e($canonical) . '" rel="noopener noreferrer" target="_blank">' . e($displayValue) . '</a>';
        if ($matching) {
            $html .= ' <span class="text-success">(' . e(I18N::translate('consistent')) . ')</span>';
        } elseif ($pageId !== null && $assignmentUrl !== null) {
            $html .= ' <form method="post" action="' . e($assignmentUrl) . '" class="d-inline">' . csrf_field()
                . '<input type="hidden" name="operation" value="add-external-id">'
                . '<input type="hidden" name="provider" value="genwiki">'
                . '<input type="hidden" name="external_id" value="' . e($pageId) . '">'
                . '<button class="btn btn-sm btn-outline-primary py-0" type="submit">' . e(I18N::translate('Assign')) . '</button></form>';
        }
        return $html . '</small>';
    }

    public function geoNamesHtml(string $placeName, string $language): string
    {
        if (!ExternalProviderSettings::isEnabled('geonames')) { return ''; }
        $information = (new GeoNamesProvider())->lookup($placeName, $language);
        if ($information === null) { return ''; }
        $html = '<section class="mt-4">' . $this->providerHeading('geonames', I18N::translate('GeoNames')) . '<a href="' . e($information['url']) . '" rel="noopener noreferrer" target="_blank">' . e($information['label']) . '</a>';
        foreach ($information['details'] as $detail) {
            $html .= '<br><small>' . e($this->externalDetailLabel($detail['label'])) . ': ' . e($this->externalDetailValue($detail['label'], $detail['value'])) . '</small>';
        }
        return $html . $this->sourceHtml('GeoNames', 'https://www.geonames.org/') . '</section>';
    }

    public function nominatimPlaceName(string $gedcom, string $fallback): string
    {
        $firstName = '';
        foreach (preg_split('/\r?\n/', $gedcom) ?: [] as $line) {
            if (preg_match('/^1 NAME(?:\s+)(.+)$/', $line, $match) === 1) {
                $name = trim($match[1]);
                if ($name !== '') {
                    $firstName = $name;
                    break;
                }
            }
        }

        $fallback = trim(html_entity_decode(strip_tags($fallback), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $components = array_values(array_filter(array_map('trim', explode(',', $fallback)), static fn (string $component): bool => $component !== ''));
        if ($firstName === '') {
            return $fallback;
        }
        if ($components !== [] && strcasecmp($components[0], $firstName) === 0) {
            array_shift($components);
        }

        return $components === [] ? $firstName : $firstName . ', ' . $components[0];
    }

    public function nominatimHtml(string $placeName, string $language, string $gedcom = '', ?string $assignmentUrl = null): string
    {
        if (!ExternalProviderSettings::isEnabled('nominatim')) {
            return '';
        }
        // Geocoding is useful for concrete places and administrative units
        // below the country level.  For countries, federations and planets
        // the shared-place name is often only an ISO/hierarchy code (for
        // example DEU, EU, Erde), so querying public geocoders produces no
        // useful result and must be skipped entirely.
        if (in_array(CoordinateConsistencySettings::hierarchyForGedcom($gedcom), ['country', 'federation', 'planet'], true)) {
            return '';
        }
        $provider = new NominatimProvider();
        $information = $provider->lookup($placeName, $language, $this->nominatimLayer($gedcom));
        if ($information === null) {
            return '<div class="alert alert-secondary small"><strong>Nominatim diagnostic:</strong> ' . e($provider->diagnostic() !== '' ? $provider->diagnostic() : 'no result') . '</div>';
        }
        $html = '<section class="mt-4">' . $this->providerHeading('nominatim', 'Nominatim') . '<a href="' . e($information['url']) . '" rel="noopener noreferrer" target="_blank">' . e($information['label']) . '</a>';
        if ($information['description'] !== null && $information['description'] !== '') {
            $html .= ' — ' . e($information['description']);
        }
        if (($information['addresses'] ?? []) !== []) {
            $nominatimInformation = new ExternalInformation(
                provider: 'nominatim',
                value: '',
                url: (string) $information['url'],
                label: (string) $information['label'],
                description: $information['description'],
                imageUrl: null,
                types: [],
                references: [],
                addresses: $information['addresses'],
            );
            $html .= $this->addressesHtml($nominatimInformation, $assignmentUrl, $gedcom);
        }
        if ($provider->diagnostic() !== '') {
            $html .= '<div class="alert alert-info small mt-2"><strong>Nominatim diagnostic:</strong> ' . e($provider->diagnostic()) . '</div>';
        }
        if (is_array($information['geometry'] ?? null)) {
            $mapId = 'nominatim-map-' . substr(md5($information['url']), 0, 10);
            $geometry = json_encode($information['geometry'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $html .= '<div id="' . e($mapId) . '" style="height:320px;min-height:240px" class="mt-3 rounded border" role="img" aria-label="' . e(I18N::translate('Map showing the Nominatim geometry')) . '"></div>';
            // Dedicated module pages do not necessarily load webtrees map
            // assets. Load Leaflet only when a polygon is actually present.
        $html .= '<script>(function(){const el=document.getElementById(' . json_encode($mapId, JSON_THROW_ON_ERROR) . ');const geometry=' . $geometry . ';function draw(){if(!el||typeof L === "undefined"){return;}const map=L.map(el);L.tileLayer("https://{s}.tile.openstreetmap.de/{z}/{x}/{y}.png",{attribution:"&copy; OpenStreetMap contributors"}).addTo(map);const layer=L.geoJSON({type:"Feature",geometry:geometry},{style:{color:"#3388ff",weight:2,fillColor:"#3388ff",fillOpacity:0.35}}).addTo(map);map.fitBounds(layer.getBounds(),{padding:[12,12]});setTimeout(function(){map.invalidateSize();},100);}if(typeof L!=="undefined"){draw();return;}if(!document.querySelector("[data-hh-leaflet]")){const css=document.createElement("link");css.rel="stylesheet";css.href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css";document.head.appendChild(css);const script=document.createElement("script");script.src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js";script.async=true;script.dataset.hhLeaflet="1";script.onload=draw;document.head.appendChild(script);}else{const timer=setInterval(function(){if(typeof L!=="undefined"){clearInterval(timer);draw();}},50);}})();</script>';
        }
        return $html . $this->sourceHtml('Nominatim', 'https://nominatim.openstreetmap.org/') . '</section>';
    }

    public function coordinateConsistencyHtml(Coordinates $providerCoordinates, ?Coordinates $sharedCoordinates, string $providerLabel, string $gedcom, ?string $assignmentUrl): string
    {
        $level = CoordinateConsistencySettings::hierarchyForGedcom($gedcom);
        if ($level === 'planet') {
            // Planetary records deliberately have no meaningful point
            // coordinate, even if a provider returns a technical fallback
            // such as GeoNames N0 E0.
            return '';
        }
        if ($sharedCoordinates === null) {
            if ($assignmentUrl === null) { return '<br><small>' . e(I18N::translate('Coordinates are available from %s.', $providerLabel)) . '</small>'; }
            return '<br><small>' . e(I18N::translate('Coordinates are available from %s.', $providerLabel)) . '</small> <form method="post" action="' . e($assignmentUrl) . '" class="d-inline">' . csrf_field()
                . '<input type="hidden" name="operation" value="add-coordinates"><input type="hidden" name="latitude" value="' . e((string) $providerCoordinates->latitude) . '"><input type="hidden" name="longitude" value="' . e((string) $providerCoordinates->longitude) . '"><button class="btn btn-sm btn-outline-primary" type="submit">' . e(I18N::translate('Add coordinates')) . '</button></form>';
        }

        $tolerance = CoordinateConsistencySettings::forLevel($level);
        if ($tolerance === null) { return ''; }
        $distance = $sharedCoordinates->distanceTo($providerCoordinates);
        $unit = $level === 'house' ? 'm' : 'km';
        $displayDistance = $unit === 'm' ? $distance : $distance / 1000.0;
        $formatted = number_format($displayDistance, $unit === 'm' && $displayDistance < 10 ? 2 : 1);
        $formattedTolerance = number_format($unit === 'm' ? $tolerance : $tolerance / 1000.0, $unit === 'm' ? 2 : 0);
        $message = I18N::translate('Coordinate distance from %s: %s %s (tolerance %s %s).', $providerLabel, $formatted, $unit, $formattedTolerance, $unit);
        if ($distance <= $tolerance) {
            return $this->showConsistentReferences() ? '<br><small class="text-success">' . e($message . ' ' . I18N::translate('consistent')) . '</small>' : '';
        }
        return '<br><small class="text-danger">' . e($message . ' ' . I18N::translate('inconsistent')) . '</small>';
    }

    private function nominatimLayer(string $gedcom): ?string
    {
        if (preg_match('/^2 _GOVTYPE\s+7\s*$/imu', $gedcom) === 1) { return 'state'; }
        if (preg_match('/^1 TYPE .*?(Landkreis|county)/im', $gedcom)) { return 'county'; }
        if (preg_match('/^1 TYPE .*?(Bundesland|Bundesstaat|state)/im', $gedcom)) { return 'state'; }
        if (preg_match('/^1 TYPE .*?(Gemeinde|municipality|municipal)/im', $gedcom)) { return 'municipality'; }
        if (preg_match('/^1 TYPE .*?(Dorf|Ortsteil|Stadtteil|Gemeindeteil|village|locality)/im', $gedcom)) { return 'locality'; }
        if (preg_match('/^1 TYPE .*?(Stadt|city|town)/im', $gedcom)) { return 'city'; }
        if (preg_match('/^1 TYPE .*?(Haus|Hof|house|building)/im', $gedcom)) { return 'house'; }
        return null;
    }

    public function sourceHtml(string $name, string $url): string
    {
        return '<br><small>' . e(MoreI18N::xlate('Source')) . ': <a href="' . e($url) . '" rel="noopener noreferrer" target="_blank">' . e($name) . '</a></small>';
    }

    public function providerHeading(string $provider, string $label): string
    {
        $iconUrl = match ($provider) {
            'wikidata' => 'https://www.wikidata.org/static/favicon/wikidata.ico',
            'factgrid' => 'https://database.factgrid.de/favicon.ico',
            'gov' => 'https://gov.genealogy.net/favicon.ico',
            'geonames' => 'https://www.geonames.org/favicon.ico',
            'genwiki' => 'https://wiki.genealogy.net/images/favicon.ico',
            'nominatim' => 'https://nominatim.openstreetmap.org/favicon.ico',
            default => null,
        };

        $icon = $iconUrl === null ? '' : '<img class="me-1" src="' . e($iconUrl) . '" width="20" height="20" alt="" aria-hidden="true" loading="lazy" referrerpolicy="no-referrer" style="vertical-align:-0.2em">';

        return '<h3 class="h4 mb-2" style="display:flex;flex-flow:row nowrap;align-items:center;justify-content:flex-start;text-align:left;gap:.4rem;white-space:nowrap">' . $icon . e($label) . '</h3>';
    }

    private function providerHomepage(string $provider): string
    {
        return match ($provider) {
            'factgrid' => 'https://database.factgrid.de/',
            'gov' => 'https://gov.genealogy.net/',
            'geonames' => 'https://www.geonames.org/',
            'genwiki' => 'https://wiki.genealogy.net/',
            'nominatim' => 'https://nominatim.openstreetmap.org/',
            default => 'https://www.wikidata.org/',
        };
    }

    private function externalDetailLabel(string $label): string
    {
        if (preg_match('/^Alternate name \(([a-z]{2,3}(?:[-_][a-z]{2,4})?)\)$/i', $label, $matches) === 1) {
            return I18N::translate('Alternate name') . ' (' . strtolower($matches[1]) . ')';
        }
        return match (strtolower($label)) {
            'country' => MoreI18N::xlate('Country'),
            'region' => MoreI18N::xlate('Region'),
            'street' => MoreI18N::xlate('Street'),
            'postal code' => MoreI18N::xlate('Postal code'),
            'place' => MoreI18N::xlate('Place'),
            'population' => I18N::translate('Population'),
            'external identifier' => I18N::translate('External identifier'),
            'administrative area' => I18N::translate('Administrative area'),
            'feature' => I18N::translate('Feature'),
            'elevation' => I18N::translate('Elevation'),
            'house number' => I18N::translate('House number'),
            'municipality' => I18N::translate('Municipality'),
            'county' => I18N::translate('County'),
            'state' => I18N::translate('State'),
            'type' => I18N::translate('Type'),
            'area' => I18N::translate('Area'),
            'continent' => I18N::translate('Continent'),
            'independent political entity' => I18N::translate('Independent political entity'),
            'first-order administrative division' => I18N::translate('First-order administrative division'),
            'second-order administrative division' => I18N::translate('Second-order administrative division'),
            'third-order administrative division' => I18N::translate('Third-order administrative division'),
            'fourth-order administrative division' => I18N::translate('Fourth-order administrative division'),
            'populated place' => I18N::translate('populated place'),
            default => $label,
        };
    }

    private function locationNamesByLanguage(string $gedcom): array
    {
        $names = [];
        $currentName = null;
        foreach (preg_split('/\r?\n/', $gedcom) ?: [] as $line) {
            if (preg_match('/^1 NAME(?: |$)(.*)$/', $line, $match) === 1 && trim($match[1]) !== '') {
                $currentName = trim($match[1]);
                $names[''] ??= [];
                $names[''][] = $currentName;
            } elseif ($currentName !== null && preg_match('/^2 LANG (.+)$/', $line, $match) === 1) {
                $language = LanguageCode::normalize($match[1]);
                if ($language === '') { continue; }
                $names[$language][] = $currentName;
            }
        }
        foreach ($names as $language => $values) { $names[$language] = array_values(array_unique($values)); }
        return $names;
    }

    private function populationHtml(array $population): string
    {
        if ($population === []) { return ''; }
        uksort($population, static function (string $left, string $right): int {
            $leftKey = self::populationSortKey($left);
            $rightKey = self::populationSortKey($right);
            return $leftKey <=> $rightKey ?: strnatcasecmp($left, $right);
        });
        $html = '<div class="d-flex flex-wrap gap-3 align-items-start mt-2"><div><strong>' . e(I18N::translate('Population')) . '</strong><table class="table table-sm mb-0"><thead><tr><th>' . e(MoreI18N::xlate('Year')) . '</th><th>' . e(I18N::translate('Population')) . '</th></tr></thead><tbody>';
        foreach ($population as $year => $value) {
            $html .= '<tr><td>' . e((string) $year) . '</td><td>' . e(I18N::number($value)) . '</td></tr>';
        }
        $html .= '</tbody></table></div>' . $this->populationChartHtml($population) . '</div>';
        return $html;
    }

    private function populationChartHtml(array $population): string
    {
        if (count($population) < 2) {
            return '';
        }

        uksort($population, static function (string $left, string $right): int {
            $leftKey = self::populationSortKey($left);
            $rightKey = self::populationSortKey($right);
            return $leftKey <=> $rightKey ?: strnatcasecmp($left, $right);
        });
        $points = array_keys($population);
        $values = array_values($population);
        $min = min($values); $max = max($values); $range = $max - $min ?: 1;
        $timestamps = array_map(static fn (string $point): int => self::populationPointTimestamp($point), $points);
        $minTimestamp = min($timestamps); $maxTimestamp = max($timestamps);
        $timestampRange = $maxTimestamp - $minTimestamp ?: 1;
        $coordinates = [];
        foreach ($values as $index => $value) {
            $x = 35 + (270 * ($timestamps[$index] - $minTimestamp) / $timestampRange);
            if ($maxTimestamp === $minTimestamp) {
                $x = 35 + (270 * $index / max(1, count($values) - 1));
            }
            $y = 145 - (115 * ((float) $value - $min) / $range);
            $coordinates[] = round($x, 2) . ',' . round($y, 2);
        }
        $yearLabel = MoreI18N::xlate('Year');
        $populationLabel = I18N::translate('Population');
        $svg = '<svg viewBox="0 0 320 190" width="320" height="190" role="img" aria-label="' . e($populationLabel) . '"><line x1="35" y1="145" x2="305" y2="145" stroke="currentColor" stroke-opacity=".35"/><line x1="35" y1="20" x2="35" y2="145" stroke="currentColor" stroke-opacity=".35"/><polyline fill="none" stroke="currentColor" stroke-width="2" points="' . e(implode(' ', $coordinates)) . '"/>';
        foreach ($values as $index => $value) {
            [$x, $y] = explode(',', $coordinates[$index]);
            $svg .= '<circle cx="' . e($x) . '" cy="' . e($y) . '" r="3" fill="currentColor"><title>' . e((string) $points[$index] . ': ' . I18N::number($value)) . '</title></circle>';
        }
        $svg .= '<text x="170" y="178" text-anchor="middle" font-size="11">' . e($yearLabel) . '</text>';
        $svg .= '<text x="11" y="83" text-anchor="middle" font-size="11" transform="rotate(-90 11 83)">' . e($populationLabel) . '</text>';
        return '<div>' . $svg . '</svg></div>';
    }

    private static function populationSortKey(string $point): array
    {
        $point = trim((string) preg_replace('/\s+\(\d+\)$/u', '', $point));
        $qualifier = 2;
        if (preg_match('/^(bis|ab)\s+(.+)$/iu', $point, $matches) === 1) {
            $qualifier = strtolower($matches[1]) === 'bis' ? 0 : 1;
            $point = trim($matches[2]);
        }
        return [self::populationTimestamp($point, $qualifier), $qualifier, $point];
    }

    private static function populationPointTimestamp(string $point): int
    {
        $point = trim((string) preg_replace('/\s+\(\d+\)$/u', '', $point));
        $qualifier = 2;
        if (preg_match('/^(bis|ab)\s+(.+)$/iu', $point, $matches) === 1) {
            $qualifier = strtolower($matches[1]) === 'bis' ? 0 : 1;
            $point = trim($matches[2]);
        }
        return self::populationTimestamp($point, $qualifier);
    }

    private static function populationTimestamp(string $point, int $qualifier = 2): int
    {
        $point = trim(strtoupper($point));
        $year = null;
        $month = null;
        $day = null;
        if (preg_match('/^(\d{1,2})\s+([A-ZÄÖÜ]{3,12})\.?\s+(\d{4})$/u', $point, $matches) === 1) {
            $day = (int) $matches[1];
            $month = self::populationMonthNumber($matches[2]);
            $year = (int) $matches[3];
        } elseif (preg_match('/^([A-ZÄÖÜ]{3,12})\.?\s+(\d{4})$/u', $point, $matches) === 1) {
            $month = self::populationMonthNumber($matches[1]);
            $year = (int) $matches[2];
        } elseif (preg_match('/^(\d{4})$/', $point, $matches) === 1) {
            $year = (int) $matches[1];
        } elseif (preg_match('/\b(\d{4})\b/', $point, $matches) === 1) {
            $year = (int) $matches[1];
        }
        if ($year === null) { return 0; }
        $month ??= match ($qualifier) {
            0 => 1,
            1 => 12,
            default => 7,
        };
        if ($day === null) {
            $day = match ($qualifier) {
                0 => 1,
                1 => (int) gmdate('t', gmmktime(0, 0, 0, $month, 1, $year)),
                default => $month === 7 ? 1 : 15,
            };
        }
        if ($qualifier === 1 && $day === 1 && preg_match('/^[A-ZÄÖÜ]{3,12}\.?\s+\d{4}$/u', $point) === 1) {
            $day = (int) gmdate('t', gmmktime(0, 0, 0, $month, 1, $year));
        }
        return gmmktime(0, 0, 0, $month, $day, $year);
    }

    private static function populationMonthNumber(string $month): ?int
    {
        return match (rtrim(strtoupper(trim($month)), '.')) {
            'JAN', 'JANUARY' => 1,
            'FEB', 'FEBRUARY' => 2,
            'MAR', 'MARCH', 'MÄR', 'MAER' => 3,
            'APR', 'APRIL' => 4,
            'MAY', 'MAI' => 5,
            'JUN', 'JUNE' => 6,
            'JUL', 'JULY' => 7,
            'AUG', 'AUGUST' => 8,
            'SEP', 'SEPTEMBER' => 9,
            'OCT', 'OCTOBER', 'OKT' => 10,
            'NOV', 'NOVEMBER' => 11,
            'DEC', 'DECEMBER', 'DEZ' => 12,
            default => is_numeric($month) && (int) $month >= 1 && (int) $month <= 12 ? (int) $month : null,
        };
    }

    private function externalDetailValue(string $label, string $value): string
    {
        if ($label === 'Feature') {
            // GeoNames may vary the capitalization of feature labels. Use a
            // canonical message id so translations (e.g. "populated place")
            // are found reliably.
            return match (strtolower(trim($value))) {
                'populated place' => I18N::translate('populated place'),
                default => I18N::translate($value),
            };
        }
        if ($label === 'Population' && is_numeric(trim($value))) {
            return I18N::number((float) $value);
        }

        if ($label !== 'Elevation' || trim($value) === '') {
            return $value;
        }

        return $value . ' ' . I18N::translate('m above sea level');
    }

    private function externalIdentifierHtml(string $value): string
    {
        $value = trim($value);
        $catalogEntry = GovExternalIdentifierCatalog::forValue($value);
        $url = GovExternalIdentifierCatalog::url($value);
        $display = $url === null ? e($value) : '<a href="' . e($url) . '" rel="noopener noreferrer" target="_blank">' . e($value) . '</a>';
        if ($catalogEntry !== null) {
            $display .= ' — ' . e(I18N::translate($catalogEntry['description']));
        }
        return $display;
    }

    private function externalPersonRelationsHtml(ExternalInformation $information, ?string $assignmentUrl, array $associatedPersonKeys): string
    {
        if ($information->owners === [] && $information->occupants === []) {
            return '';
        }

        $html = '';
        foreach ([
            MoreI18N::xlate('Owner') => [$information->owners, 'Owner'],
            I18N::translate('Occupants') => [$information->occupants, 'Occupant'],
        ] as $heading => [$relations, $relationship]) {
            if ($relations === []) {
                continue;
            }
            $html .= $this->personRelationsHtml($heading, $relations, $information->people, $assignmentUrl, $associatedPersonKeys, $information->provider, $relationship);
        }
        return $html;
    }

    public function crossReferenceHtml(ExternalInformation $information, array $identifiers, ?string $assignmentUrl = null): string
    {
        $html = '';
        foreach ($information->references as $provider => $values) {
            foreach ($values as $value) {
                $displayValue = $value;
                $url = null;
                if ($provider === 'genwiki' && preg_match('~[?&]curid=([1-9][0-9]{0,11})~i', $value, $match) === 1) {
                    $displayValue = $match[1];
                    $url = 'https://wiki.genealogy.net/?curid=' . $displayValue;
                }
                $comparisonValue = $provider === 'genwiki' ? $displayValue : $value;
                $referenceIdentifier = (new ExternalProviderRegistry())->byKey($provider)?->identifier($comparisonValue);
                if ($referenceIdentifier !== null) { $url ??= $referenceIdentifier->url; }
                $matching = false;
                foreach ($identifiers as $identifier) {
                    if ($identifier->provider === $provider && $identifier->value === $comparisonValue) {
                        $matching = true;
                        break;
                    }
                }
                if ($matching && !$this->showConsistentReferences()) {
                    continue;
                }
                $providerLabel = ['wikidata' => 'Wikidata', 'factgrid' => 'FactGrid', 'gov' => 'GOV', 'geonames' => 'GeoNames', 'genwiki' => 'GenWiki'][$provider] ?? $provider;
                $html .= '<br><span class="small">' . e(I18N::translate('Reference to %s', $providerLabel)) . ': '
                    . ($url === null ? e($displayValue) : '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($displayValue) . '</a>')
                    . ' — <span class="' . ($matching ? 'text-success' : 'text-warning') . '">' . e($matching ? I18N::translate('consistent') : I18N::translate('not present in this shared place')) . '</span>';
                $html .= '</span>';
                if (!$matching && $assignmentUrl !== null) {
                    $html .= ' <form method="post" action="' . e($assignmentUrl) . '" class="d-inline ms-1">'
                        . csrf_field()
                        . '<input type="hidden" name="operation" value="add-external-id">'
                        . '<input type="hidden" name="provider" value="' . e($provider) . '">'
                        . '<input type="hidden" name="external_id" value="' . e($value) . '">'
                        . '<button class="btn btn-sm btn-outline-primary py-0" type="submit">' . e(I18N::translate('Assign')) . '</button></form>';
                }
            }
        }
        return $html;
    }

    public function personRelationsHtml(string $heading, array $relations, array $people, ?string $assignmentUrl = null, array $associatedPersonKeys = [], string $provider = '', ?string $relationship = null): string
    {
        if ($relations === []) {
            return '';
        }

        $rows = '';
        foreach ($relations as $relation) {
            $personId = property_exists($relation, 'qid') ? $relation->qid : $relation->id;
            $person = $people[$personId] ?? null;
            $label = is_object($person) && property_exists($person, 'label') ? $person->label : $personId;
            $personProvider = is_object($person) && property_exists($person, 'provider') ? $person->provider : $provider;
            $personUrl = is_object($person) && property_exists($person, 'url') ? $person->url : null;
            if ($personUrl === null && is_object($person) && method_exists($person, 'entityUrl')) {
                $personUrl = $person->entityUrl();
            }
            if ($personUrl === null && $personProvider !== '') {
                $personUrl = (new ExternalProviderRegistry())->byKey($personProvider)?->identifier($personId)?->url;
            }
            $personLinks = '';
            $externalLinks = is_object($person) && property_exists($person, 'externalLinks') ? $person->externalLinks : [];
            foreach ($externalLinks as $linkLabel => $linkUrl) {
                $personLinks .= ' · <a href="' . e($linkUrl) . '" rel="noopener noreferrer" target="_blank">' . e($linkLabel) . '</a>';
            }
            $personName = $personUrl === null
                ? e($label)
                : '<a href="' . e($personUrl) . '" rel="noopener noreferrer" target="_blank">' . e($label) . '</a>';
            $key = $personProvider . ':' . $personId;
            $consistent = in_array($key, $associatedPersonKeys, true);
            if ($consistent && !$this->showConsistentReferences()) {
                continue;
            }
            $birthDate = is_object($person) && property_exists($person, 'birthDate') ? $person->birthDate : null;
            $deathDate = is_object($person) && property_exists($person, 'deathDate') ? $person->deathDate : null;
            $action = $consistent
                ? '<span class="text-success small">' . e(I18N::translate('consistent')) . '</span>'
                : '';
            if (!$consistent && $assignmentUrl !== null && in_array($relationship, ['Owner', 'Occupant'], true) && $personProvider !== '') {
                $action = '<form method="post" action="' . e($assignmentUrl) . '" class="d-inline">'
                    . csrf_field()
                    . '<input type="hidden" name="operation" value="add-person">'
                    . '<input type="hidden" name="person_provider" value="' . e($personProvider) . '">'
                    . '<input type="hidden" name="person_id" value="' . e($personId) . '">'
                    . '<input type="hidden" name="person_label" value="' . e($label) . '">'
                    . '<input type="hidden" name="person_birth" value="' . e((string) $birthDate) . '">'
                    . '<input type="hidden" name="person_death" value="' . e((string) $deathDate) . '">'
                    . '<input type="hidden" name="person_sex" value="' . e((string) (is_object($person) && property_exists($person, 'sex') ? $person->sex : '')) . '">'
                    . '<input type="hidden" name="person_wikidata" value="' . e($this->externalPersonId($externalLinks, 'wikidata')) . '">'
                    . '<input type="hidden" name="person_factgrid" value="' . e($this->externalPersonId($externalLinks, 'factgrid')) . '">'
                    . '<input type="hidden" name="person_wikitree" value="' . e($this->externalPersonId($externalLinks, 'wikitree')) . '">'
                    . '<input type="hidden" name="person_from" value="' . e((string) $relation->from) . '">'
                    . '<input type="hidden" name="person_until" value="' . e((string) $relation->until) . '">'
                    . '<input type="hidden" name="person_relationship" value="' . e((string) $relationship) . '">'
                    . '<button class="btn btn-sm btn-outline-primary py-0" type="submit">' . e(I18N::translate('Add person')) . '</button></form>';
            }
            $rows .= '<tr><td>' . $personName . ' <small>(' . e($personId) . ')</small>' . $personLinks . '</td>'
                . '<td>' . $this->displayWikidataDate($birthDate) . '</td><td>' . $this->displayWikidataDate($deathDate) . '</td>'
                . '<td>' . $this->displayWikidataDate($relation->from) . '</td><td>' . $this->displayWikidataDate($relation->until) . '</td><td>' . $action . '</td></tr>';
        }
        if ($rows === '') {
            return '';
        }
        $html = '<h5 class="mt-3">' . e($heading) . '</h5><div class="table-responsive"><table class="table table-sm"><thead><tr>'
            . '<th>' . e(MoreI18N::xlate('Name')) . '</th>'
            . '<th>' . e(MoreI18N::xlate('Birth')) . '</th><th>' . e(MoreI18N::xlate('Death')) . '</th>'
            . '<th>' . e(MoreI18N::xlate('From')) . '</th><th>' . e(I18N::translate('Until')) . '</th><th></th></tr></thead><tbody>';
        return $html . $rows . '</tbody></table></div>';
    }

    /** @param array<string,string> $externalLinks */
    private function externalPersonId(array $externalLinks, string $provider): string
    {
        $url = $externalLinks[match ($provider) {
            'wikidata' => 'Wikidata',
            'factgrid' => 'Factgrid',
            default => 'WikiTree',
        }] ?? '';
        $pattern = match ($provider) {
            'wikidata' => '~wikidata\\.org/(?:entity|wiki)/(Q[1-9][0-9]*)~i',
            'factgrid' => '~factgrid\\.de/entity/(Q[1-9][0-9]*)~i',
            default => '~wikitree\\.com/wiki/([^/?#]+)~i',
        };
        if (preg_match($pattern, $url, $match) === 1) {
            return rawurldecode($match[1]);
        }
        return '';
    }

    private function displayWikidataDate(?string $date): string
    {
        if ($date === null || preg_match('/^(\\d{4,})(?:-(\\d{2})(?:-(\\d{2}))?)?$/', $date, $parts) !== 1) {
            return '';
        }

        $gedcom = $parts[1];
        if (isset($parts[2])) {
            $months = ['01' => 'JAN', '02' => 'FEB', '03' => 'MAR', '04' => 'APR', '05' => 'MAY', '06' => 'JUN', '07' => 'JUL', '08' => 'AUG', '09' => 'SEP', '10' => 'OCT', '11' => 'NOV', '12' => 'DEC'];
            $gedcom = $months[$parts[2]] . ' ' . $parts[1];
            if (isset($parts[3])) {
                $gedcom = ltrim($parts[3], '0') . ' ' . $gedcom;
            }
        }

        return (new Date($gedcom))->display();
    }
}
