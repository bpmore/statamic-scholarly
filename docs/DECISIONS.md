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

## 2026-09-13: OrcidSource reads work summaries only (superseded 2026-09-14, see below)

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

## 2026-09-13: One publication entry per paper, with a researchers list

**Decision.** The owned collection holds one entry per paper. `researchers`
lists every tracked researcher a fetch has attached, and `verification_status`
is the entry's. Papers are co-authored, and a shared paper is one record.

**Consequence, and the rule that contains it.** Approval is for every name
listed, so attaching a name is a claim about that person. A fetch may attach
a researcher to a candidate freely. If it would attach a researcher to an
APPROVED entry, the entry goes back to candidate with the name added: the
approval was a person's decision about the names it had, and a PubMed name
match could otherwise attach a namesake to a paper someone already confirmed.
A rejected entry gains no names from a fetch at all; a verifier attaches a
co-author to one by hand, which is the confirmation.

**Rejected.** One entry per (paper, researcher), each with its own status. It
isolates every verifier's decision and queries the same on both entry
drivers, but it stores a shared paper twice and lets one copy drift from the
other under editing. Raised, and turned down in favour of the shared record.

**Cost.** A department where many tracked researchers co-author sees shared
papers return to the queue as each co-author's first fetch attaches them. The
per-researcher status grid that would avoid this is not portably queryable.

## 2026-09-13: The fetch protects a person's work from the machine

**Decision.** What a re-fetch does to an existing entry depends on its status.
`rejected`: untouched. `approved`: bibliographic fields untouched; only a
missing DOI or PMID and the source list are added (and see the entry above
for a researcher not yet listed). `candidate`: snapshot rewritten, researcher
added. Matching is on DOI then PMID across the collection, never on title.

**Why.** Rejection durability is the spec's own rule, and its failure looks
exactly like normal operation. "Edit and confirm" corrects the snapshot, so a
refresh of an approved entry would undo the correction on the next night.
Adding an identifier never destroys an edit, so that much is still written.

**Also settled here.**

- `scholarly_last_fetched` on the researcher is a per-source map of the time
  the run started, advanced only for sources that completed. A source that
  failed is asked for the same window again next time. A source that is not
  configured is reported as skipped, makes the run partial, and is not stamped.
- One job per researcher, not per source: de-duplication needs every source's
  candidates in one place. The job carries the researcher's id, not the entry.
- The owned collection is created by `scholarly:install` and by the first
  fetch that finds it missing. A missing collection at two in the morning is
  created, not turned into a night of failed jobs. It is never a host's
  existing collection: if the handle exists, it is used as is.
- Entries are slugged by their id. Titles collide (an erratum and its paper),
  and Statamic writes the file by slug, so a title slug is a silent overwrite.
- Title matches are linked both ways in `possible_duplicates`, within a run
  and against entries from earlier runs, through a stored `title_key`. Two
  entries that already share a DOI or PMID are never title-linked: apart,
  they were separated on purpose. Entries written before the key existed
  gain it on their next refresh; an approved one gains it from its stored,
  possibly corrected, title.
- A refresh writes only the values the run has. A PMID-only source tonight
  does not erase the DOI a DOI-only source stored last night, and
  `source_apis` is the union of every source that ever reported the paper.

## 2026-09-13: The review panel is the collection listing plus actions

**Decision.** Verification happens in the owned collection's own listing and
entry pages. Confirm, Reject and Defer are Statamic actions; a permission
(`verify scholarly publications`) gates them; a nav item, "Publication
review", opens the listing. The status, researchers, sources and verifier are
listable columns, and Statamic's field filter narrows the list to candidates.

**Why.** Statamic already has a listing with filters, bulk selection, row
actions with confirmation dialogs and optional fields, and an entry editor.
"Edit and confirm" is the entry editor followed by the Confirm action. A
page of its own would reproduce all of that in Vue for the sake of a
pre-applied filter, and the repository's rule is that control-panel UI comes
from Statamic's own components.

**Rejected.** A custom CP page listing candidates. Also a dashboard widget
showing the queue length: it is a fine idea, but it is a second surface to
keep honest about partial runs, and the listing is enough to start with.

**Also settled here.**

- Every transition records `verified_by` and `verified_at`. Reject takes an
  optional reason. Confirm is offered on a rejected entry too: a person may
  reverse another person's decision, and the record shows who did.
