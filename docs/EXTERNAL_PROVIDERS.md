# External providers and identifier consistency

The public name of this module is **External Places**. The technical module
identifier remains `hh_external_places` during the compatibility transition;
see [the naming proposal](RENAME_PROPOSAL.md).

The module uses one provider-neutral read model for public place information.
Each adapter has a fixed endpoint, identifier validator and reviewed property
mapping. GEDCOM values are never used as arbitrary URLs.

## Supported identifiers

```gedcom
1 _EXID Q123456
2 TYPE https://www.wikidata.org/entity/
1 _EXID Q654321
2 TYPE https://database.factgrid.de/entity/
1 _GOV SCHERGJO54EJ
```

Wikidata and FactGrid item IDs must be canonical `Q` IDs. GOV IDs are checked
against the GOV identifier character set. `_GOV` is the preferred storage for
GOV and takes precedence over a GOV `_EXID`. Exactly one GOV value is allowed;
multiple or conflicting values produce an error flash message and no GOV value
is used. Unknown authorities are preserved, but are not fetched by this module.

## Provider mappings

Wikidata uses `P31` for type, `P18` for a Wikimedia Commons image, `P8168`
for a FactGrid item ID and `P2503` for a GOV ID. FactGrid uses `P2` for type,
`P189` for a Wikimedia Commons image, `P771` for a Wikidata item ID and `P1073` for a GOV ID. These
properties are configured per provider; equal property numbers must not be
assumed across Wikibase installations.

GOV is read through its public REST endpoint `/api/getObject?itemId={id}`;
`/api/data/{id}` is retained as a compatibility fallback. The module only uses
the fixed GOV host and a validated ID. The service is read-only and has a
bounded response size and timeout.

## HTTP transport

External requests use the module's transport boundary. On webtrees 2.3 it
uses the PSR-18 client supplied by webtrees; on older installations it falls
back to Guzzle when that client is available. Provider code therefore does not
depend on a particular HTTP implementation. TLS certificate verification is
always enabled and must be correctly configured in the PHP environment.

## Consistency display

The module compares the shared place's local `_EXID` blocks with reviewed
cross-provider properties in fetched external items. Matching values are shown
as consistent. A referenced value that is not yet present in the shared place
is shown as missing; it is not silently imported.

An editor may add a missing value explicitly. The module writes only a
validated `_EXID`/`TYPE` block and updates the normal webtrees change stamp.

## Privacy and failures

The providers expose public research data and are not used to match or alter
webtrees persons. Every displayed value retains its provider label and link.
External failures do not block the shared-place page.

For Wikidata place relationships, displayed owners and occupants link to their
Wikidata item. If a person has a valid WikiTree identifier (`P2924`), the
module also provides a WikiTree link. FactGrid place records are currently
read for place-level information and cross-references; owner/occupant
relationships from FactGrid are not yet part of the read model.
