# Scholarly: Marketplace submission draft

Status: prepared for review, not submitted. Use v0.3.1, which contains the
public-visibility and repeated-ORCID-fetch fixes described in CHANGELOG.md.

## Listing fields

Name: **Scholarly**

Short description: **Match publications to researcher profiles, review uncertain matches, and publish approved lists.**

Price: **Free**

License: proprietary, free to use on any number of sites. No license key or
paid edition. The package license limits redistribution and reuse.

Compatibility: Statamic 6.27 or newer within version 6, PHP 8.2 or newer.
The optional Eloquent entry driver needs PHP 8.3. Sites using multiple
editors, roles or multisite need the relevant Statamic Pro features.

## Long description

One name can belong to many researchers. A publication search is a starting
point, not proof that a paper belongs on someone's profile.

Scholarly brings publications from ORCID and PubMed into Statamic, matches
them to researcher profiles, and gives editors a queue for uncertain
results. It also imports BibTeX and CSV, including exports from Google
Scholar. It does not scrape Google Scholar.

Papers with the same DOI or PMID share a record. A matching title is flagged
for review rather than silently merged: an erratum and its paper can share
a title. Confirm, reject or defer a candidate from Statamic's own publication
listing. A rejected match stays rejected when the source returns it again.

### Approval that says what it knows

Each match shows its reasons and its confidence state. The starting score
orders the queue; it is not a measured probability and never approves a
paper by itself.

With automatic approval on, an ORCID claim or a score calibrated from this
site's decisions can approve a paper without human review. The entry says
when approval was automatic, and an editor can reverse it. Turn automatic
approval off if every match must be reviewed by a person.

Approval and publication are separate decisions. Draft, scheduled and
expired papers stay out of public output. A draft or otherwise private
researcher has no public feed.

### Built into Statamic

- Publications are entries with editable fields and relationships to the
  site's researcher collection.
- Researcher collection and identifier field names are configurable.
- Confirm, Reject and Defer use native actions with an explicit permission.
- A read-only status page shows the review queue, researcher setup, sources,
  last fetch, scoring and notification settings.
- Antlers tags return approved, published papers with year and journal
  filters, sorting, limits and pagination.
- AMA, APA and Vancouver citation formats use the saved record and leave
  missing facts out.
- An optional public JSON feed uses the same publication rules as the tags.
- Both flat-file entries and the Eloquent driver are supported.

### When a source cannot answer

A failed request is not an empty publication list. Fetch reports name the
source that failed and distinguish a complete run, a partial run and a run
that could not look. No publication service is queried while a page renders.

Scheduled fetches and review digests run through the site's scheduler and
queue. Mail uses the site's configured mail service. Optional webhooks send
signed review digests to the site's chosen endpoint.

### Setup and limits

PubMed requires a contact email. API keys and an ORCID access token are
optional and come from the site's configuration. ORCID and PubMed requests
are made by the site's server; those services' own access policies apply.

Scopus and Web of Science are not included. Scholarly does not calculate
citation counts or h-indexes, promise a complete bibliography, or guarantee
that a name match is correct. Version 0.x means the data model may still
change; read the changelog before upgrading.

Free to use on any number of sites. Source, installation instructions,
configuration and support are at the project repository once public access
is enabled for release.

## Screenshots

Use actual product captures already in `docs/screenshots/`:

1. `01-publication-review-listing.png`: publication statuses and matching state.
2. `02-row-actions.png`: review actions on a publication.
3. `03-entry-verification.png`: the entry's verification fields and reasons.
4. `04-researcher-page.png`: the researcher's review summary.
5. `05-tools-page.png`: source and queue status.

Retain accurate captions and avoid claiming that a candidate is verified.
These files remain in the source repository and are excluded from the
Composer download. The new local review uses fictional data only.

## Launch handoff

- Confirm the listing name is available in the actual submission form.
- Both fixes are merged through pull requests #25 and #26. All PHP version
  checks passed. The release target is v0.3.1.
- Confirm the release download installs, not only the working checkout.
- Make source and issue-tracker access work for customers at launch and
  register/check the public Composer package. Do not change visibility
  without the owner's approval.
- Update the private-repository installation paragraph in README.md when
  the repository and package become public.
- Upload the product screenshots and paste only the listing fields and long
  description into the Marketplace form, excluding these handoff notes.
