# **webtrees** module: External Places

![webtrees major version](https://img.shields.io/badge/webtrees-v2.2%20%7C%20v2.3-green)
[![Module version](https://img.shields.io/badge/version-2.2.6.15-blue)](version.txt)
[![Downloads](https://img.shields.io/github/downloads/hartenthaler/hh_external_places/total?label=downloads)](https://github.com/hartenthaler/hh_external_places/releases)
[![License: GPL v3](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

External Places is a [webtrees](https://www.webtrees.net) module for enriching [Vesta Shared Places](https://github.com/vesta-webtrees-2-custom-modules/vesta_shared_places) (`_LOC`) with public information from external providers. It helps editors link shared places, compare public information and review details before explicitly transferring selected values into the shared-place record.

## 📚 Contents

* [Purpose](#purpose)
* [Domus](#domus)
* [Main features](#main-features)
* [Screenshots](#screenshots)
* [Privacy](#privacy)
* [Requirements](#requirements)
* [Installation](#installation)
* [Usage](#usage)
* [Documentation](#documentation)
* [Translation](#translation)
* [Credits](#credits)
* [License](#license)
<a id="purpose"></a>
## 🎯 Purpose

A shared place can represent a building, farm, church, cemetery, street, square, district, village or another real-world place.
The module stores validated external identifiers alongside the shared-place record and displays provider-specific public information such as
names, descriptions, types, images, addresses, relationships, external references and population data where available.

### Providers

The module currently supports these optional providers:

* [Wikidata](https://www.wikidata.org)
* [FactGrid](https://database.factgrid.de)
* [GOV](https://gov.genealogy.net)
* [GeoNames](https://www.geonames.org)
* [GenWiki](https://wiki.genealogy.net)
* [Nominatim/OpenStreetMap](https://nominatim.openstreetmap.org) for contextual map information

See [External providers and identifier consistency](docs/EXTERNAL_PROVIDERS.md) for provider-specific details.

Nominatim/OpenStreetMap provides contextual map information: the module uses
the shared-place name to show a matching OSM object, its address hierarchy and,
where available, its geometry on a map. Its block is shown first on the
external-information page. Requests are cached and subject to the
[Nominatim usage policy](https://operations.osmfoundation.org/policies/nominatim/).

<a id="domus"></a>
## 🏠 Domus

[Domus](https://domus.genealogy.net) is an open application for researching the history of houses and buildings. It complements webtrees: webtrees remains the place for private genealogical data, while Domus can provide specialised public research and map views.
**Show in Domus** opens the linked Wikidata item in Domus in a new browser tab.
Without a Wikidata link, it opens the Domus map start page.
The Domus view can expose links to GOV and GenWiki, overlay historical maps,
and show Wikidata residents or owners for houses and farms where available.

![A shared place opened in Domus](docs/images/domus.png)

<a id="main-features"></a>
## ⚙️ Main features

The current release provides:

* recognising typed external identifiers;
* loading and caching public information from enabled providers;
* provider-specific searches (including GenWiki article search) and nearby searches where the provider supports them;
* assigning, replacing and removing identifiers explicitly;
* checking cross-references between providers and showing whether they are consistent;
* filtering provider searches by hierarchy level: house/farm, country, federation/international organisation, or planet;
* showing historical addresses, owners and occupants from Wikidata or FactGrid;
* showing a localized GeoNames parent hierarchy and translated type labels;
* searching public people and organisations associated with external places;
* linking displayed external people to their provider records and, when available, to WikiTree (`P2949`), GenWiki (`P14871`) or the Wikipedia sitelink for the selected language;
* configuring enabled providers and search options; and
* opening linked Wikidata places in Domus.

<a id="screenshots"></a>
## 🖼️ Screenshots

The screenshots below show the main workflows. The shared-place summary keeps
the local genealogy record in webtrees and presents links to the module's dedicated pages.

### Administration and search filters

Administrators can enable the external information providers and configure
their optional search filters. For every hierarchy level (house/farm, country,
federation or international organisation, and planet), each provider has a
list of typical type values that an editor can use to narrow a search. These
provider- and level-specific lists are shown in collapsible accordion panels;
each panel can be opened or closed independently and its values can be reset
to the provider defaults.

![Administration settings with provider search-filter types](docs/images/control_panel.png)

### Linking a shared place

The **External pages** action is available on the standard Vesta shared-place
page (see summary section). The `EXID` tags (introduced with GEDCOM 7) link to external databases.

![External-ID links and summary action](docs/images/link_exid.png)

### Provider information and consistency

The information page groups public data by provider. It also reports missing
or inconsistent values that may need to be reviewed in the shared-place
record.

![Provider information and consistency checks](docs/images/info.png)

### Addresses and related people

When a provider provides addresses or people connected with a place,
such as owners, the module displays these additional details as well.

![Historical addresses and related individuals](docs/images/address_individuals.png)

### Searching and assigning an external ID

Editors can search each provider by name or, where supported, by geographic radius.
Result lists can be filtered for the relevant hierarchy level when a provider returns many matches.

![External-ID assignment and provider searches](docs/images/zuordnung.png)

<a id="privacy"></a>
## 🔒 Privacy

When an external entry is loaded, the server requests only the validated identifier and requested display language.

Nearby searches send the shared place's coordinates and configured radius to
the selected provider. Standard technical request metadata is sent by the
server. The module does not transmit private genealogical notes or sources.

Responses are cached locally. If a provider is temporarily unavailable, the normal Vesta Shared Place page remains available.
When the optional Legal Notice module is active, it includes the selected external providers in the generated privacy policy together with the purpose of the request and the transferred technical data.

<a id="requirements"></a>
## 📌 Requirements

* webtrees 2.2.x or 2.3.x (the module is webtrees 2.3 ready)
* Vesta Shared Places
* PHP with HTTPS access to the selected provider APIs for live enrichment; cached data remains usable while offline

<a id="installation"></a>
## 📥 Installation

Install and use [Custom Module Manager](https://github.com/Jeferson49/CustomModuleManager)
for a convenient installation of webtrees custom modules:

1. Open **Control panel / Modules / Custom Module Manager** in webtrees.
2. Find **External Places** and click **Install module**.

**Manual installation**:

1. Download the [latest release](https://github.com/hartenthaler/hh_external_places/releases/latest).
2. Unzip it into the `modules_v4` directory of your webtrees installation.
3. Ensure that the directory is named `hh_external_places`.
4. In the webtrees control panel, enable **External Places**.

<a id="usage"></a>
## Usage

External provider data is available from the shared-place page through the
**External information** link. This dedicated page uses the normal webtrees
layout, identifies the place in its heading and links back to the shared-place
record. It keeps provider details, cross-reference checks, population data and
assignment actions together.

An editor can use **Assign external identifier** on the shared-place page. The provider-specific sections offer search and, where supported, nearby search; the editor must explicitly choose the result. The module stores a selected provider identifier as a typed external identifier. For example, a GeoNames assignment uses the numeric GeoNames ID:

```gedcom
1 _EXID 2930035
2 TYPE https://www.geonames.org/
```

If an existing item has been merged or redirected by Wikidata, the assignment page shows the replacement QID and lets the editor apply it explicitly.

For a shared place with GEDCOM `MAP`, `LATI` and `LONG` coordinates, editors can also use **Search nearby**. The module shows at most 20 candidates inside the configured radius and ranks suggestions by matching name and distance. The search radius is configured in the module settings.

Search results use a compact table. They show the provider label and identifier, the description and—where applicable—distance. Opening a result uses the provider's public page.

When Wikidata, FactGrid or Nominatim provides address data, editors can transfer
individual structured address rows into the registered `_LOC:_ADDR` structure.
The shared-place page shows house number, street, postal code, place,
administrative area and optional validity dates. Each imported row keeps the
provider reference as provenance; existing matching rows are not duplicated.
Before offering a transfer, existing `_LOC:_ADDR` rows are checked. Matching
rows are marked as consistent and can be hidden with the setting for
consistent external information.

The currently offered GEDCOM transfers are summarized in
[Provider data offered for transfer](docs/IMPORTABLE_PROVIDER_DATA.md). They
include explicitly selected external identifiers, coordinates, place names,
GOV types, Wikidata/FactGrid/Nominatim address rows, population observations,
provider images and newly created individuals from provider relationships.
Images remain external links in newly created media objects; the module does
not download or silently copy image files.

Where Wikidata or FactGrid contains them, the same panel also shows public
owners and occupants. An editor can explicitly select **Add person** to create
a new INDI with the available provider/WikiTree EXID values, a PROP or RESI
event and a `_LOC:_ASSO` link. Existing webtrees people are not matched or
merged automatically. The table gives the public name and provider link, known
birth/death dates, the period of the relationship and available WikiTree links.
GOV may provide several historical population figures. They are displayed as a
chronologically sorted table with a compact line chart, using the user's
webtrees number formatting. Editors can explicitly add an observation as a
GEDCOM-L demographic data (`_DMGD` with `TYPE POPULATION` and an optional
`DATE`), with provenance retained in a valid record-level note. The same table
marks matching observations as consistent and avoids duplicates.
Additional GOV external identifiers are shown with their configured meaning
and, where a public URL template is known, as links.

<a id="documentation"></a>
## 📖 Documentation
* [Concept: Wikidata and Domus integration](docs/WIKIDATA_DOMUS_CONCEPT.md)
* [External providers and identifier consistency](docs/EXTERNAL_PROVIDERS.md)
* [Coordinates and consistency](docs/COORDINATES.md)
* [FamilySearch Places access test](docs/FAMILYSEARCH_PLACES.md)
* [GOV type catalogue](docs/GOV_TYPE_CATALOG.md)
* [Module and repository naming proposal](docs/RENAME_PROPOSAL.md)
* [Roadmap](docs/ROADMAP.md)
* [Development notes](docs/DEVELOPMENT.md)
* [Historical address model](docs/HISTORICAL_ADDRESSES.md)
* [Field-level reconciliation and import actions](docs/FIELD_RECONCILIATION.md)
* [Provider data offered for transfer](docs/IMPORTABLE_PROVIDER_DATA.md)
* [Owner and occupant metadata](docs/OWNER_AND_OCCUPANT_METADATA.md)
* [Version 2 roadmap](docs/ROADMAP.md)
* [Changelog](CHANGELOG.md)

<a id="translation"></a>
## 🌐 Translation

The module uses the standard webtrees gettext system (`.po`/`.mo`) for its
user interface: headings, buttons, messages and other operating texts are
translated through `resources/lang`. GOV object types are not maintained as
interface strings; their multilingual labels come from the
bundled source `resources/config/gov-types.owl`. See [the GOV type catalogue
documentation](docs/GOV_TYPE_CATALOG.md) for the source and fallback rules.
German is currently available. Contributions are welcome as pull requests.

<a id="credits"></a>
## 🙏 Credits

* [webtrees](https://www.webtrees.net)
* [Vesta Shared Places](https://github.com/vesta-webtrees-2-custom-modules/vesta_shared_places)
* David Straub for developing [Domus](https://domus.genealogy.net), and the Domus community
* [Wikidata](https://www.wikidata.org) and [Wikimedia Commons](https://commons.wikimedia.org)
* [FactGrid](https://database.factgrid.de) and [GOV](https://gov.genealogy.net)
* GeoNames, GOV, Nominatim, ...

<a id="license"></a>
## ⚖️ License

This module is licensed under [GPL-3.0-or-later](LICENSE).
