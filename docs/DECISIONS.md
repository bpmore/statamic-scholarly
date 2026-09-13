# Decisions

Why things are the way they are, and what was turned down. Read before choosing
an approach; add an entry when a change settles a question.

## 2026-09-13: Bibliographic fields are stored, not computed

**Decision.** A publication entry stores its own title, journal, dates and
authors. Freshness comes from the fetch job writing back into that snapshot.

**Why.** A public addon cannot rely on a host having a PubMed cache, and
computing from PubMed produces nothing at all for the DOI-only works ORCID
returns. Storing also makes an editorial correction possible, which a computed
field would silently discard on the next render.

**Rejected.** Computing bibliographic fields from PubMed at read time. It keeps
metadata current for free and is what a sibling project chose, but it covers only
PubMed-indexed works and it puts a cache read on every render.

## 2026-09-13: De-duplication never merges on title

**Decision.** DOI and PMID merge. A title match is recorded as a pair for a
human to look at and merges nothing.

**Why.** An erratum and the paper it corrects share a title and are different
works, as do conference abstracts and the full papers that follow them. Merging
destroys one of the two, and the result looks like a single believable entry, so
nobody notices.

**Rejected.** Fuzzy title merging above a similarity threshold. Every threshold
that merged the genuine duplicates also merged errata.

## 2026-09-13: A source reports failure rather than returning nothing

**Decision.** `PublicationSource::fetchForResearcher()` returns a `FetchOutcome`
carrying candidates, failures and the list of sources that completed.

**Why.** Zero publications is indistinguishable from a researcher who has none,
an expired credential, a rate-limit block, a renamed API field and a typo in an
identifier. An array cannot express the difference, and the caller that renders
an empty list has published a claim it cannot support.

**Rejected.** Returning an array and throwing on failure. One dead source would
abort the whole run, so a single expired Scopus key would stop ORCID and PubMed
being fetched at all.

## 2026-09-13: NCBI queries refuse rather than proceed unidentified

**Decision.** No contact email means `MissingContactEmail`, not a warning and a
request.

**Why.** NCBI's guidelines ask every client to identify itself so they can make
contact before blocking it. For one site, skipping that is a latent risk the
site owner carries. For an addon installed on many sites it is a design defect:
every install becomes anonymous traffic and the block lands on people who did
nothing. A run that did not happen is visible; one that happened anonymously is
not.

**Rejected.** Warning and sending anyway, which is what the codebase this design
was drawn from does today.

## 2026-09-13: The rate limiter is shared, not per call site

**Decision.** A cache-backed limiter keyed `scholarly:ncbi`, polled until a slot
is free, bounded at five seconds.

**Why.** Sleeping between requests inside one method is not a rate limit. Two
queue workers each pacing themselves politely still exceed the ceiling together,
and that is the shape a real deployment has.

**Rejected.** `RateLimiter::attempt()` alone. It does not wait: it runs the
callback or returns false, so used by itself it would drop a request rather than
pace it, and on a queued job a dropped request is a silently missing publication.

## 2026-09-13: Proprietary licence, free to use

**Decision.** Same licence as the author's other Statamic addon. Free on any
number of sites, no key, no limit, no licence check in the code. Not
redistributable and not reusable as the basis of another product.

**Why.** Consistency with the author's existing addon, and the disambiguation
logic is the valuable part. Free to use is the intent; free to take is not.

**Note.** The sibling addon's licence carries a specific reason that does not
apply here: those rules were a deliberate fork of an engine behind another
product. Scholarly is not a fork of anything, so the no-reuse clause rests on the
plainer argument above. Worth revisiting if a permissive licence would help
adoption more than the clause protects.

## 2026-09-13: Each build keeps its own corpus

**Decision.** No shared conformance corpus with the separate in-house
implementation.

**Why.** A shared corpus between two implementations of the same rules was tried
in this account, between the sibling addon and another project, and ended when
the addon was separated to stand on its own. The corpus was kept, because what it
is worth day to day never depended on anyone else running it. That reasoning
applies here unchanged.

**Rejected.** A shared fixture corpus as a cross-implementation conformance
suite. It remains available and costs nothing to attempt later, but designing for
it now would repeat an arrangement that has already been unwound once.

## 2026-09-13: Feature tests extend Statamic's AddonTestCase

**Decision.** `tests/TestCase.php` extends `Statamic\Testing\AddonTestCase` and
names the provider. Nothing else is wired by hand.

**Why.** `AddonServiceProvider::bootConfig()` locates the addon through the
Composer manifest, and a package under test is not in
`vendor/composer/installed.json`. A hand-rolled Testbench case booted without
error and merged no config: `config('statamic-scholarly')` read null and every
binding quietly fell back to its default. That is the same silent shape as a
misconfigured host, and the suite would have passed against it.
`AddonTestCase` writes the addon into the manifest from `composer.json`.

