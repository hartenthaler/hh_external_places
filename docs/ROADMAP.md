# Roadmap

## 0.1 – Foundation / Read-only ✅

Completed: establish the external identifier model, robust read-only Wikidata enrichment, cache, language fallback and the shared-place information panel.

## 0.2 – Assignment ✅
Completed: editors can search Wikidata from a Vesta `_LOC` record, review candidates, assign, replace, or remove a typed Wikidata identifier. The workflow works with and without Pretty URLs, preserves unrelated external identifiers, and updates the shared place’s change record.

## 0.3 – Nearby Discovery ✅
Completed: editors can search up to 20 Wikidata items around a shared place with GEDCOM coordinates. Results are ranked by name similarity and distance, never linked automatically, and use a global radius with optional per-tree exceptions.

## 0.4 – Domus Integration ✅
Completed: shared places offer a read-only Domus link through a replaceable provider. Linked QIDs use Domus' documented map deep link; the safe fallback is the map start page. The module does not embed or synchronize Domus.

## 0.5 – Extended Metadata ✅

Completed: show historical address data plus public Wikidata owners and occupants, with dates and provenance. The module remains read-only: it does not resolve people against the family tree or import external data into GEDCOM.

## Version 2 – Planned reconciliation and extended research

Version 2 now starts with a dedicated bug-fixing and quality phase before
larger research features. The open Version 2 issues are:

### Bug fixing and quality

- #99 – make Nominatim reliably visible when enabled;
- #98 – fix shared-place type assignment and temporal consistency checks;
- #93 – expand the GOV type catalogue and replace remaining codes with labels;
- #92 – fix missing or malformed GOV population tables and charts; and
- #97 – clarify house/farm classification and historical date ranges.

### Provider data and reconciliation

- #83 – compare provider coordinates and offer validated import;
- #88 – show and reconcile GOV alternate names;
- #87 – show a filtered GOV object timeline;
- #91 – suggest only filters matching the shared-place classification; and
- #33 – reconcile provider data with the family tree.

### Further research and presentation

- #25 – display subobjects;
- #37 – show relevant external objects as an optional map layer; and
- #73 – document the module's focus on houses and farms.

The deferred backlog also contains #48 (OpenRouteService) and #49
(OpenHistoricalMap); these remain outside the current Version 2 scope until
their practical benefit and provider availability are clearer. No automatic
GEDCOM changes are planned. The naming rationale is documented in
[Module and repository naming proposal](RENAME_PROPOSAL.md).
