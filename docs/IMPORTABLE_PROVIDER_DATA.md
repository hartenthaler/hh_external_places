# Provider data offered for transfer

External Places is read-only by default. An editor must explicitly confirm
each offered action. The module never silently overwrites GEDCOM data and
keeps the provider, external identifier and source URL as provenance where a
value is imported.

## Currently available

| Provider value | Destination in the shared-place GEDCOM record | How it is offered |
| --- | --- | --- |
| External provider identifier | Typed `EXID`/`_EXID`; GOV may use the provider-specific `_GOV` tag | Assign an explicitly selected search result |
| Coordinates | `MAP` with `LATI` and `LONG` | **Add coordinates** after comparison with the existing point |
| Place name and language | `1 NAME` and optional `2 LANG` | **Add place name** for a missing or reviewed language variant |
| GOV place type and validity period | Matching `1 TYPE` block with `_GOVTYPE` | **Add GOV place type** when the type is missing or needs review |
| Address | `_LOC:_ADDR` with `_HNO`, `ADR1`, `POST`, `CITY`, optional `DATE` and provenance `NOTE` | **Add address** for one selected Wikidata or FactGrid row |
| Owner or occupant | New `INDI` with `NAME`/`GIVN`/`SURN`, `SEX` (`M`, `F`, `X` or unknown `U`), provider/WikiTree `EXID` values, a `PROP` (owner) or `RESI` (occupant) event, and `_LOC:_ASSO` plus provenance `NOTE` | **Add person** for one selected Wikidata or FactGrid relationship |

Address data is normalized from the provider-specific models into one common
table. Wikidata uses `P669`/`P6375`; FactGrid uses `P208`, `P522`, `P152`,
`P47`, `P1121` and `P153`. See [Historical and structured addresses](HISTORICAL_ADDRESSES.md)
for the mapping and fallback rules.

## Display-only data

The following information is currently shown for review but is not written to
GEDCOM by this module:

* population histories and charts;
* descriptions, images, hierarchies and external cross-references;
* owners and occupants from Wikidata or FactGrid, unless an editor explicitly
  selects **Add person**.

The **Add person** action creates a new `INDI` record. It does not search for,
modify or merge an existing person. The new record receives the available
Wikidata/FactGrid and WikiTree `EXID` values, a canonical GEDCOM name with
given-name and surname subtags, a sex value (`U` when the provider has no
usable value), a `PROP` or `RESI` event with the shared-place name and
relationship period, and the shared place receives a registered `_LOC:_ASSO`
link with the relationship and provenance note.

No provider currently supplies an implemented place-event import. The open
follow-up issue [#174](https://github.com/hartenthaler/hh_external_places/issues/174)
tracks a future workflow for provider events and `_LOC:_EVEN`.

All write actions are restricted by the normal webtrees edit permissions and
protected by the module's CSRF-handled assignment action.
