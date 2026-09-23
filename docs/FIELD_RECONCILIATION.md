# Field-level reconciliation

External Places compares provider values with the GEDCOM values of the shared
place one field at a time. A provider value is never written automatically.
Editors see one of these states:

* **consistent** – the normalized values agree;
* **inconsistent** – both values exist but differ;
* **missing** – the provider has a value and GEDCOM has none;
* **not available** – the provider does not expose that field.

The setting for consistent external information controls whether consistent
rows are shown. Missing and inconsistent values remain visible because they
may require a decision.

## Fields and actions

The current field actions are:

| Field | Provider data | GEDCOM action |
| --- | --- | --- |
| Name and language | GOV, GeoNames, Wikidata and other provider name lists | Add a missing language variant as `NAME` with `LANG` |
| Coordinates | Provider coordinates | Add one validated `MAP` point after distance comparison |
| Place type and period | GOV type ID, readable label and validity period | Add or extend the matching `TYPE`/`_GOVTYPE` block |
| Address | Wikidata, FactGrid and Nominatim | Add a structured `_LOC:_ADDR` row |
| Population | Historical provider observations | Add GEDCOM-L `_DMGD` with the value, `TYPE POPULATION`, optional `DATE` and a valid record-level provenance note |
| Image | Provider image URL | Create a linked `OBJE` media record with the external `FILE` URL |
| Related person | Wikidata or FactGrid owner/occupant | Create a new `INDI`, relationship event and `_LOC:_ASSO` link |

All actions are explicit, permission-checked and CSRF-protected. Imported
values retain the provider, identifier and source URL as provenance. Existing
values are not replaced silently. A population value/date pair and an image
URL are imported idempotently, so repeating an action does not create a second
copy.

## Dates

Uncertain provider dates remain uncertain. `ab 1992` is stored as `FROM 1992`,
`bis 1992` as `TO 1992`, and a plain year remains `1992`. GOV type periods use
the shared period rules: adjacent `bis 1972` and `ab 1972` ranges are allowed;
the same period extends an existing type block, while a different period gets
a new block.

## Deliberately display-only information

Descriptions, hierarchies, cross-provider references and provider-specific
metadata are currently shown for review only. Historical place events other
than demographic `_DMGD` observations remain outside the import workflow and
are tracked separately in Issue #174. Matching existing people and merging them
with newly discovered provider people is also intentionally deferred.