- Defer stamps `deferred_at` and changes nothing else. Notifications, when
  they exist, leave deferred candidates out of the current batch.
- The verifier refuses an entry outside the owned collection rather than
  writing a status onto a page.

## 2026-09-14: The tag has no parameter for anything but approved

**Decision.** `{{ scholarly:publications }}` returns approved entries, and no
parameter widens that. Filters narrow (year, journal, limit); none of them
can reach a candidate or a rejected entry.

**Why.** A candidate on a public page is the addon presenting an estimate as
a claim, which is the one thing it must never do. A `status` parameter would
be used, in good faith, by a template author who wanted "everything we
found", and the page would look finished.

**Rejected.** `status="any"` for preview templates. The control panel is the
preview.

**Also settled here.** Author names are joined as the source gave them
("Carberry J"). Reformatting a name is a guess about which part is the
surname, and a wrong guess renders a real person's name wrong.

## 2026-09-14: Notification reads state, not the run

**Decision.** `scholarly:notify` mails each verifier about candidates with no
`notified_at`, stamps them, and leaves deferred ones out. It is scheduled at
the same frequency as the fetch and can be run by hand at any time.

**Why.** One fetch is many queued jobs, and "the run is over" has no moment
a notification can hang on without a batch table the host must migrate.
State has no such problem: whatever is waiting and unmentioned gets
mentioned once, whether the fetch was queued, synchronous, or three days
ago. The cost is latency: a queued fetch's candidates are mailed on the next
notify pass, a day later at most on the default schedule.

**Rejected.** Mailing from the job after each researcher. One mail per
researcher per night, and no digest possible. Also Laravel job batches with
a completion callback: exact, but they need a database table on the host,
and the addon must work on a flat-file site.

**Also settled here.**

- Open question 2. A researcher's `scholarly_verifier` gets the mail; with
  none, the configured fallback address; with neither, nobody, and the run
  says so in its output and the log and returns failure. The candidates stay
  unstamped, so they are mentioned as soon as a verifier is set.
- The last run's verdict is written to the researcher as
  `scholarly_last_run`, every time. The mail says PARTIAL per researcher
  from it, and the control panel can show it without reading a log.
- The mail never calls a candidate a publication. The subject counts
  "candidate publications"; the lines count "new candidates".

## 2026-09-14: The entry-heavy suite runs under three drivers

**Decision.** The pipeline, tag, verification, notification and command
tests are written once, in `tests/Cases`, and run three times: on the
Stache, on the Eloquent driver with uuid entries, and on the Eloquent driver
with auto-increment entries.

**Why.** The spec promises both drivers, and the first run under Eloquent
found four defects the Stache suite could not: the writer set entry ids
itself, which fights an auto-increment column; three classes typed
researcher ids as strings where the driver hands out integers; a strict
in_array between a stored id and a live one failed on type; and the tag's
JSON containment on `researchers` was typed, so an id a host's entries
field stored as an integer never matched the string the addon stores. Every
one of those is a silently empty result on a real site.

**Rejected.** A single Eloquent shape. A site that imported its files has
uuid entries and one installed fresh has integers, and the writer must not
know which.

**Rule that follows.** An entry id is a string inside the addon, cast at
every boundary, and a stored list of ids is matched in both forms.

**One limit.** The Eloquent driver for Statamic 6 needs PHP 8.3, so the 8.2
job runs the Stache suite only. A host on 8.2 cannot install the driver
either, so nothing is promised there that is not tested.

## 2026-09-14: Affiliations, author iDs and abstracts come from a second request, and its failure makes the run partial

**Decision.** After the listing call, each source makes one request per
hundred records for the full form: PubMed `efetch` (XML) for author
affiliations, author ORCID iDs and the abstract; ORCID's bulk works endpoint
for contributors and the description. If that second request cannot answer,
the candidates still go out with what the listing had, a failure is recorded,
and the source is not marked as run, so the last-fetched stamp does not
advance and the next run asks for the same window again.

**Why.** These are the fields disambiguation runs on: an affiliation matching
the site's patterns, or an author's ORCID iD, is the evidence that a name
match is this researcher. Leaving them empty was the earlier decision, taken
before the partial-failure rule existed; the rule is the one above and it is
the same rule every other failure follows. Enriched-or-partial is honest;
enriched-or-silently-bare is not.

