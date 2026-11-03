# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).
While the major version is 0 the publication entry shape may still change; once
it is 1, it will not change without a migration path, because consumers will
have verified entries by hand and cannot re-verify them.

## [Unreleased]

## [0.3.1] - 2026-11-02

### Fixed

- Repeated ORCID fetches now reuse records without a DOI or PMID by keeping
  scoped ORCID work IDs. Edited and rejected records retain their review.
  Older duplicates without source IDs need manual review; the upgrade does
  not merge or delete entries by title.
- Public publication lists, counts and feeds now respect Statamic's publish
  state as well as Scholarly approval. Approved drafts, scheduled papers and
  expired papers stay private. Draft or otherwise private researchers have
  no public feed, including when selected by id or the current entry context.
- The install guide now requires the 0.3 release line instead of 0.1.
- Package descriptions and the README no longer imply every approved paper
  was confirmed by a person. Automatic approval is described explicitly.

### Added

- Editor permission and scheduler setup instructions, Marketplace listing
  copy, and a release-readiness record.

After upgrading, run `php please scholarly:install` to add the source-record
field without changing existing fields. Clear the site's static cache and purge any publication
pages or feeds stored by a CDN. Previously cached responses may contain
content that the corrected output no longer returns.

## [0.3.0] - 2026-09-17

Marketplace readiness, reviewed against Statamic's submission guidelines.

### Added

- `scholarly:install` adds any field a newer version ships to an existing
  publications blueprint, and says which. Nothing the site changed is
  touched.
- `feed.enabled` config key: `false` turns the public JSON feed off.
- A support path in `composer.json` and the README, and a README section
  listing every outbound request the addon makes.
- `confidence_why`: the score's breakdown as sentences on the entry, in
  place of a key/value grid whose keys the sidebar truncated to
  "signal_c". The raw breakdown stays, hidden, for machines.
- Screenshots in the README.

### Changed

- Composer dist downloads no longer include the tests, fixtures and CI
  configuration.

## [0.2.0] - 2026-09-15

### Added

- **A page under Tools.** Scholarly now has a utility in the control panel:
  the review queue (waiting, deferred, set aside, approved, rejected), every
  researcher with their identifiers, last fetch and verifier, which sources
  will run and why one will not, NCBI identification, scoring calibration,
  notifications and the schedule. Read-only, and nothing on it fetches.
  Access is the permission "Access Scholarly utility". Settings still change
  in `config/statamic-scholarly.php` and `.env`; the page reads them and says
  where they live.

## [0.1.0] - 2026-09-14

First release. Everything below is new.

### Added

- A `webhook` notification channel: each digest POSTed as JSON to
  `notifications.webhook_url`, signed with HMAC-SHA256 under
  `webhook_secret` in `X-Scholarly-Signature`. Dropped when no URL is set;
  a failed post is logged and does not stop the mail.
- The "Publication review" nav item shows how many candidates are waiting,
  for users who can verify. Statamic 6 has no in-panel inbox, so there is
  no bell.
- A `scholarly` fieldset, written by `scholarly:install` for the researcher
  blueprint: the verifier, the ten newest approved publications, the number
  of candidates waiting, and the last fetch's verdict. Written by the addon
  after every scoring pass; nothing computed on render.
- `{{ id | scholarly_citation:ama }}` (also `apa`, `vancouver`; add `:html`
  for an italic journal and escaped text), rendering the stored record and
  leaving out what it does not have.
- A JSON feed at `/!/statamic-scholarly/feed/{researcher}` of approved
  publications, with `year`, `journal` and `limit`, and
  `{{ scholarly:feed_url }}` to point at it. The tag and the feed share one
  approved-only query.
- Volume, issue and pages are now stored from PubMed and from imports, and
  an approved entry gains any field it lacked from a later fetch.
- `scholarly:import file researcher`: BibTeX or CSV into candidates for
  one researcher, through the same de-duplication, matching and scoring a
  fetch uses. `--confirm-as=<user>` approves what the import brought in as
  that person's decision. The supported route for Google Scholar exports
  and hand-kept lists.
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
