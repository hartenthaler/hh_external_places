# Coordinates and consistency

External Places uses one provider-neutral coordinate model for shared places
and external records. The implementation is the immutable
`src/Geo/Coordinates.php` value object.

## Accepted formats

Coordinates are validated as WGS84 latitude/longitude. The parser accepts:

* signed decimal values (`48.05299`, `9.32258`);
* compass prefixes or suffixes (`N48.05299`, `E9.32258`, `W9.32258`);
* the German east direction `O` (`O9.32258`); and
* degree/minute/second notation.

GEDCOM coordinates are read from the first `MAP` block containing `LATI` and
`LONG`. Wikibase `Point(longitude latitude)` and compact `@latitude/longitude`
values are also supported. Invalid or out-of-range values are rejected.

## Distances and nearby searches

`Coordinates::distanceTo()` uses the great-circle (Haversine) distance. The
same function is used for Wikidata and FactGrid nearby candidates and for
provider-consistency checks. Provider adapters do not maintain their own
distance formula.

For bounding-box APIs, `Coordinates::boundingBox()` creates an approximate
WGS84 box around the point. It only narrows the remote query; returned
candidates are still checked with the exact great-circle distance. This is
used by the GOV and FactGrid nearby searches.

Nominatim/Photon geometry is deliberately kept as provider-specific geometry:
it can contain polygons, extents and OSM node/way/relation data rather than a
single point. Such geometry is used for the map and is not a second distance
implementation.

## Hierarchy levels and tolerances

The module classifies a shared place into one of the levels used by issue #83:

| Level | Typical records | Default tolerance |
| --- | --- | ---: |
| House/farm | house, farm, building, castle, palace | 5 m |
| Locality | village, town, city, locality | 5 km |
| Municipality | municipality, municipal association | 20 km |
| County/district | county, district, Landkreis, Regierungsbezirk | 50 km |
| State | state, Bundesland, first-order administrative division | 100 km |
| Country | country, Staat, Land | 200 km |
| Federation | federation, union, international organisation | 500 km |
| Planet | Earth, planet, world | no coordinates |

The GEDCOM `TYPE` text is used for this classification. Counties are kept as
their own level so that Nominatim can still be queried for them; coordinate
comparison uses the conservative country/state tolerance. If the type is
missing or ambiguous, geocoder filtering keeps the level as unknown, while
coordinate comparison uses the same conservative tolerance. The same hierarchy
value is also available to the provider search filters, so issue #91 can use
one classification instead of reimplementing it.

Administrators can change the three numeric tolerances in the module settings.
Values are stored internally in metres and shown in a suitable unit. Planetary
records have no coordinate tolerance.

When an external provider has a validated coordinate but the shared place has
none, an editor is offered **Add coordinates**. The action writes a GEDCOM
`MAP` block with `LATI` and `LONG`; it never overwrites an existing coordinate
block. Consistency messages can be hidden for matching values, while missing
or inconsistent values remain visible.
