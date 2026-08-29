# **webtrees** module: External Places

![webtrees major version](https://img.shields.io/badge/webtrees-v2.2.x-green)
[![Module version](https://img.shields.io/badge/version-2.2.6.7-blue)](version.txt)
[![Downloads](https://img.shields.io/github/downloads/hartenthaler/hh_external_places/total?label=downloads)](https://github.com/hartenthaler/hh_external_places/releases)
[![License: GPL v3](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

External Places is a [webtrees](https://www.webtrees.net) module for enriching [Vesta Shared Places](https://github.com/vesta-webtrees-2-custom-modules/vesta_shared_places) (`_LOC`) with public information from Wikidata, FactGrid, GOV, GeoNames and optionally Nominatim/OpenStreetMap. The module focuses on houses, farms and other inhabited buildings, while remaining usable for every kind of shared place. Administrators can enable or disable providers and configure a global nearby-search radius with optional exceptions for individual family trees. Editors can assign and compare external place identifiers, search each provider and inspect cached details. After review, selected external identifiers or information can explicitly be transferred into the shared-place record.

## 📚 Contents

* [Purpose](#purpose)
* [Main features](#main-features)
* [Screenshot](#screenshot)
* [Domus (Wikidata)](#domus-wikidata)
* [Privacy](#privacy)
* [Requirements](#requirements)
* [Installation](#installation)
* [Usage](#usage)
* [Documentation](#documentation)
* [Translation](#translation)
* [Credits](#credits)
* [License](#license)

## 🎯 Purpose

A shared place can represent a building, farm, church, cemetery, street, square, district, village or another real-world place. The module stores validated external identifiers alongside the shared-place record and displays provider-specific public information such as names, descriptions, types, images, addresses, relationships, external references and population data where available.

The module does not synchronize with Wikidata, FactGrid or GOV and does not send genealogical person data to these services. Assigning, replacing or removing an identifier, or transferring a reviewed value into the shared-place record, is always an explicit action by a user who may edit the shared place. Read-only display never changes GEDCOM data.

Wikidata, FactGrid and GOV use fixed provider adapters. Their public cross-references can be checked for consistency; missing identifiers are added only after an editor explicitly submits them. See [External providers and identifier consistency](docs/EXTERNAL_PROVIDERS.md).

## 🏠 Domus (Wikidata)

[Domus](https://domus.genealogy.net) is an open application for researching the history of houses and buildings. It complements webtrees: webtrees remains the place for private genealogical data, while Domus can provide specialised public research and map views. **Show in Domus** opens the linked Wikidata item in Domus in a new tab. Without a Wikidata link, it opens the Domus map start page. Domus is a separate public research application; the module only provides links to it.

## ⚙️ Main features

The current release provides:

* recognising typed Wikidata and FactGrid QIDs, GOV identifiers and GeoNames IDs;
* loading and caching public information from Wikidata, FactGrid, GOV, GeoNames and Nominatim;
* provider-specific searches and nearby searches where the provider supports them;
* assigning, replacing and removing identifiers explicitly, without automatic GEDCOM changes;
* checking cross-references between providers and showing whether they are consistent;
* filtering provider searches by hierarchy level: house/farm, country, federation/international organisation, or planet;
* showing historical addresses, owners and occupants from Wikidata or FactGrid;
* searching public people and organisations associated with external places;
* linking displayed external people to their provider records and, when available, to WikiTree (`P2949`);
* configuring enabled providers and one global nearby-search radius with optional tree exceptions; and
* opening linked Wikidata places in Domus.

## 🖼️ Screenshot

The shared-place summary keeps the local genealogy record in webtrees and adds compact, read-only external-provider panels.

![External provider information shown for a shared place](docs/images/screenshot1.jpg)

## 🔒 Privacy

When an external entry is loaded, the server requests only the validated identifier and requested display language. Nearby searches send the shared place's coordinates and configured radius to the selected provider. Standard technical request metadata is sent by the server. The module never sends names of living people, family relationships, private notes, sources or other genealogical data.

Responses are cached locally. If a provider is temporarily unavailable, the normal Vesta Shared Place page remains available.
When the optional Legal Notice module is active, it includes the selected external providers in the generated privacy policy together with the purpose of the request and the transferred technical data.

## 📌 Requirements

* webtrees 2.2.x
* Vesta Shared Places
* PHP with HTTPS access to the selected provider APIs for live enrichment; cached data remains usable while offline

## 📥 Installation

1. Download the [latest release](https://github.com/hartenthaler/hh_external_places/releases/latest).
2. Unzip it into the `modules_v4` directory of your webtrees installation.
3. Ensure that the directory is named `hh_external_places`.
4. In the webtrees control panel, enable **External Places**.
5. In the Vesta Shared Places configuration, enable **External Places** as a place-information provider.

## Usage

External provider data is available from the shared-place page through the
**External information** link. This dedicated page uses the normal webtrees
layout, identifies the place in its heading and links back to the shared-place
record. It keeps provider details, cross-reference checks, population data and
assignment actions together. The summary link remains for compatibility with
Vesta Shared Places.

An editor can use **Assign external identifier** on the shared-place page. The provider-specific sections offer search and, where supported, nearby search; the editor must explicitly choose the result. The module stores a selected provider identifier as a typed external identifier. For example, a GeoNames assignment uses the numeric GeoNames ID:

```gedcom
1 _EXID 2930035
2 TYPE https://www.geonames.org/
```

If an existing item has been merged or redirected by Wikidata, the assignment page shows the replacement QID and lets the editor apply it explicitly.

For a shared place with GEDCOM `MAP`, `LATI` and `LONG` coordinates, editors can also use **Search nearby**. The module shows at most 20 candidates inside the configured radius. It ranks suggestions by matching name and distance, but never creates a link automatically. Administrators can set one radius for all family trees and add exceptions only where a tree needs a different value; the selected value is used consistently for nearby searches in Wikidata, FactGrid and GOV.

Search results use a compact table. They show the provider label and identifier, the description and—where applicable—distance. Opening a result uses the provider's public page; **Assign** remains an explicit editor action.

Administrators can maintain provider-specific type lists for four hierarchy
levels: house/farm, country, federation/international organisation, and planet.
The lists are presented in an accordion and each provider has a **Reset to
default** action. Editors can activate one level filter separately for each
provider; the normal unfiltered search remains available. Nearby-search
buttons are disabled until the shared place has valid coordinates; a tooltip
explains what is missing.

When Wikidata provides address statements, the shared-place page shows a read-only address table with house number, street, postal code, place and optional validity dates. It is intentionally omitted for items without address data, such as most settlements or administrative areas.

Where Wikidata or FactGrid contains them, the same panel also shows public owners and occupants. These are external facts: no webtrees person is linked, changed or created. The table gives the public name and provider link, known birth/death dates, the period of the relationship and available WikiTree links.
GOV may provide several historical population figures. They are displayed as a
chronologically sorted table with a compact line chart, using the user's
webtrees number formatting. Additional GOV external identifiers are shown with
their configured meaning and, where a public URL template is known, as links.

## 📖 Documentation
* [Concept: Wikidata and Domus integration](docs/WIKIDATA_DOMUS_CONCEPT.md)
* [External providers and identifier consistency](docs/EXTERNAL_PROVIDERS.md)
* [Module and repository naming proposal](docs/RENAME_PROPOSAL.md)
* [Roadmap](docs/ROADMAP.md)
* [Development notes](docs/DEVELOPMENT.md)
* [Historical address model](docs/HISTORICAL_ADDRESSES.md)
* [Owner and occupant metadata](docs/OWNER_AND_OCCUPANT_METADATA.md)
* [Version 2 roadmap](docs/ROADMAP.md)
* [Changelog](CHANGELOG.md)

## 🌐 Translation

The module uses the standard webtrees gettext translation system. Translation files are kept in `resources/lang`; German is currently available. Contributions are welcome as pull requests.

## 🙏 Credits

* [webtrees](https://www.webtrees.net)
* [Vesta Shared Places](https://github.com/vesta-webtrees-2-custom-modules/vesta_shared_places)
* [Wikidata](https://www.wikidata.org) and [Wikimedia Commons](https://commons.wikimedia.org)
* [FactGrid](https://database.factgrid.de) and [GOV](https://gov.genealogy.net)
* David Straub for developing [Domus](https://domus.genealogy.net), and the Domus community

## ⚖️ License

This module is licensed under [GPL-3.0-or-later](LICENSE).