**Rejected.** Replacing E-summary with E-fetch outright, one request instead
of two. It would change the metadata seam's return shape from NCBI's summary
row to a shape of this addon's, and a host with an E-summary client of its
own could no longer bind it. Two requests, both cached for a week, cost
little.

**Also settled here.** PubMed records an author's ORCID iD as a bare id, a
URL, or with a typo; only a well formed id is kept, because a wrong id on an
author line is worse than none. Author names from E-fetch are rendered in
E-summary's form ("Carberry J") so the two line up.

## 2026-09-14: Scoring starts with signals, a breakdown and a stated state; no bands yet

**Decision.** Every candidate gets `confidence_score`, `confidence_breakdown`
(each signal and each weight's contribution) and `confidence_state`. The
state is one of three: `certain` (an anchor: the researcher's own ORCID iD
on an author line, or a work they claimed on their record; the number is
1.0 and is not a score), `uncalibrated` (the hand-set prior; an ordering,
not a probability) or `calibrated` (fitted to this site's decisions; not
built yet). Nothing is auto-approved and nothing is discarded on the score.

**Why.** A calibrated 0.90 means roughly nine in ten. An uncalibrated 0.90
means nothing, and a threshold on it is an arbitrary cut. Calibration needs
labelled decisions, which a new site has none of, so the first honest thing
the score can be is an ordering with its reasons attached. The state field
is what stops the number being read as more than it is: copy, columns and
any later routing read the state before the number.

**The signals, and why each.** Name match (full or surname only, from a
blunt parser that reduces every source's form to surname and initials and
never decides which J. Carberry is which). Affiliation match against the
site's patterns or against affiliations seen on the researcher's own author
line on certain papers. Co-author overlap with the certain papers, the
signal the spec expects to outrank affiliation. Journal seen on a certain
paper. Corroboration: how many sources reported the paper. Name prevalence:
how many PubMed records the author string matches, from the esearch count
the PubMed source already receives, stored on the researcher as
`scholarly_name_prevalence`.

**The fingerprint** is built only from certain papers: approved entries,
and candidates that arrived as an ORCID claim or carry the researcher's own
iD. A name-matched candidate contributes nothing, or the fingerprint would
learn a namesake's co-authors and then score the namesake's next paper up.
Confirming and rejecting rescore every candidate of the researchers on the
entry, which is the feedback loop the spec asks for.

**Rejected.** Auto-approval and the discard floor on the prior. Both exist in
config and both do nothing until a calibrated model exists. Also replacing
the prevalence count with a name-frequency table: the esearch count is
free, current and specific to the exact author string.

**Open.** What calibration is fitted from (this site's decisions pooled
across researchers, per researcher, or both), how many labels it needs
before the state may say `calibrated`, and what "discard" does to an entry.
Those are product decisions and are put to the owner rather than made here.

## 2026-09-14: Calibration is pooled, pulled toward the prior, and refuses to overclaim

**Decision.** `scholarly:calibrate` fits the same logistic terms the prior
uses to every decision on the site: approved entries and ORCID-anchored
candidates as positives, rejected entries as negatives, pooled across
researchers. The fit is L2-penalised toward the PRIOR rather than toward
zero, so with no decisions it is the prior and with few it barely moves.
It refuses to save below `scoring.min_labels` (30) decisions with
`min_labels_per_class` (10) of each kind, and refuses when its
cross-validated Brier score is worse than the prior's. The report prints
reliability bins ("predicted 0.90 to 1.00: observed 0.87") so "0.90 means
nine in ten" is a number a person can read, not a claim. The model is one
JSON file on the local disk; a cache flush cannot un-calibrate a site.

**Why pooled.** Most researchers never make enough decisions to fit anything
of their own; the prevalence term already carries the per-person adjustment.
Chosen by the owner from the three options put to them.

**Why leave-one-out.** A certain paper's co-authors are in the fingerprint
because it is certain. Measured against that fingerprint, every positive
would show perfect overlap and the model would learn that certain papers
have perfect overlap, which is circular. Each example's signals are
computed against the other certain papers only.

**Rejected.** Fitting from zero (a handful of decisions would swing every
weight); calling the fit calibrated regardless of how many decisions it
saw (the state field would lie); storing the model in the cache.

**Still open, put to the owner.** Whether anything ACTS on a calibrated
score: the spec's auto-approve band conflicts with the README's promise that
nothing reaches a public page without a person, and "discard" has no defined
effect on an entry. `auto_approve_threshold` and `discard_floor` remain
inert until that is decided.
