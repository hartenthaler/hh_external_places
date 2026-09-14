# GOV type catalogue

The module uses the public GOV type vocabulary to display readable names for
numeric GOV object types. The bundled snapshot is
`resources/config/gov-types.owl` and is based on:

<https://gov.genealogy.net/types.owl>

GOV publishes this vocabulary as public-domain data. The source URL is retained
in the snapshot and in this document so that the file can be refreshed without
losing its provenance. The snapshot was copied from the Vesta Gov4Webtrees
module; Vesta's source comment records a GOV update from November 2025.

The vocabulary contains the numbered object types as well as group resources.
Group resources are deliberately ignored by `GovTypeCatalog`: they describe
classifications such as settlement or administration and are not themselves
object-type identifiers.

`GovTypeCatalog` is the single lookup used by the GOV provider, the GOV type
editor, and the administrator's search-filter display. It reads the English or
German label (and other available language labels), preferring the requested
language and falling back to English, German, or any available label. Unknown
numeric IDs remain visible as numbers, so newer GOV types do not break the
module. The validator continues to compare the numeric `_GOVTYPE` value; the
catalogue supplies its human-readable representation.

`PlaceTypeFilterSettings` remains a separate concern. Its lists are only the
administrator-maintained search-filter defaults for the four hierarchy levels;
they are not intended to contain the complete GOV catalogue. For example, type
54 (`part of town` / `Stadtteil`) is available through the catalogue but is not
part of the house/farm filter defaults.

When the upstream vocabulary changes, replace the snapshot, verify the XML,
and check the affected labels and filters. No Vesta runtime dependency is
required for the catalogue.
