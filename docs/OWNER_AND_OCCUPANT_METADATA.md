# Owners and occupants from Wikidata

This document describes the provider-specific owner and occupant enrichment of
the provider-neutral **External Places** module.

External Places can show selected public relationships for a shared place.
The module reads them from Wikidata only and never creates, changes, or links
webtrees individual records.

## Included statements

* Wikidata `P127` (**owned by**) is displayed as **Owners**.
* Wikidata `P466` (**occupant**) is displayed as **Occupants**.
* FactGrid `P126` (**Owned by**) is displayed as **Owners**.
* FactGrid `P239` (**Resident**) is displayed as **Occupants**.

FactGrid relationship dates use `P49` (begin date) and `P50` (end date).
FactGrid person records are loaded in one bounded batch and remain read-only.
FactGrid `P2949` (**WikiTree person ID**) is rendered as a WikiTree link when
present; Wikidata uses the corresponding `P2949` property.

All usable statements are shown, including historical statements and statements
marked as deprecated in Wikidata. Historical information is the purpose of this
view, so the module does not filter statements by their Wikidata rank.

For every relation, the module shows the external provider label and link,
birth and death dates where available, and the relation's start and end
qualifiers. A missing date remains empty. Provider cross-links are shown only
for validated, fixed provider mappings.

## Privacy and provenance

The data comes from the public Wikidata directory and may be shown to visitors
who can view the shared place. It is clearly marked as sourced from Wikidata.
The module deliberately does not try to match an external Wikidata person to a
person in the family tree, does not import personal data into GEDCOM, and does
not send local person data to Wikidata.

The module requests at most 20 related items for one place display. If that
additional request fails, the verified identifiers remain available as
external links.
