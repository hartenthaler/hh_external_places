# External-person matching

The module uses two deliberately separate comparison scopes.

## Consistency on the shared-place page

When provider owners or occupants are displayed on a shared-place page, the
module compares them only with individuals already linked to that same place
through `_LOC:_ASSO` or the legacy `_LOC:ASSO` structure. This keeps the
consistency check local to the place and avoids scanning the complete tree.

If the provider identifier is already present on one of those linked
individuals, the provider person is considered consistent and is not offered
again as a new import.

## Similar-person search after an import

After a new external individual has been created, the module opens a separate
similar-person page. It searches for possible duplicates in the complete
current family tree. This is a read-only search. It ranks at most 20
candidates using normalized names, available birth and death dates, sex and
matching external identifiers. The criteria and any matching or conflicting
values are shown to the editor.

The module never merges records itself. For each candidate it only offers the
webtrees merge action. webtrees performs the permission check and the complete
comparison and merge workflow; the action is therefore available only to an
administrator. No module-specific merge or write operation is introduced.

The candidate page identifies the imported person by a link to the new
individual record. The XREF is intentionally not repeated in the heading.

An exact provider identifier that is already associated with the shared place
prevents creation of another imported individual. No expensive global EXID
scan is performed as part of the place consistency check.
