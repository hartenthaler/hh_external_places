# Development

This repository targets webtrees 2.2.x and is based on `hartenthaler/hh-webtrees-module-template`.

Initial development should follow the milestones in `ROADMAP.md` and the concrete issue plan in `GITHUB_ISSUES.md`.

## Debugging rule for external providers

Provider diagnostics must expose all information needed to reproduce a
selection decision in one message: the original place value, the normalized
query sent to the provider, provider and operation, cache state, HTTP status,
response/candidate counts, filtering criteria and the selected result (or the
reason why no result was selected). Temporary diagnostics must be removed or
disabled before a release.

## Cache schema-version rule

Increase the cache schema version in `WikibaseCacheSchema` whenever an
existing cache entry can no longer be interpreted exactly as before. This
includes changes to the stored payload structure, the provider or language
parts of the cache key, the requested Wikibase properties, the mapping of
claims into module data, or the meaning of an existing value. A version bump
must include a migration that either converts the old entries or explicitly
invalidates them. Do not bump the version for changes that affect only the
presentation of already compatible cached data.

## Reuse before implementation

“Etwas nicht zu programmieren ist die beste Art zu programmieren.”
Before adding code, search the repository and the other modules for an
equivalent implementation. Prefer reusing or extending a shared function or
class over duplicating provider-specific logic. Add a new shared abstraction
when it removes genuine duplication, and keep provider adapters focused on
mapping their external data to that common model.
