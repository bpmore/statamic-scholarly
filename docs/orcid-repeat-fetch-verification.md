# ORCID repeat-fetch fix verification

Date: November 2, 2026.

## Result

Fixed: repeating a full ORCID fetch reuses works with neither DOI nor PMID.
Source record identities remain scoped to the configured API record URL.
No title-based merging was added. Existing records without that identity
are not merged or deleted automatically.

## Checks performed

- Before the writer fix, the new corpus-based regression failed on all
  three storage modes: the repeat created two records instead of zero.
- With the fix, the complete suite on PHP 8.4.27, Statamic 6.39.0 and
  Laravel 13.39.0 passed: 453 tests, 1,804 assertions. This covers flat
  files, Eloquent UUIDs and Eloquent integer IDs.
- The PHP 8.2 minimum-dependency setup on Statamic 6.31.0 passed:
  299 tests, 1,042 assertions, excluding the Eloquent driver that needs 8.3.
- Tests cover changed titles, changed preferred summaries, retained record
  aliases, DOI arrival, approved edits, rejected works, equal-title works,
  legacy records without source identity, and separate researcher/API scopes.
- Real ORCID and PubMed calls for C. Titus Brown were repeated on the
  isolated Statamic 6.39.0 site with the fixed source installed. A separate
  unpublished test researcher was used, with automatic approval disabled.
- The first fixed fetch attached 126 records, including four new keyless
  ORCID records. A repeated full fetch created zero records and refreshed
  126. The saved entry IDs were identical before and after the repeat.
- Each of the four keyless records had its own distinct scoped source ID.
  All eight observed responses in these two runs were HTTP 200. The addon
  reused cached PubMed metadata where available.
- No original site content was changed. No notification command was run.
  The user's permitted PubMed contact email was only process configuration.

## Limits

Older keyless records do not carry proof of source identity. One identifiable
copy may be created on the first fetch after upgrading; later fetches reuse
it. Previously created copies still need manual review. Titles alone must
not be used to delete or merge them.

This check verifies repeat-fetch behavior, not that every publication is
correctly attributed. No simultaneous-worker test, real-service outage test,
or new browser/accessibility review was performed. No release was published
and nothing was submitted to the Marketplace.

Temporary logs and live result snapshots: `/tmp/scholarly-latest-20261102`.
