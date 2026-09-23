# Historical and structured addresses from Wikidata and FactGrid

This is a provider-neutral enrichment within the **External Places** module.
Wikidata and FactGrid expose different properties, but both are mapped to the
same display model and the same `_LOC:_ADDR` import workflow.

External Places displays historical addresses and, for an editor, offers an
explicit transfer into a registered `_LOC:_ADDR` structure. The transfer is
performed one row at a time and does not overwrite an existing identical
address. The provider, external identifier and source URL are retained as a
note below the imported address for provenance.

`_LOC:_ADDR` is the structured storage used by this module. `_LOC:NOTE` is not
used as an alternative address format: a note cannot reliably represent house
number, street, place and validity dates separately. A note is only used for
the provenance of an imported address.

The current custom structure uses these child tags:

| Tag | Meaning |
| --- | --- |
| `_HNO` | House number |
| `ADR1` | Street or free-text street address |
| `POST` | Postal code |
| `CITY` | Place/locality |
| `DATE` | Optional `FROM`, `TO` or `FROM ... TO ...` validity range |
| `NOTE` | Import provenance |

## When the table is shown

The **Addresses** table is shown only if Wikidata or FactGrid contains an
address statement or an address object:

### Wikidata

1. `P669` (located on street) is preferred; or
2. `P6375` (street address) is used only when no structured `P669` statement exists.

The qualifiers `P670` (house number), `P281` (postal code) and `P131`
(locality) are read from the same `P669` statement.

### FactGrid

1. `P208` (Address / Real estate) is followed to the linked address object.
2. The address object, or the place itself when no `P208` exists, is read with
   `P522` (street/square), `P152` (house number), `P47` (location), `P1121`
   (urban district) and `P153` (postal address).
3. `P153` is retained as free text. If it contains a postal code, the module
   also extracts that value for the structured table column.

Consequently, a grave, settlement, administrative area or other item without an address statement has no empty or speculative address section.

## Columns and qualifiers

For each `P669` statement, the module reads only qualifiers that belong to that same statement:

| Display column | Wikidata value |
| --- | --- |
| House number | `P670` |
| Street | main value of `P669` |
| Postal code | `P281` |
| Place | `P131` qualifier |
| From | `P580`, or `P585` when no start time exists |
| To | `P582` |

For FactGrid, the corresponding columns are read from the address item or the
place item:

| Display column | FactGrid value |
| --- | --- |
| House number | `P152` |
| Street | `P522` |
| Place | `P47` |
| Administrative area | `P1121` |
| Postal address | `P153` |

Undated rows represent a current or otherwise undated address and intentionally leave **From** and **To** empty. The module follows the provider's locality hierarchy (`P131` in Wikidata, `P47` in FactGrid) only to fill the place and administrative-area context; ancestor places are not treated as additional street addresses.

The transfer action is provider-neutral. Each row is offered separately and
the source provider, external identifier and URL are retained in the imported
provenance note. `P6375` and `P153` remain available as free text when no
structured street object is available.

## Consistency with the shared place

Before displaying an import action, the module reads the existing level-1
`_LOC:_ADDR` blocks and compares house number, street, postal code, place and
the optional start/end values. A matching provider row is marked **consistent**
and is never offered for a second import. If the administrator hides consistent
external information, matching address rows and their table are hidden as well.
Rows that differ in at least one of these fields remain visible and can be
accepted individually.

## Language and provenance

Street and place items are resolved using the webtrees display language with
the existing English fallback. Textual addresses retain the language supplied
by the provider. Each displayed row keeps its provider as provenance.
