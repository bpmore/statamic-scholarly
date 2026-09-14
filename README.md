# Scholarly

Builds a verified publication list for a researcher profile in Statamic.

Publications are pulled from ORCID and PubMed, de-duplicated across sources,
and put in front of a person to confirm before any of it appears on a site. The
hard part is not fetching: it is knowing which of the many people sharing a name
actually wrote the paper.

> **Status: early.** The data model may still change while the major version is
> 0. See the changelog.

## What it does

- Fetches from ORCID and PubMed, with Scopus and Web of Science to come.
- De-duplicates on DOI, then PMID, then title. A title match is flagged for a
  human rather than merged, because an erratum and the paper it corrects share a
  title and are different works.
- Routes every candidate through a review queue. Nothing reaches a public page
  without a person confirming it.
- Renders an approved list through an Antlers tag.

## What it will not do

- **It will not tell you a publication is definitely someone's work.** Outside
  an ORCID claim it is matching names, affiliations and topics, and a match is
  an estimate. A publication list on a profile is a claim about a named person,
  so a person decides what goes on it.
- **It will not report an empty list as an answer when it could not look.** A
  source with a missing credential, a failed request or an unrecorded identifier
  is reported as a failure, because zero publications is otherwise
  indistinguishable from a researcher who has published nothing.
- It will not scrape Google Scholar. There is no API and scraping breaks its
  terms. Manual BibTeX and CSV import is the supported route.
- It will not compute citation metrics.

## Installing

```
composer require bpmore/statamic-scholarly
php artisan vendor:publish --tag=statamic-scholarly-config
```

Then set a contact email. **The addon refuses to query NCBI without one:**

```
SCHOLARLY_NCBI_EMAIL=you@example.org
SCHOLARLY_NCBI_API_KEY=optional-but-raises-your-rate-limit
SCHOLARLY_ORCID_ACCESS_TOKEN=optional-too
```

NCBI asks every client to identify itself so they can make contact before
blocking a heavy one. An addon installed on many sites that skipped this would
make all of them anonymous traffic, and the block would land on site owners who
did nothing. Refusing is the safe direction.

## Running a fetch

```
php please scholarly:install          # creates the publications collection
php please scholarly:fetch --sync     # every researcher, now, printing each report
php please scholarly:fetch            # every researcher, on the queue
php please scholarly:fetch jane-doe --source=pubmed --since=2024-01-01
```

The schedule in config runs `scholarly:fetch` daily. A report line says
`complete` or `PARTIAL`, and a partial run names the source that could not
answer, because zero publications from a source that never ran is not an
answer.

## Verifying

Give a role the **Verify publications** permission. "Publication review" in
the control panel opens the publications listing; filter it to
`Status: Candidate` and use the Confirm, Reject and Defer actions on a row or
a selection. To correct a record first, edit the entry, then Confirm it.

Confirming records who did it and when. A rejected paper is never brought
back by a fetch, though a person can confirm it later.

## Configuring which fields hold identifiers

The addon does not assume your field handles. Point it at whatever you use:

```php
'researchers' => [
    'collection' => 'people',
    'fields' => [
        'orcid'         => 'orcid_id',
        'pubmed_author' => 'pubmed_author_id',
        // ...
    ],
],
```

A wrong handle fails quietly: the field reads empty, nothing is found, and that
looks exactly like a researcher with no publications. Check these first when a
fetch finds less than you expected.

## Rendering

```antlers
{{ scholarly:publications researcher="{id}" sort="publication_date:desc" limit="5" }}
    <article>
        <h3>{{ title }}</h3>
        <p>{{ authors | scholarly_authors:3 }}, <em>{{ journal }}</em>, {{ publication_date format="Y" }}</p>
    </article>
{{ /scholarly:publications }}
```

Only approved publications are ever returned, and there is no parameter to
change that. `researcher` takes an id or a slug and defaults to the current
entry; `year` takes `2020` or `2020:2024`; `sort`, `limit`, `paginate`, `as`
and `journal` work as on the collection tag. `scholarly_authors:3` joins the
first three author names and adds "et al." when there are more.
`{{ scholarly:publications_count researcher="{id}" }}` gives the number.

## Adding your own source

Implement `Bpmore\Scholarly\Contracts\PublicationSource` and bind it. A source
returns a `FetchOutcome` rather than an array, so it can report that it could not
run rather than returning nothing and being mistaken for an empty result.

If your application already has a PubMed client, bind
`Bpmore\Scholarly\Contracts\PublicationMetadataFetcher` to it. Otherwise you get
two clients caching the same records under different keys, and the two caches
can disagree.

## Licence

Free to use on any number of sites, with no key and no limit. Not free to
redistribute or to build another product from. See [LICENCE](LICENSE.md).
