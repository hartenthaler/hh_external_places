# Changelog

All notable user-facing changes are documented here.

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

## Unreleased

- GOV population history is now shown as a year-sorted table with a compact
  line chart; the population label is translated.
- GOV cross-references to Wikidata and GeoNames are shown through the common
  consistency display; additional GND and LEO-BW identifiers are translated
  and linked directly.
- GOV external-reference prefixes are maintained in a configuration file so
  additional identifiers can be displayed consistently and translated.
- Version 2 will add optional research workflows without automatic GEDCOM changes.

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
