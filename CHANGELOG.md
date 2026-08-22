# Changelog

All notable user-facing changes are documented here.

## Next release

- Use webtrees' PSR-18 HTTP client when available, with a compatibility
  fallback for older installations. This keeps external searches compatible
  with webtrees 2.3 while retaining secure TLS verification.

- Added shared read-only provider infrastructure for Wikidata, FactGrid and GOV,
  including cross-reference consistency indicators and explicit entry of missing
  external identifiers.

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
