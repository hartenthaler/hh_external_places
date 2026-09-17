# External providers and identifier consistency

The public name of this module is **External Places**. The technical module
identifier remains `hh_external_places` during the compatibility transition;
see [the naming proposal](RENAME_PROPOSAL.md).

The module uses one provider-neutral read model for public place information.
Each adapter has a fixed endpoint, identifier validator and reviewed property
mapping. GEDCOM values are never used as arbitrary URLs.

Language values used for place-name comparison are normalized centrally. The
comparison key is lower-case ISO 639-1 (`de`, `en`, ...). GEDCOM language names
such as `GERMAN` and ISO 639-2 terminological or bibliographic codes such as
`deu` and `ger` therefore compare as `de`; unknown valid two- or three-letter
codes are retained rather than discarded. This same normalizer is used by
GeoNames, GOV and the Wikibase clients.

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

GOV place names are shown in the same provider-neutral detail area as
`Alternate name (xx)`, where `xx` is the language code supplied by GOV. If GOV
supplies a validity period, it is shown next to the name as read-only context
(`Period`, or `From`/`Until`); GOV `timespan` Julian-day bounds are converted
to readable ISO dates while retaining the provider's precision. The comparison key is the normalized ISO 639-1
language code, so GEDCOM `2 LANG GERMAN`, GOV `deu` and GeoNames `de` are
compared as the same language. A matching name is marked consistent when the
administrator has enabled confirmations; a differing name remains visible as
inconsistent. If a language-specific name is missing from `_LOC`, an editor
can use **Add place name** to write the validated name and its language as a
new `1 NAME`/`2 LANG` pair. The provider's validity period is not invented as
GEDCOM data because `_LOC:NAME` has no portable period substructure.

For a GeoNames record, the optional parent hierarchy is requested in the
current webtrees display language (with English as fallback). It is shown
directly after the place name, from the broadest parent to the selected place.
Each hierarchy item is linked to its GeoNames record and uses a translated
type label on mouse-over. The remaining details follow in a stable order:
administrative area, region, country, feature, elevation, population and
alternate names.

Nominatim is also optional. It performs a read-only contextual lookup against
the public OpenStreetMap Nominatim API using the shared-place name. Results are
cached locally and include the returned object type and address hierarchy where
available. The provider is not used for systematic or automatic bulk queries;
it is only evaluated when the shared-place information is rendered. The public
service's [Nominatim usage policy](https://operations.osmfoundation.org/policies/nominatim/)
is binding: at most one request per second, an identifying User-Agent, visible
OpenStreetMap attribution, and no autocomplete or systematic downloads.

For address records, the query starts with the first GEDCOM `NAME` and adds
the first local component from the complete place hierarchy (for example
`Klosterstraße 3, Ennetach`). This keeps common street names tied to their
locality without sending the full hierarchy to the geocoder. The query then
removes ISO country codes and the synthetic `Earth` level. Where available, the GEDCOM place type
is used as a preference for house/building, locality, city, county or state.
For country, federation, continent and Earth records no Nominatim or Photon
lookup is performed; the empty-query diagnostic remains available for testing.

The lookup uses the public endpoint
`https://nominatim.openstreetmap.org/search` with `format=jsonv2`, address
details, name details and a single best result. The returned `type` and
address hierarchy are shown as contextual information; no Nominatim ID is
written to the GEDCOM record. If the response contains GeoJSON geometry, the
module renders it as a semi-transparent polygon on an interactive Leaflet map.
The map is loaded only for a result that includes geometry; an unavailable
map library does not affect the surrounding place information.

GenWiki is available as a searchable, read-only MediaWiki provider. Editors
can search the public GenWiki API by place name and assign the selected
numeric page ID as a typed `_EXID` (`https://wiki.genealogy.net/?curid={id}`).
For an assigned page the module loads only the article title and introductory
plain-text extract; it does not execute or embed arbitrary wiki markup. Search
results and extracts are cached, requests use a bounded response size and
timeout, and failures never block the shared-place page. Existing GenWiki
links discovered through GOV or Wikidata are de-duplicated against assigned
GenWiki page IDs.

## Dedicated external-information page

The module provides a dedicated shared-place page at the **External
information** link. It uses the normal webtrees page layout, identifies the
place in its heading with a link back to the shared-place record, and contains
the complete provider output, consistency messages, population data and editor
actions in one place. The compact link in the Vesta Shared Places summary
remains for compatibility; the optional Vesta tab integration is registered
for both the webtrees 2.2 and 2.3 shared-place view variants.

