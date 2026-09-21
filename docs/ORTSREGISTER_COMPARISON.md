# Relationship with `webtrees-ortsregister`

This comparison records the current boundary between **External Places** and [thobgg/webtrees-ortsregister](https://github.com/thobgg/webtrees-ortsregister). It is based on the public module documentation and the provider interfaces in this repository.

## webtrees-ortsregister

webtrees-ortsregister is a place-centred archive and maintenance module. Its focus is a visual landing page for each place, media and research files, hierarchy navigation, maps, place merge/rename operations and optional _LOC curation. It also provides GOV lookup, GOV hierarchy information, external references and GenWiki links. The module can write selected place data to the tree when an administrator explicitly enables those operations.

## External Places

External Places is a provider-neutral, read-only information layer for an existing Vesta Shared Places _LOC record. Its focus is typed external identifiers and provider data from Wikidata, FactGrid and GOV, including provider-specific search and nearby search, cross-reference checks and explicit editor actions for assigning or removing an identifier. It does not create a place archive, rewrite PLAC values, merge places or synchronize external data into GEDCOM.

## Coexistence and boundary

The modules are complementary and can run together:

| Concern | Responsible module |
| --- | --- |
| Place archive, media, research files and landing page | webtrees-ortsregister |
| Place hierarchy, merge/rename and optional _LOC curation | webtrees-ortsregister |
| Read-only Wikidata, FactGrid and GOV enrichment | External Places |
| Provider identifier assignment and cross-reference consistency | External Places |
| Core GEDCOM and Vesta Shared Places records | webtrees core / Vesta |

There is no hard dependency between the modules. Both may display GOV data for the same place; this is not a data conflict because External Places keeps its provider data read-only and does not claim ownership of the archive page. Administrators should avoid enabling two competing write workflows for the same _LOC field.

When Ortsregister is enabled, External Places detects the active module at
runtime and adds an **Im Ortsregister anzeigen** link for the current `_LOC`
record. The link uses the Ortsregister detail route
`/tree/{tree}/orte/{place_id}`, where `place_id` is the webtrees place ID for
the current shared place. If Ortsregister is disabled or unavailable, no link
is shown and External Places continues to work normally.

## Result

The comparison is complete for the current scope. Future work may refine the visual integration and avoid duplicate GOV panels, but that is an optional coexistence enhancement rather than a prerequisite for the provider support.
