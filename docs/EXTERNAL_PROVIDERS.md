# External providers and identifier consistency

The public name of this module is **External Places**. The technical module
identifier remains `hh_external_places` during the compatibility transition;
see [the naming proposal](RENAME_PROPOSAL.md).

The module uses one provider-neutral read model for public place information.
Each adapter has a fixed endpoint, identifier validator and reviewed property
mapping. GEDCOM values are never used as arbitrary URLs.

GeoNames is an optional provider. It uses the existing webtrees GeoNames
username, offers a multi-result name search and can be explicitly assigned
like the other providers. A selected GeoNames identifier is stored in GEDCOM
as a typed `_EXID` block. Provider enablement is site-wide. Nearby
searches use one global default radius. Only trees with an explicitly
different radius are stored and displayed as exceptions; entering the global
default for an exception removes that exception.

GeoNames `alternateNames` values returned by the service are shown as
language-labelled, deduplicated details. They are read-only contextual data;
the module never writes them to `_LOC:NAME` automatically.

Nominatim is also optional. It performs a read-only contextual lookup against
the public OpenStreetMap Nominatim API using the shared-place name. Results are
cached locally and include the returned object type and address hierarchy where
available. The provider is not used for systematic or automatic bulk queries;
it is only evaluated when the shared-place information is rendered. The public
service's [Nominatim usage policy](https://operations.osmfoundation.org/policies/nominatim/)
is binding: at most one request per second, an identifying User-Agent, visible
OpenStreetMap attribution, and no autocomplete or systematic downloads.

The lookup uses the public endpoint
`https://nominatim.openstreetmap.org/search` with `format=jsonv2`, address
details, name details and a single best result. The returned `type` and
address hierarchy are shown as contextual information; no Nominatim ID is
written to the GEDCOM record. If the response contains GeoJSON geometry, the
module renders it as a semi-transparent polygon on an interactive Leaflet map.
The map is loaded only for a result that includes geometry; an unavailable
map library does not affect the surrounding place information.

## Dedicated external-information page

The module provides a dedicated shared-place page at the **External
information** link. It uses the normal webtrees page layout, identifies the
place in its heading with a link back to the shared-place record, and contains
the complete provider output, consistency messages, population data and editor
actions in one place. The compact link in the Vesta Shared Places summary
remains for compatibility; the optional Vesta tab integration is registered
for both the webtrees 2.2 and 2.3 shared-place view variants.

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
for a FactGrid item ID, `P2503` for a GOV ID and `P1566` for a GeoNames ID.
FactGrid uses `P2` for type, `P189` for a Wikimedia Commons image, `P771`
for a Wikidata item ID, `P1073` for a GOV ID and `P418` for a GeoNames ID.
FactGrid's `wikidatawiki` sitelink is also accepted as a Wikidata reference.
These properties are configured per provider; equal property numbers must not
be assumed across Wikibase installations.

GOV is read through its public REST endpoint `/api/getObject?itemId={id}`;
`/api/data/{id}` is retained as a compatibility fallback. The module only uses
the fixed GOV host and a validated ID. The service is read-only and has a
bounded response size and timeout.

For a validated GOV identifier the module checks the MediaWiki API with the
stable namespace title `GOV:{id}` and follows redirects. A link supplied by GOV
is preferred when present; otherwise an existing redirected GenWiki article is
linked using its actual title. Missing articles are not displayed, and the API
result is cached.

GOV external-reference prefixes are described in
`resources/config/gov-external-identifiers.json`. Each entry contains a
translatable description and, where available, a URL template. The `{0}`
placeholder is replaced with the validated identifier value. Wikidata,
GeoNames and other provider references are additionally checked by the
shared consistency display; unrecognised prefixes remain visible without a
link.

GOV may provide political-geocoding identifiers from the German DCAT-AP.de
standard. They use the form `DCAT-AP.de:<key>/<value>`, for example
`DCAT-AP.de:stateKey/08` for Baden-Württemberg. The module links these values
to `http://dcat-ap.de/def/politicalGeocoding/<key>/<value>` and only accepts
the configured prefix and non-empty path value; it does not follow arbitrary
URLs supplied by a GOV record.

When GOV supplies historical population figures, the module normalizes them
into a year-indexed `population` object. The shared-place summary presents
these values in chronological order in a two-column table and a compact line
chart; no external data is written back to GOV.

GeoNames supplies a current population value, but its standard `getJSON`
response does not include a census or reference year. The module therefore
shows that value without inventing a year; historical population claims need
a source that provides dated observations.

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

Administrators may hide the informational messages for matching values. Missing
or conflicting values remain visible regardless of this setting.

An editor may add a missing value explicitly. The module writes only a
validated `_EXID`/`TYPE` block and updates the normal webtrees change stamp.

The administrator can maintain provider-specific type identifiers for four
optional hierarchy filters: house/farm, country, federation/international
organisation, and planet. The initial lists target the lowest inhabited-place
level; Wikidata and FactGrid use type QIDs, GOV uses numeric type IDs from its
official type vocabulary, and GeoNames uses feature codes. The new Wikidata
defaults include Q634 (planet), Q484652/Q1335818/Q170156 (federation or
international organisation), and Q6256/Q1048835/Q4835091 (country). The
corresponding FactGrid defaults are Q176135, Q1059807, and Q21925/Q221010.
The existing house defaults remain unchanged. The GOV defaults
are 8 (castle), 17 (building), 21 (manor), 24 (farm), 193 (alpine pasture),
229 (group of houses), 231 (farms), 236 (houses), 261 (farm hamlet), 111
(palace), 102 (forester's house), and 87 (mill). Labels are module strings
and can be translated independently of the provider IDs. Each provider has a
reset-to-default action. The editor activates one filter level per provider,
so an unfiltered search is always still available.

The initial Wikidata list includes Q23413, Q751876, Q3947, Q16560, Q41176,
Q44613, Q365627, Q1802963 and Q131596; administrators can extend or reduce it.

GeoNames building and inhabited-place filters use the `S` (spot/building/farm)
feature class. The initial codes and their English descriptions are maintained
in `resources/config/geonames-feature-codes.json`, based on GeoNames'
`featureCodes_en.txt`; the descriptions are passed through gettext so they can
be translated without duplicating provider identifiers. Nearby-search controls
remain disabled until the shared place has valid coordinates.

## Privacy and failures

The providers expose public research data and are not used to match or alter
webtrees persons. Every displayed value retains its provider label and link.
External failures do not block the shared-place page.

For Wikidata place relationships, displayed owners and occupants link to their
Wikidata item. If a person has a valid WikiTree identifier (`P2949`), including
Unicode names, the module also provides a WikiTree link. FactGrid place records
are read for place-level information and cross-references. Where present,
FactGrid owner and resident claims (`P126`/`P239`) are displayed as read-only
person tables.
