# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).
While the major version is 0 the publication entry shape may still change; once
it is 1, it will not change without a migration path, because consumers will
have verified entries by hand and cannot re-verify them.

## [Unreleased]

### Fixed

- A refresh wrote every snapshot field, nulls included, so a PMID-only
  source erased the DOI a DOI-only source had stored the night before. Only
  values the run has are written now, and `source_apis` accumulates.
- The Confirm, Reject and Defer actions and the review nav item named icons
  that do not exist in Statamic 6, so the control panel logged a warning for
  each and drew nothing.
- Under the Eloquent entry driver: the writer no longer sets entry ids
  (which fought an auto-increment column), researcher ids are cast to
  strings everywhere the driver may hand out integers, and the tag matches a
  researcher id stored as either a string or an integer. The suite now runs
  under the Stache and under both Eloquent id shapes.
- The E-summary client cached a timeout or a refused request as a missing
  record for a week. Only a miss NCBI reports is recorded now.

### Added

- The discard floor: a candidate whose calibrated probability is below
  `scoring.discard_floor` is set aside (`discarded_at`): still a candidate,
  left out of the digest, filterable in the listing, never deleted, and
  asked about again if a later rescoring lifts it. The prior never sets
  anything aside.
- Auto-approval: with `scoring.auto_approve` on, a candidate whose number
  means something (an ORCID anchor, or a calibrated probability) and is at
  or above `auto_approve_threshold` is approved by the scorer, marked
  `auto_approved` with no verifier, and can be rejected by a person. The
  shipped prior never approves anything. Sites that published config
  before this key existed must add it to turn it on.
- `scholarly:calibrate`: fits the confidence model to this site's confirm
  and reject decisions, pooled across researchers and pulled toward the
  shipped prior, reports cross-validated reliability, and refuses below
  the configured number of decisions or when the fit is worse than the
  prior. Candidates then say `calibrated`. `--dry-run` and `--forget`.
- Confidence scoring, first part: every candidate carries
  `confidence_score`, a per-signal `confidence_breakdown`, and a
  `confidence_state` that says what the number is: `certain` (an ORCID
  anchor), `uncalibrated` (the shipped prior; an ordering, not a
  probability) or, later, `calibrated`. Signals: name match, affiliation,
  co-author overlap with the researcher's certain papers, journal,
  corroboration, and name prevalence from the PubMed search count. Confirm
  and reject rescore. Nothing is auto-approved or discarded yet.
- Candidates now carry author affiliations, author ORCID iDs and the
  abstract: PubMed through `efetch` and ORCID through the bulk works
  endpoint, one request per hundred, cached. If that second request cannot
  answer, the candidates still arrive with what the listing had and the run
  is partial. `PublicationMetadataFetcher::detailsForPmids()` is the seam.
- Title matches are now found across runs, not only within one: a stored
  `title_key` lets tonight's full paper find last month's abstract, and both
  are linked in `possible_duplicates`. Nothing is merged.
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