The provider output is assembled by the read-only
`ExternalInformationRenderer`; the module class coordinates provider access and
passes validated values to this presentation boundary.

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

Wikidata and FactGrid entity responses use one shared, provider-aware database
cache (`WikibaseCacheRepository`). The cache key consists of the provider,
Q-ID and normalized display language, and all consumers store the same raw
`wbgetentities` payload. This prevents the information page and assignment
page from showing different snapshots of the same item. The cache is
versioned; when the payload shape or requested property set changes, the
module invalidates incompatible entries during boot. The file cache remains
reserved for the other providers and is not used for Wikibase entities.

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
into a date-labelled `population` object. The shared-place summary presents
these values in chronological order in a two-column table and a compact line
chart; GOV's `beginYear` and `endYear` bounds are retained as `ab` and `bis`
observations. An imprecise year represents an unknown day within that year;
for chart positioning, `bis YYYY` is placed at 1 January and `ab YYYY` at
31 December. A year without a qualifier is placed at 1 July, while a month
without a qualifier uses its approximate midpoint. The same boundary rule uses the first and last
day for an imprecise month.
No external data is written back to GOV.

GeoNames supplies a current population value, but its standard `getJSON`
response does not include a census or reference year. The module therefore
shows that value without inventing a year; historical population claims need
a source that provides dated observations.

GeoNames searches use the provider's place-name `name` parameter. This also
includes names derived from alternate names and does not exclude
administrative objects; the optional hierarchy filters are applied to the
returned feature codes afterwards.

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

The administrator can maintain provider-specific type identifiers for the
provider-neutral hierarchy filters house, locality, municipality, county,
state, country, federation/international organisation, and planet. The
initial lists target common levels; Wikidata and FactGrid use type QIDs, GOV
uses numeric type IDs from its official type vocabulary, and GeoNames uses
feature codes. The Wikidata defaults include Q634 (planet),
Q484652/Q1335818/Q170156 (federation or international organisation),
Q6256/Q1048835/Q4835091 (country), Q107390 (state), Q28575/Q106658 (county),
Q484170 (municipality), and Q486972/Q532/Q3957/Q515 (locality). The
corresponding FactGrid defaults remain available where stable mappings are
known. The existing house defaults remain unchanged. The GOV defaults
are 8 (castle), 17 (building), 21 (manor), 24 (farm), 193 (alpine pasture),
229 (group of houses), 231 (farms), 236 (houses), 261 (farm hamlet), 111
(palace), 102 (forester's house), and 87 (mill). Labels are module strings
and are resolved independently of the provider IDs. Each provider has a
reset-to-default action. The editor activates one filter level per provider,
so an unfiltered search is always still available. The assignment page suggests
only the filter matching an unambiguous shared-place classification. The central
classifier uses the GEDCOM `TYPE` and, where present, the numeric `2 _GOVTYPE`
value (`7` for a German federal state, `71` for a federation, and `72`/`130`
for a country). If the type is missing, unknown or contradictory, all filter
buttons remain available as a safe fallback.

The complete GOV vocabulary is maintained separately from these defaults. It
is bundled as `resources/config/gov-types.owl` and resolved by the central
`GovTypeCatalog`; see [GOV_TYPE_CATALOG.md](GOV_TYPE_CATALOG.md) for its source,
language fallback, and update procedure. This keeps all GOV labels consistent
in provider output, type validation, and the administration interface.

The initial Wikidata house list includes Q23413, Q751876, Q3947, Q16560, Q41176,
Q44613, Q365627, Q1802963 and Q131596; administrators can extend or reduce it.

GeoNames building and inhabited-place filters use the `S` (spot/building/farm)
feature class. The initial codes and their English descriptions are maintained
in `resources/config/geonames-feature-codes.json`, based on GeoNames'
`featureCodes_en.txt`; the descriptions are passed through gettext so they can
be translated without duplicating provider identifiers. Nearby-search controls
remain disabled until the shared place has valid coordinates.

## Coordinates and consistency tolerances

Coordinates from GEDCOM and external providers are normalised to WGS84 and
compared with provider-neutral great-circle calculations. The accepted input
formats, hierarchy classification, tolerances and explicit coordinate-import
rules are documented in [Coordinates and consistency](COORDINATES.md).

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