**Rejected.** A plain `Orchestra\Testbench\TestCase` listing the providers, the
shape borrowed from `bpmore/statamic-a11y-gate`. It works there only because
nothing in that addon reads its own config during boot.

## 2026-09-13: OrcidSource reads work summaries only

**Decision.** `OrcidSource` makes one request per researcher, to
`/v3.0/{orcid}/works`, and each ORCID group becomes one candidate carrying
title, DOI, PMID, journal, date and URL. Authors and abstract stay empty.

**Why.** Everything de-duplication keys on is in the summary, and one request
per researcher has no partial-failure case to design. The full record (one
call per work, or one per hundred via the bulk endpoint) adds contributors and
a short description, and with it a second failure mode: summaries fetched,
details not. That outcome needs its own reporting rule, and it is better
decided when the review panel exists to show the difference.

**Rejected.** Filling `authors` with the researcher's own name. The claim is
true, but the candidate would render as a single-author paper, which is a
different claim and usually a false one.

**Also settled here.**

- One group, one candidate. ORCID has already grouped summaries it believes
  are the same work, and emitting one candidate per summary only hands the
  de-duplicator a duplicate to catch. The summary with the highest
  `display-index` supplies the bibliographic fields; the group's identifiers
  supply the keys.
- Only `self` identifiers key a candidate. A `part-of` DOI is the book a
  chapter sits in, and keying on it would merge every chapter into one
  believable entry.
- A 404 is `Reason::UnknownIdentifier`, a new case. A well-formed iD that
  ORCID has never issued needs a person to check the field, not a retry, and
  the enum's other cases all send the reader somewhere else.
- `since` filters on ORCID's `last-modified-date`, client side, because the
  endpoint has no server-side filter. The whole listing is fetched either
  way; `since` only narrows what is handed on.
- No credential is required. ORCID's public API answers without one, so
  unlike NCBI there is no identification rule to refuse on. A token is
  optional and only raises the rate ceiling.

## 2026-09-13: The metadata seam batches, and throws rather than answering null

**Decision.** `PublicationMetadataFetcher` gains `summariesForPmids()`, one
request per hundred PMIDs. Both methods throw `NcbiUnavailable` when NCBI
could not be asked and `UnexpectedNcbiResponse` when the answer was not a
summary. Null means one thing only: NCBI answered, and said no such record.

**Why.** Two hundred papers one at a time at three requests a second is over a
minute per researcher, and a faculty is hours of NCBI traffic for the same
answer a few dozen requests give. And the old client cached every null for a
week, including the null it returned for a timeout, so a rate-limit block
during a nightly run was recorded as "no such paper" until the cache expired.
That is the failure the whole addon exists to refuse, sitting in its own
client. Separating "absent" from "could not ask" at the type level is what
stops it coming back.

**Rejected.** Keeping the per-PMID contract and looping. Simpler seam, and a
host binding its own client has less to implement, but the traffic is the
addon's problem on every site it is installed on, not the host's. A host
that only has a per-PMID client can loop inside its own `summariesForPmids()`.

## 2026-09-13: PubmedSource searches by author string and filters on modification date

**Decision.** `PubmedSource` runs `"{author}"[Author]` through esearch, paged
with an explicit `retmax`, then summarises every PMID through the metadata
seam. `since` becomes a `datetype=mdat` window.

**Why.** The author string is the only PubMed handle a host records, and a
name search is the honest shape of this source: an estimate that every
namesake shares. The fictional Josiah Carberry's search string returns a real
cardiologist's papers, which is the whole reason the review queue exists.
`retmax` is always sent because NCBI's default page is twenty, and twenty
papers with no error reads as a complete answer for a researcher with
sixty-three. Modification date rather than publication date, because "since
the last fetch" has to catch a 2015 paper that PubMed attached a DOI to
yesterday, and a publication-date window would not.

**Also settled here.**

- A PMID the search returned and the summary endpoint disowned makes the run
  partial. Two NCBI endpoints disagreeing is not a paper that does not exist.
- A missing contact email is reported as `RefusedLocally` by the fetch, with
  `isConfigured()` still true. "This site chose not to use PubMed" and "this
  site forgot to identify itself" are different findings and get different
  advice.
- Authors are names only. E-summary carries no affiliation or ORCID; those
  come from E-fetch's XML and are a later change, as with ORCID's contributors.
- ORCID's `[auid]` field in PubMed search is not used yet. It would return
  ORCID-anchored PubMed records, which are certain rather than estimated, and
  deserves its own source key rather than being folded into the name search.
