# Changelog

## Unreleased

## 2.2.6.13 - 2026-09-17

- Fixed a PHP error that could prevent the external-information page from
  opening for shared places without a Wikidata assignment.
- The README contents links now work reliably, and GeoNames third-order
  administrative divisions are offered under the county/district filter.
- Administrators can reset all provider filter lists and hierarchy levels to
  their bundled defaults with one action.

## 2.2.6.12 - 2026-09-17

- Wikidata and FactGrid entity responses now share one provider-aware,
  language-specific cache, so the information and assignment pages use the
  same current snapshot (Issue #132).
- Wikidata and FactGrid property mappings are maintained in one bundled
  configuration catalogue instead of duplicated inline code (Issue #133).
- Coordinates from GEDCOM, Wikidata, FactGrid, GOV and GeoNames now use one
  shared WGS84 value object with support for compass directions, German `Ost`
  notation and distance calculation (Issue #83).
- Administrators can configure hierarchy-specific coordinate tolerances: 5 m
  for buildings, 200 km for states/countries and 500 km for federations;
  planetary records do not use coordinates.
- Editors see coordinate consistency results and can explicitly import a
  validated provider coordinate when the shared place has no coordinates.
- Nominatim address lookups now combine the first GEDCOM name with the first
  local place context (for example `Klosterstraße 3, Ennetach`) and use new
  cache keys so stale, ambiguous street results are not reused.
- Provider output rendering is now isolated in `ExternalInformationRenderer`,
  keeping the module lifecycle and Vesta integration separate from HTML/SVG
  generation (Issue #134).
- Places without an explicit hierarchy type are no longer misclassified as
  countries, so local places such as villages remain eligible for Nominatim.
- Coordinate comparisons for such unknown place types now use the conservative
  country/state tolerance instead of hiding provider distances and consistency
  results.
- Photon fallback retries once without a restrictive type layer when that layer
  returns no candidates, and stale fallback cache entries are invalidated.
- Counties such as Landkreis Sigmaringen are no longer treated as countries
  for Nominatim suppression and can therefore receive their own map block.
- The central hierarchy now distinguishes locality, municipality, county and
  state between house/building and country. Provider filters, Nominatim layer
  selection and coordinate tolerances use the same hierarchy (Issue #131,
  merged in PR #139).
- Added Wikidata Q106658 (`district of Germany`) for county filtering and
  GeoNames A.ADM3 for municipality filtering.
- The assignment page now suggests only the filter matching an unambiguous
  shared-place hierarchy; missing or contradictory type information keeps all
  filters available (Issue #91).
- GOV alternate place names now retain their language codes and validity
  periods, are compared with shared-place `NAME`/`LANG` pairs, and can be
  added explicitly by an editor (Issue #88).

## 2.2.6.10 - 2026-09-14

- Nominatim searches now retain useful locality context while removing country
  codes and the synthetic Earth level.
- Search results can prefer the known place level, such as house, city, county
  or state, reducing ambiguous matches.
- Photon is used as a cached fallback when Nominatim is temporarily unavailable;
  unsuitable map features are filtered out.
- Exact OSM geometry is loaded for Photon ways and relations when available, so
  buildings and administrative areas can be shown with their real outlines.
- OSM links, map extents and provider diagnostics were improved for reliable
  troubleshooting and safer fallback behavior.
- GOV alternate names are displayed with their language codes and can be
  compared with shared-place names (Issue #109).
- GenWiki is available as a searchable provider. Editors can assign a GenWiki
  page ID and view its title and introductory paragraph; responses are cached
  and existing GenWiki references are de-duplicated (Issue #110).

## 2.2.6.11 - 2026-09-15

- GOV population observations using the API's `beginYear` and `endYear`
  fields are now shown in the population table and chart with their temporal
  precision and correct `bis`/`ab` ordering (Issue #92).

- GOV type 54 is now shown as the translated human-readable label “Part of
  town” (German: “Stadtteil”) instead of a numeric code (Issue #93).

- Language codes from GEDCOM, GOV, GeoNames and Wikibase are now normalized
  centrally for reliable place-name comparisons (Issue #121).

## 2.2.6.9 - 2026-09-12

- GenWiki links can be resolved for GOV identifiers through the MediaWiki API.
- Wikidata's P14871 GenWiki article identifier is read and shown as a link;
  existing GenWiki links are checked for consistency and are not duplicated
  (Issue #100).
- GOV DCAT-AP.de political-geocoding identifiers are recognized and linked
  safely (Issue #94).
- GeoNames searches include alternate names and administrative objects, so
  searches such as “Deutschland” and “European Union” return the actual
  GeoNames records (Issue #96).
- GeoNames parent hierarchies are requested in the user's language and shown
  directly after the place name; provider details use a stable, readable
  order (Issue #85).
- Added GeoNames `A.ZN` as the default federation filter type for zones such as
  the European Union.

## 2.2.6.8 - 2026-08-31

- GeoNames alternate names are now shown with their language codes, sorted and
  deduplicated.
- Editors can compare alternate names with the shared-place names and add a
  missing name together with its language.
- Added the missing Wikidata default filter for sovereign states.

- Added the requested GOV, Wikidata and house-level default filter types.
- GOV population histories no longer interpret list positions as years; “from”
  and “until” values are retained as separate dated observations.
- Nominatim settlement searches now prefer settlement results for ambiguous
  bare place names while preserving building results for address searches.
- Wikibase filters now accept both object and string representations of type
  claims returned by compatible API versions.
- Newly added GOV place types receive a readable TYPE value instead of the
  technical placeholder `place`.

## 2.2.6.7 - 2026-08-29

- GOV identifiers now also accept valid legacy IDs with lower-case prefixes,
  such as `object_1192115`.
- Improved the settings wording and provider presentation.
- GOV population entries with “from” and “until” dates are retained as
  separate historical values.

## 2.2.6.6 - 2026-08-29

- Search filters can now distinguish four levels: house/farm, country,
  federation or international organisation, and planet.
- Administrators can edit the provider-specific type lists in an accordion;
  each provider and level can be reset to its defaults.
- Editors can apply any of these filters to normal and nearby searches while
  leaving the unfiltered search available.
- Added the requested Wikidata and FactGrid defaults for planet, federation
  and country searches.

## 2.2.6.5 - 2026-08-29

- Nominatim/OpenStreetMap can now optionally enrich shared places with the
  object type and compact address hierarchy.
- When Nominatim provides a building or area polygon, it is shown on an
  interactive map with a transparent overlay.
- Nominatim requests use local caching and the public service's one-request-
  per-second policy; temporary request failures no longer produce a technical
  diagnostic block in the place display.
- Added German translations for the Nominatim address labels “Municipality”
  (Gemeinde) and “County” (Landkreis).
- Administrators can hide confirmations for external identifiers that are
  already consistent across providers. Missing or conflicting identifiers are
  still reported.
- Provider searches can be filtered for house/building types, with editable
  provider-specific defaults and a reset action.
- GOV population history is shown chronologically in a table with a compact
  line chart; additional GOV references are linked where possible.

All notable user-facing changes are documented here.

## 2.2.6.4 - 2026-08-26

- Fixed a serious runtime error that prevented GOV external place information
  from being displayed.
- Numeric GOV type identifiers are now shown with readable, translatable names.

## 2.2.6.2 - 2026-08-25

- Added FactGrid, GOV and GeoNames as selectable external place providers.
- Editors can search providers, search nearby where supported, and assign or
  remove validated external identifiers from a shared place.
- Shared-place summaries now show provider cross-references and indicate when
  identifiers agree across Wikidata, FactGrid, GOV and GeoNames.
- Added read-only FactGrid place data, including addresses, owners,
  occupants and public cross-references.
- Added WikiTree links for external people when Wikidata provides a WikiTree
  identifier, including names with diacritics.
- Added administrator controls for enabled providers and a global nearby-search
  radius with optional family-tree exceptions.

- External searches now use webtrees' modern HTTP connection when available,
  while older webtrees installations continue to work through a fallback.
  This prepares the module for webtrees 2.3 and keeps secure TLS verification.

- GOV access now follows the same endpoint and request behavior as the Vesta
  GOV module, improving compatibility with the public GOV service.

- Provider results and external details are interpreted more reliably, including
  multilingual GOV names, external references and population data.

## 2.2.6.3 - 2026-08-26

- Nearby-search buttons now clearly indicate when coordinates are still
  missing, instead of inviting an action that cannot work.
- Administrators can reset each provider's house/building filter list to its
  defaults; the confirmation message now describes the actual reset action.
- FactGrid house filtering now starts with documented residential-building,
  real-estate, apartment and isolated-settlement types.
- Population values use the user's webtrees number formatting.
- Visitors can search and review results but cannot see or submit assignment
  actions; editors can assign missing cross-provider identifiers directly from
  the consistency message.

## 2.2.6.0 - 2026-08-19

### Added

- Initial project setup for webtrees 2.2 and Vesta Shared Places.
- Recognition and validation of Wikidata identifiers on shared places.
- Read-only Wikidata enrichment with local caching and language fallback.
- Public-source and image-attribution information.
- A protected search page where editors can review and assign a Wikidata item to a shared place.
- Explicit controls to remove an assigned Wikidata item.
- A visible assignment button even when a shared place has no Wikidata item yet.
- Updates to the shared-place change record when its Wikidata assignment changes.
- Detection of Wikidata redirects and merged items, with an editor-controlled replacement action.
- German translations for the displayed information and assignment interface.
- Automatic disclosure of Wikidata access to the optional Legal Notice module.
- Nearby discovery for shared places with coordinates, including distance display and transparent name/distance ranking.
- A configurable nearby-search radius for each family tree (default: 5 km).
- A direct, read-only **Show in Domus** link.  Linked Wikidata items open their Domus map entry; other shared places open the Domus map start page.
- More compact, accessible tables for normal and nearby Wikidata search results, with an icon link to the public Wikidata item.
- Read-only historical address tables when Wikidata provides structured or free-text address statements, including optional validity dates.
- Public Wikidata owners and occupants with external links, known life dates, and historical relationship dates.
