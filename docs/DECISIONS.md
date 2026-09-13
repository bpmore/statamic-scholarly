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
