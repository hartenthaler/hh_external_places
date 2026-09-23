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

Address data is normalized from the provider-specific models into one common
table. Wikidata uses `P669`/`P6375`; FactGrid uses `P208`, `P522`, `P152`,
`P47`, `P1121` and `P153`. See [Historical and structured addresses](HISTORICAL_ADDRESSES.md)
for the mapping and fallback rules.

## Display-only data

The following information is currently shown for review but is not written to
GEDCOM by this module:

* population histories and charts;
* descriptions, images, hierarchies and external cross-references;
* owners and occupants from Wikidata or FactGrid.

Owners and occupants are not automatically matched to existing webtrees
people and no new `INDI` record is created yet. The planned workflow for
creating people and linking them through `_LOC:ASSO` remains part of issue
[#33](https://github.com/hartenthaler/hh_external_places/issues/33).

No provider currently supplies an implemented place-event import. The open
follow-up issue [#174](https://github.com/hartenthaler/hh_external_places/issues/174)
tracks a future workflow for provider events and `_LOC:_EVEN`.

All write actions are restricted by the normal webtrees edit permissions and
protected by the module's CSRF-handled assignment action.
