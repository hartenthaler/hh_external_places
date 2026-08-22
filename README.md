# **webtrees** module: External Places

![webtrees major version](https://img.shields.io/badge/webtrees-v2.2.x-green)
[![Module version](https://img.shields.io/badge/version-2.2.6.0-blue)](version.txt)
[![Downloads](https://img.shields.io/github/downloads/hartenthaler/hh_external_places/total?label=downloads)](https://github.com/hartenthaler/hh_external_places/releases)
[![License: GPL v3](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

External Places is a [webtrees](https://www.webtrees.net) module for enriching [Vesta Shared Places](https://github.com/vesta-webtrees-2-custom-modules/vesta_shared_places) (`_LOC`) with public, read-only information from Wikidata, FactGrid and GOV. Editors can assign and compare external place identifiers, search each provider and inspect cached details without copying external research into genealogical data.

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

The module does not synchronize with Wikidata, FactGrid or GOV and does not send genealogical person data to these services. Assigning, replacing or removing an identifier is always an explicit action by a user who may edit the shared place. Read-only display never changes GEDCOM data.

Wikidata, FactGrid and GOV use fixed provider adapters. Their public cross-references can be checked for consistency; missing identifiers are added only after an editor explicitly submits them. See [External providers and identifier consistency](docs/EXTERNAL_PROVIDERS.md).

## 🏠 Domus (Wikidata)

[Domus](https://domus.genealogy.net) is an open application for researching the history of houses and buildings. It complements webtrees: webtrees remains the place for private genealogical data, while Domus can provide specialised public research and map views. **Show in Domus** opens the linked Wikidata item in Domus in a new tab. Without a Wikidata link, it opens the Domus map start page. The module does not embed Domus or synchronize data with it.

## ⚙️ Main features

The current release provides:

* recognising typed Wikidata and FactGrid QIDs and the preferred `_GOV` identifier;
* validating QIDs and keeping other external identifiers untouched;
* loading and caching public provider information through Vesta's place-information provider mechanism;
* using the webtrees language with a sensible fallback; and
* showing provider provenance for displayed data and images;
* protected provider-specific search pages for finding, reviewing and manually assigning identifiers; and
* controlled removal and replacement of stored QIDs, including an explicit offer to replace a redirected Wikidata item and an updated shared-place change record; and
* an editor-only nearby search for shared places with coordinates, ordered by name similarity and distance; and
* a read-only outbound link to Domus, using its documented QID deep link where available.
* provider-specific searches and nearby searches for Wikidata, FactGrid and GOV;
* historical addresses, owners and occupants from Wikidata, when the linked item actually provides them;
* public provider cross-references and consistency information without modifying GEDCOM data.

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

An editor can use **Assign external identifier** on the shared-place page. The provider-specific sections offer search and, where supported, nearby search; the editor must explicitly choose the result. The module stores a Wikidata item as an external identifier with its authority URI:

```gedcom
1 _EXID Q123456
2 TYPE https://www.wikidata.org/entity/
```

If an existing item has been merged or redirected by Wikidata, the assignment page shows the replacement QID and lets the editor apply it explicitly.

For a shared place with GEDCOM `MAP`, `LATI` and `LONG` coordinates, editors can also use **Search nearby**. The module shows at most 20 candidates inside the configured radius. It ranks suggestions by matching name and distance, but never creates a link automatically. Administrators can choose the radius independently for every family tree in the module's configuration; the selected value is used consistently for nearby searches in Wikidata, FactGrid and GOV.

Search results use a compact table. They show the provider label and identifier, the description and—where applicable—distance. Opening a result uses the provider's public page; **Assign** remains an explicit editor action.

When Wikidata provides address statements, the shared-place page shows a read-only address table with house number, street, postal code, place and optional validity dates. It is intentionally omitted for items without address data, such as most settlements or administrative areas.

Where Wikidata contains them, the same panel also shows public owners and occupants. These are external Wikidata facts: no webtrees person is linked, changed or created. The table gives the public Wikidata name and link, known birth/death dates and the period of the relationship.

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
