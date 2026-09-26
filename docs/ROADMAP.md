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

## Version 2 – Complete planned functionality

Version 2 is the completion target for the functionality planned for this
module. It is not limited to bug fixing: all core provider integrations,
reconciliation checks, explicit import actions, consistency handling and
supporting documentation must work together coherently before Version 2 is
considered complete. The remaining Version 2 work is tracked in these issues:

### Quality and provider consistency

- #97 – clarify house/farm classification and historical date ranges;
- #87 – show a filtered GOV object timeline;
- #142 – notify editors when a linked external record changes; and
- #146 – merge provider timelines into one chronological view.

### Provider data and presentation

- #25 – display subobjects;
- #37 – show relevant external objects as an optional map layer;
- #49 – add OpenHistoricalMap as a historical map layer with a time slider; and
- #191 – set a title when importing media objects.

### Documentation and release quality

- #171 – update the documentation screenshots.

Issues already completed in the 0.x milestones remain part of the Version 2
baseline; Version 2 closes only when the remaining core issues above provide a
consistent end-to-end workflow.

## Version 3 – Optional extensions

Version 3 contains optional additions beyond the planned Version 2 scope.
Their implementation is not guaranteed and depends on provider availability,
technical feasibility and the practical benefit for users. The current
optional backlog includes:

- #154 – add FamilySearch Places as an external information provider;
- #174 – investigate and import place-related events via `_LOC:EVEN`;
- #115 – expand the GenWiki provider with additional article information;
- #138 – add Deutsche Digitale Bibliothek sources with type/date filtering;
- #144 – group related `_LOC` records under one GOV parent;
- #145 – group external-information sections by provider; and
- #112 – add English screenshots and captions.
