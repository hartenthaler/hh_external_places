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
