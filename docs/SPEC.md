# Specification: Scholarly

Scholarly aggregates, de-duplicates, scores and routes research publications from external
bibliographic sources to the correct researcher profile, then exposes a verified publication list for
frontend output.

This is the build specification for the add-on. The reasoning behind each settled question is in
[`DECISIONS.md`](DECISIONS.md); read that before choosing an approach, and add an entry when a
change settles something new.

---

## 1. Conventions inherited from the author's existing add-ons

Read before anything else. These are not proposals: they are observed in
[`bpmore/statamic-a11y-gate`](https://github.com/bpmore/statamic-a11y-gate), verified against
`statamic/cms v6.27.1` there and exercised here on the dogfood site at `v6.32.0`, and they settle
several questions an earlier draft of this spec left open.

| Property | Value | Source |
|---|---|---|
| Repository | `bpmore/statamic-scholarly` | The `statamic-` prefix is the account's convention |
| Package | `bpmore/statamic-scholarly` | Same |
| Composer type | `statamic-addon` | |
| Namespace | `Bpmore\Scholarly\` | Short form, not the full repo name, as `Bpmore\A11yGate\` is |
| Config file | `config/statamic-scholarly.php` | Named for the slug, not the short name |
| `extra.statamic` | `name` and `slug` | |
| License | **proprietary** | Free to use, not free to take. Section 12 |
| PHP | `^8.2` | Tested on 8.2, 8.3 and 8.4 |
| Statamic | `^6.27` | Version 6 only. No version 5 support |
| Testbench | `^10.11 \|\| ^11.0` | |
| Pest | `^3.8 \|\| ^4.0` | |
| `composer.lock` | **Not committed** | A package's consumer owns the lock |
| Docs | `README.md`, `CHANGELOG.md`, `CLAUDE.md`, `docs/DECISIONS.md` | |
| Prose | **No em dashes anywhere.** Use colons, commas, parentheses, or two sentences | |

**The service provider shape is verified, not assumed.** An add-on extends
`Statamic\Providers\AddonServiceProvider` and declares its surface as properties: `$config = true`,
`$fieldtypes`, `$commands`, `$tags`, `$scripts`, `$viewNamespace`, with `bootAddon()` for anything
needing explicit registration and `register()` calling `parent::register()` first. **Statamic
discovers anything in `src/Listeners` automatically** and binds it to the event named by its first
type hint, so listeners are not registered by hand.

## 2. The product principle

The sibling add-on's governing rule is that its claims are true, and the specific failure it refuses
is worth restating here because Scholarly has the identical one:

> A check that could not run must say so. A silent zero is indistinguishable from a pass.

Scholarly's version: **a source that failed must say so, loudly, in the result.** Zero publications
returned is indistinguishable from a researcher who has none, and from an expired credential, a
rate-limit block, a changed API, or a typo in an identifier. An add-on that silently renders an empty
list in all five cases is worse than one that renders nothing, because an empty list looks like an
answer.

Three consequences that constrain the design:

1. **Never present an unverified publication as verified.** The `candidate` state is not a weaker
   kind of `approved`; it is a different thing, and nothing on a public page may blur them.
2. **Never imply a confidence score is a guarantee.** A calibrated `0.94` means roughly ninety-four
   percent, which means roughly one in seventeen is wrong. Copy, CP labels and documentation say so.
3. **A partial fetch is reported as partial.** If three sources were configured and one failed, the
   run says which, and the publication list is not presented as complete.

## 3. Goals

1. **Authoritative publication lists**, verified by a human or arrived by a path certain enough not
   to need it.
2. **Solve disambiguation.** A name search returns every namesake on earth, so confidence must be
   computed, explainable and reviewable.
3. **Work on any Statamic site.** No assumption about collection names, blueprint handles, field
   handles, institution or country beyond what configuration states.
4. **Curation compounds.** A rejection is permanent, a confirmation improves later scoring.
5. **Be a good API citizen.** Distributed software hitting a shared public API anonymously is how an
   add-on gets its entire user base blocked. Section 6.

## 4. Non-goals

1. Clinical trials. Publications only.
2. Scraping Google Scholar. No official API exists and scraping violates its terms, so manual
   BibTeX and CSV import is the supported route.
3. Citation metrics: h-index, citation counts, alternative impact measures.
4. Bundling institutional credentials. Scopus and Web of Science subscriptions are consumed, never
   supplied.
5. Being a general bibliography manager. The unit of value is one researcher's verified
   publications, not a reference library.

## 5. Portability requirements

Each of these is a place an institutional assumption would otherwise leak in.

1. **The researcher collection is configured, not assumed.** One site uses `people`, another
   `staff`, `faculty` or `authors`.
2. **Researcher identifier field handles are configured.** Ship a field map with reasonable
   defaults; work when every handle differs.
3. **The add-on owns its publications collection**, created on install and named by config. It never
   adopts a host's existing collection.
4. **Affiliation patterns come from config and ship EMPTY.** A default naming any real institution
   is a bug.
5. **Entry access goes through Statamic's facades**, `Entry::make()` and `Entry::query()`, never
   Eloquent models directly, so the add-on works under the flat-file Stache and under
   `statamic/eloquent-driver` alike. Both are tested. Note that a host can have the driver installed
   while `entries.driver` is still `file`, so installing it is not the same as exercising it.
6. **No hardcoded user-facing English.** Rendered strings go through translation files.
7. **Multi-site aware.** A publication is not inherently site-scoped, so default to the default site
   with localization possible.
8. **Schema.org output is generic and optional.** Plain `ScholarlyArticle` JSON-LD a host can
   disable or override, with no dependency on any host schema package.
9. **Own every validation rule**: ORCID, PMID, DOI, Scopus Author ID, Web of Science ResearcherID,
   registered from the add-on's own service provider.
10. **Plain `text` fields with validation and input masks**, not a bespoke identifier fieldtype.

**PMID validation defaults to format-only.** A live existence check makes a network call during form
validation, which is defensible for one site and a poor default for distributed software. Ship it as
an opt-in config flag, off by default.

## 6. NCBI E-utilities compliance

A hard requirement, and the failure lands on consumers rather than on the author.

1. **Always send `tool` and a configured `email`.** NCBI's usage guidelines require both so they can
   contact a heavy user before blocking them. **Treat a missing email as a misconfiguration**:
   refuse to fetch and log an actionable error rather than fetching anonymously. Many installs each
   hitting NCBI unidentified is precisely how a shared block happens.
2. **Send `api_key` when configured**, and pace at ten requests per second with it, three without.
3. **Rate-limit centrally**, shared across every job and command, using Laravel's `RateLimiter` or a
   cache-backed token bucket. Per-call-site spacing is not a rate limit: concurrent jobs each pacing
   themselves still collectively exceed the ceiling.
4. **Cache successes and misses alike**, under a versioned key prefix, so a missing record is not
   re-fetched on every render.
5. **Never fetch on a render path.** Fetching happens in queued jobs and console commands only.

### 6.1 The metadata-fetcher seam

`PublicationMetadataFetcher` wraps the NCBI calls and resolves from the container, so a host that
already operates a PubMed client can bind its own implementation and keep one client and one cache
keyspace. Without the seam such a host fetches and caches the same PMID twice, and the two caches can
disagree.

The seam batches (`summariesForPmids()`, one request per hundred) and it throws when NCBI could not
be asked. Null at this seam means exactly one thing: NCBI answered, and said there is no such record.
A client that returns null for a timeout, and caches it, records a rate-limit block as a missing paper.

## 7. Data model

### 7.1 Researcher fields

Published as a fieldset the host imports, prefixed so they cannot collide with host fields:
`scholarly_verifier` (users), `scholarly_auto_approve_threshold` (float, Phase 2),
`scholarly_discard_floor` (float, Phase 2), and `scholarly_last_fetched`.

`scholarly_last_fetched` is a **per-source map**, not a scalar. A single scalar means a source added
later re-fetches nothing, because the scalar is already current.

Identifier fields are **read from the host's existing fields** through the configured map. The add-on
ships an optional fieldset for hosts that have none, but never requires its own.

### 7.2 Publication entry fields

`title`, `abstract`, `journal`, `volume`, `issue`, `pages`, `publication_date`, `pmid`, `doi`,
`authors` (ordered, each `{name, affiliation, orcid}`), `researcher` (one entry in the configured
collection), `source_apis`, `confidence_score` and `confidence_breakdown` (Phase 2),
`verification_status` (`candidate`, `approved` or `rejected`), `verified_by`, `verified_at`,
`external_urls`, `possible_duplicates`, `fetched_at`.

**One entry per (paper, researcher).** The claim is about the pair, so the status lives on the pair,
and two researchers who share a paper have two entries. See DECISIONS for the alternative and its cost.

**Bibliographic fields are stored, not computed.** A public package cannot rely on a host's PubMed
cache, and computing from PubMed yields nothing for the DOI-only works ORCID returns. Freshness comes
from the fetch job writing back into the stored snapshot.

### 7.3 De-duplication order

First hit wins: **DOI** normalized (lowercased, `https://doi.org/` and `doi:` stripped), then **PMID**
exact, then **fuzzy title plus year**. The last routes to the review queue only, never auto-merging
two entries.

## 8. Configuration

`config/statamic-scholarly.php`, published by `php artisan vendor:publish`:

```php
return [
    'researchers' => [
        'collection' => 'people',
        'fields' => [
            'orcid'          => 'orcid_id',
            'pubmed_author'  => 'pubmed_author_id',
            'scopus_author'  => 'scopus_author_id',
            'researcher_id'  => 'researcherid',
            'google_scholar' => 'google_scholar_author_id',
        ],
    ],

    'publications' => ['collection' => 'publications'],

    'ncbi' => [
        'tool'    => 'statamic-scholarly',
        'email'   => env('SCHOLARLY_NCBI_EMAIL'),   // required: no fetch without it
        'api_key' => env('SCHOLARLY_NCBI_API_KEY'), // optional: raises the rate ceiling
        'verify_pmid_exists_on_validation' => false,
    ],

    'sources' => [
        'orcid'          => [
            'enabled'      => true,
            'access_token' => env('SCHOLARLY_ORCID_ACCESS_TOKEN'), // optional: raises the ceiling
            'base_url'     => env('SCHOLARLY_ORCID_BASE_URL', 'https://pub.orcid.org/v3.0/'),
        ],
        'pubmed'         => ['enabled' => true],
        'scopus'         => ['enabled' => false, 'api_key' => env('SCOPUS_API_KEY')],
        'web_of_science' => ['enabled' => false, 'api_key' => env('WOS_API_KEY')],
    ],

    'affiliation_patterns' => [],   // ships EMPTY: institution-specific

    'scoring' => ['auto_approve_threshold' => 0.90, 'discard_floor' => 0.30],

    'schedule'      => 'daily',
    'notifications' => ['channels' => ['mail'], 'digest' => true],
];
```

Every credential comes from the environment. A missing credential disables its source with a log
line and a reported partial run, per section 2. It never fails silently and never fails the whole
fetch.

## 9. Fetch pipeline

```
scholarly:fetch {researcher?} {--source=} {--since=} {--sync}
  └─ FetchPublications job per researcher (every source, so de-duplication
       │                                   sees them all)
       ├─ for each source:
       │    ├─ source->isConfigured()       -> skip, report, mark the run partial
       │    └─ source->fetchForResearcher() -> FetchOutcome (the source takes its
       │                                       own rate-limit slot, section 6)
       ├─ de-duplicate (section 7.3)
       ├─ score (Phase 2) or mark candidate (Phase 1)
       ├─ write entries: rejected untouched, approved keeps its snapshot,
       │                 candidate refreshed (section 10)
       └─ scholarly_last_fetched[source] = run start, for sources that completed
```

```php
interface PublicationSource
{
    public function isConfigured(): bool;

    /**
     * Never a bare array: "found nothing" and "could not look" are different
     * answers, and FetchOutcome carries candidates, failures and the sources
     * that completed. `$since` is a date string; each source decides what it
     * filters on (ORCID: last-modified).
     */
    public function fetchForResearcher(Entry $researcher, ?string $since = null): FetchOutcome;

    public function sourceKey(): string;
}
```

Hosts register additional sources without forking the package.

### 9.1 Confidence scoring (Phase 2)

1. **Two tiers, not one sum.** A hard identifier match (ORCID, DOI, Scopus Author ID) is
   categorically certain and short-circuits scoring. ORCID is a gate, not a weighted term.
2. The probabilistic model runs **only** on unanchored name-string candidates.
3. **Weights calibrate from each researcher's ORCID-anchored seed set**, which supplies labeled
   positives for free. Any fixed weight table is an initial prior, not the final model.
4. **Name prevalence weights the prior.** A rare surname agreeing is far stronger evidence than a
   common one, and it should inform a per-researcher default threshold.
5. **Co-author overlap is expected to outrank affiliation.** Collaboration networks are stable;
   affiliation is noisy because researchers move and large institutions host many namesakes.
6. **Three bands**: auto-approve, review queue, and a discard floor below which candidates drop, so a
   common-name search does not bury verifiers in namesakes' papers.
7. **The feedback loop is durable.** Reject suppresses permanently, confirm enriches the fingerprint.

A calibrated score is the point: `0.90` must mean roughly ninety percent likely correct, or the
threshold is an arbitrary cut on an uncalibrated sum. Keep the per-signal breakdown visible in the
control panel, because that explanation is what makes auto-routing trustworthy.

## 10. Verification workflow

| Action | Effect |
|---|---|
| Confirm | `approved`, renders publicly, enriches the fingerprint (Phase 2) |
| Reject | `rejected`, **permanently suppressed**, never resurfaces on re-fetch |
| Edit and confirm | Correct the stored snapshot, then approve |
| Suggest PMID or DOI | Manual entry for works no source returned |
| Defer | Stays `candidate`, leaves the current notification batch |

**Rejection must be durable against re-fetch.** A source returning the same namesake paper nightly
must not re-queue it. Failing this destroys verifier trust, and the failure looks exactly like normal
operation.

## 11. Frontend output

```antlers
{{ scholarly:publications researcher="{id}" sort="publication_date:desc" limit="5" }}
  <article>
    <h3>{{ title }}</h3>
    <p>{{ authors | scholarly:format_authors }}, <em>{{ journal }}</em>, {{ publication_date format="Y" }}</p>
  </article>
{{ /scholarly:publications }}
```

Parameters: `researcher`, `sort`, `limit`, `year` (scalar or `2020:2024`), `journal`, `type`.

**Only `approved` entries are ever returned**, from the tag, the JSON feed and the schema output
alike. Candidate and rejected entries are unreachable from every frontend surface.

Phase 2 adds `scholarly:feed` (JSON), the `scholarly:citation` modifier (AMA, APA, Vancouver), and a
read-only control-panel fieldtype showing recent verified publications on the researcher entry.

Control-panel UI comes from Statamic's own component library rather than hand-rolled widgets.

## 12. Licensing and support

**Proprietary, and free to use.** No price, no license key, no per-site limit, no edition, and no
license check anywhere in the code. The proprietary license exists so the work cannot be taken
wholesale, not to gate access. Never write copy, a setting or a code path implying otherwise.

Support matrix per section 1: PHP `^8.2` tested on 8.2, 8.3 and 8.4, Statamic `^6.27`, no version 5.

Semantic versioning, honored strictly, shipping `0.x` while the data model settles. Breaking changes
to the publication entry shape after 1.0 need a migration path, because consumers will have verified
thousands of entries by hand and cannot re-verify them.

**Unchecked:** whether "Scholarly" is available as a marketplace listing name. `bpmore/statamic-scholarly`
is free on Packagist, though the bare `scholarly` vendor namespace is taken by an unrelated package.

## 13. The corpus

Adopt the sibling add-on's pattern: `corpus/cases/<name>/{input,expected}`, with a case per rule, and
**behavior changes only by editing the corpus, in its own commit, with the reason written down.** For
Scholarly the cases are candidate publications with expected de-duplication and scoring outcomes.

Two properties make it worth more here than in most projects. De-duplication and scoring are exactly
the logic whose output looks plausible when wrong, so a diff nobody meant to make is otherwise
invisible. And a scoring change that shifts a band boundary silently re-routes real publications
between auto-approve, queue and discard, which no unit test on a single case will catch.

**On sharing a corpus with another implementation.** Holding two implementations of the same rules
to one set of answers is an appealing idea and was tried once in this account, between the sibling
add-on and another project. It ended when that add-on was separated to stand on its own, and the
corpus was kept anyway, because what it is worth day to day never depended on anyone else running it.
That is the reasoning to inherit: **this corpus exists for this repository.** Sharing it later costs
nothing to attempt and is not designed for.

## 14. Testing

Testbench and Pest, in the package's own repository.

1. **A host with entirely different collection and field handles.** The most valuable test in the
   suite, because it is what proves section 5.
2. **Both entry drivers**, flat-file Stache and `statamic/eloquent-driver`.
3. **De-duplication** across all three keys, including one paper arriving from three sources with
   differing metadata.
4. **Rejection durability**: reject, re-run the fetch with the source still returning it, assert it
   does not reappear.
5. **No network on a render path.** Fake the HTTP client, assert zero requests during a render and a
   control-panel listing with a cold cache.
6. **NCBI compliance**: assert `tool` and `email` on every outbound request, and that a missing email
   refuses to fetch rather than fetching anonymously.
7. **A failed source is reported, not silently empty.** Section 2's principle, asserted directly.
8. **Only `approved` entries render.**
9. **The corpus**, section 13.
10. **Phase 2**: hard-anchor short-circuit, band boundaries, calibration, fingerprint enrichment.

Mutation-test before trusting a check: break the thing it guards and confirm the check fails.

Two Pest traps the sibling repo records, both live here: `expect($x)->not->toContain($needle,
"message")` is vacuously true because `toContain` takes needles rather than a failure message, and a
loop over a possibly-empty array asserts nothing, so assert the empty case directly.

### 14.1 Continuous integration

Follow the sibling add-on's workflow shape, which is stricter than most and for stated reasons:

- `composer validate --strict` **before** installing, so a malformed manifest is caught here rather
  than by Packagist in front of an audience.
- A **highest-dependencies** job and a **lowest-dependencies** job
  (`composer update --prefer-lowest --prefer-stable`). A floor in `composer.json` is a promise about
  a machine nobody runs, and only a runner that is that machine makes it true.
- No `composer.lock`, so every run is a fresh resolve against current Statamic. Going red on a commit
  that changed nothing means a dependency moved, which is a finding rather than a nuisance.
- Read-only permissions, and concurrency cancelling superseded runs.

## 15. Dogfooding

**On a dedicated site, and not on whichever host the add-on was written against.** This matters
more than it sounds. The portability requirements
in section 5 are exactly the class that passes a test suite and fails on a real host: a hardcoded
collection handle, an assumed field name, a driver-specific query. A Testbench suite the author also
wrote will not catch them. The add-on needs at least one real Statamic install, with real content and
a real researcher blueprint, that is **not** the host it was designed against. Standing that site up
is part of the build rather than an afterthought.

## 16. Phasing

**Phase 1**: the validation rules; the NCBI client with identification and central rate limiting;
`OrcidSource` and `PubmedSource`; de-duplication; manual verification with a control-panel review
panel; the `scholarly:publications` tag; mail notification; the corpus and its first cases. No
scoring and no auto-approval.

**Phase 2**: calibrated scoring with the three-band model and the explainable breakdown; Scopus and
Web of Science; BibTeX and CSV import; digest, bell and webhook notifications; `scholarly:feed`,
`scholarly:citation`, and the read-only fieldtype.

## 17. Open questions

1. **Marketplace listing name availability**, section 12.
2. **Default verifier** when a researcher has none: a configured fallback, the last editor, or no
   notification.
3. **Multi-site publication semantics**: default-site-only with cross-site rendering, or localized.
4. **Whether the `ProfilesRnsSource` idea is worth an optional source at all.** It was evaluated and
   rejected as a disambiguation engine because it returns binary membership rather than scores. As a
   corroborating source it is plausible and unproven.

## 18. Verification bounds

- The conventions in section 1 and the service-provider shape were read directly in
  [`bpmore/statamic-a11y-gate`](https://github.com/bpmore/statamic-a11y-gate) at its `main` tip, a
  working add-on whose own notes record verification against `statamic/cms v6.27.1`. They are
  observed, not inferred from documentation.
- The zero-em-dash convention was verified by grep across that repository: no file matches.
- The portability requirements in section 5 were derived by auditing one real Statamic host for
  institution-specific coupling. They are reasoned, and not yet exercised against a second host,
  which is what section 15 exists to fix.
- NCBI's rate ceilings and the `tool` and `email` requirement are stated from NCBI's published usage
  guidelines, not re-measured.
- No timing or coverage measurement appears in this document.

---

## Document history

| Version | Date | Summary |
|---|---|---|
| 1.0.0 | 2026-09-13 | Initial specification. |
| 1.0.3 | 2026-09-13 | One entry per (paper, researcher); `researcher` is singular; the pipeline diagram matches the job. |
| 1.0.2 | 2026-09-13 | Section 6.1: the metadata seam batches and throws; null means only an absent record. |
| 1.0.1 | 2026-09-13 | Matched to the code: `fetchForResearcher()` returns `FetchOutcome` and takes a string `since`; ORCID config keys; PHP 8.3 in the matrix; Statamic version the dogfood site runs. |
