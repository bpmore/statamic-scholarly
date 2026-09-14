# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).
While the major version is 0 the publication entry shape may still change; once
it is 1, it will not change without a migration path, because consumers will
have verified entries by hand and cannot re-verify them.

## [Unreleased]

### Fixed

- The E-summary client cached a timeout or a refused request as a missing
  record for a week. Only a miss NCBI reports is recorded now.

### Added

- `scholarly:notify`, which mails each researcher's `scholarly_verifier` (or
  the configured fallback address) one digest of candidates not yet
  mentioned, says per researcher when the last fetch was partial, and stamps
  what it mentioned. A researcher nobody can be told about fails the run
  loudly. Scheduled alongside the fetch.
- `scholarly_last_run` on the researcher: the last fetch's verdict and
  summary, written every time.
- `{{ scholarly:publications }}` and `{{ scholarly:publications_count }}`,
  returning a researcher's approved publications only, with `researcher`,
  `sort`, `limit`, `paginate`, `as`, `year` and `journal`. The
  `scholarly_authors` modifier joins author names with an "et al." cap.
- Verification in the control panel: Confirm, Reject (with an optional
  reason) and Defer actions on the owned collection's listing and entries,
  gated by a `verify scholarly publications` permission, with a "Publication
  review" nav item. Every transition records who and when.
- The fetch pipeline: `scholarly:fetch` queues one `FetchPublications` job per
  researcher (or runs inline with `--sync`), every source is consulted, the
  candidates are de-duplicated, and one entry per paper is written to the
  owned collection as a candidate with the researcher attached. A rejected
  entry is never touched again; an approved entry keeps the snapshot the
  verifier edited, and goes back to review if a fetch attaches a researcher
  nobody confirmed.
  `scholarly_last_fetched` on the researcher is stamped per source, only for
  sources that completed.
- `scholarly:install`, which creates the owned publications collection and
  its blueprint under the configured handle. The first fetch does this too.
- A nightly schedule from `schedule` in config.
- `PubmedSource`: an author-string search through esearch, paged explicitly,
  with summaries batched through the metadata seam. A name search is an
  estimate and every candidate says so. Zero hits is a complete empty answer;
  a search PMID the summary endpoint disowns makes the run partial.
- `PublicationMetadataFetcher::summariesForPmids()`, one request per hundred
  PMIDs. Both methods now throw when NCBI could not be asked; null means only
  that NCBI said the record does not exist.
- `OrcidSource`: one request per researcher to the ORCID public API, one
  candidate per work group, with a 404 reported as an unknown identifier
  rather than as a researcher with no works. Reads the iD from the
  host-mapped field. Optional token; no credential required.
- `SharedRateLimit`, the cache-backed limiter the NCBI client used, pulled out
  so every remote gets the same per-site ceiling.
- De-duplication across DOI, PMID and title, with title matches flagged for
  review rather than merged.
- NCBI E-utilities identification (`tool`, `email`, optional `api_key`) and a
  cache-backed rate limiter shared across every worker.
- `FetchOutcome`, which distinguishes a complete run from a partial one and from
  a run where nothing could look at all.
- `ResearcherFieldMap`, so identifier field handles are read from configuration
  rather than assumed.
- The corpus, with five cases.
