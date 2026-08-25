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

Future work is collected in the Version 2 milestone. It includes richer
FactGrid/GOV metadata, subobjects, type-specific nearby-search behaviour,
investigation of the Vesta scrollbar behaviour, and an editor-controlled
comparison of public Wikidata/FactGrid/GOV data with local family-tree data.
No automatic GEDCOM changes are planned. The naming rationale is documented
in [Module and repository naming proposal](RENAME_PROPOSAL.md).
