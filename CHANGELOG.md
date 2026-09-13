# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).
While the major version is 0 the publication entry shape may still change; once
it is 1, it will not change without a migration path, because consumers will
have verified entries by hand and cannot re-verify them.

## [Unreleased]

### Added

- De-duplication across DOI, PMID and title, with title matches flagged for
  review rather than merged.
- NCBI E-utilities identification (`tool`, `email`, optional `api_key`) and a
  cache-backed rate limiter shared across every worker.
- `FetchOutcome`, which distinguishes a complete run from a partial one and from
  a run where nothing could look at all.
- `ResearcherFieldMap`, so identifier field handles are read from configuration
  rather than assumed.
- The corpus, with five cases.
