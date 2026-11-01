# Scholarly release readiness

Review date: November 1, 2026.

Branch: `codex/scholarly-marketplace-readiness`, based on `7da6dbc` / `v0.3.0`.
Proposed next release: **0.3.1**, not tagged or published by this work.

## Result

The local release work is complete. The release is not yet submitted. Publish
the reviewed branch through a pull request, check the hosted test results,
then prepare the release download and public install/support access.

This review used the [current Marketplace submission guidelines](https://statamic.com/marketplace/submission-guidelines),
revision September 14, 2026, and the matching review skill kept in this repo.

## What changed

- Public tags, counts and feeds now require Statamic's published status as
  well as approval. Draft, scheduled and expired publications stay private.
- Private researchers are not exposed by id, slug or current-entry context.
- Confirmation copy explains that both the paper and researcher must be
  published before the paper can appear publicly.
- The install command in the README now selects the 0.3 line, not 0.1.
- The README explains permissions, scheduling and cache clearing after the
  visibility fix. Descriptions disclose automatic approval.
- `docs/marketplace.md` contains the listing draft and launch handoff.

No matching, scoring, de-duplication or license rules changed. No real
researcher data, credentials or external API calls were needed for the
clean-site checks.

## Verification performed

### Existing development dependencies

PHP 8.4.27: `php84 vendor/bin/pest --colors=never`.

Result: **435 passed, 1,719 assertions**. This includes file entries and both
UUID and integer Eloquent entry ids. The three new draft/scheduling
regressions first failed against the original implementation. The additional
expired-publication case holds the same native visibility boundary.

After the final confirmation-copy change, the action and file-output suites
were rerun: **21 passed, 100 assertions**.

### Lowest dependencies on PHP 8.2

An isolated source copy was installed using the workflow's
`--prefer-lowest --prefer-stable` setup. The optional Eloquent development
dependency was removed only from that copy, as the driver needs PHP 8.3.
Composer resolved Statamic **6.31.0**. No security-advisory checks were bypassed.

`php82 vendor/bin/pest --exclude-group=eloquent --colors=never`:
**291 passed, 999 assertions**.

This is not a claim that Statamic 6.27 was reinstalled or that PHP 8.3 was
rerun locally. The hosted matrix still needs to run on the new branch.

### Clean customer-style installation

Created a fresh official `statamic/statamic` **6.5.2 skeleton** in a temporary
folder. Its resolved CMS is **Statamic 6.39.0**, on PHP 8.4.27 and Laravel 13.
The skeleton version and the CMS version are separate package versions.

Installed the `v0.3.0` Git archive as a copied package through a temporary
Composer path repository, not a symlink into the development checkout.
Dependencies, package discovery and control-panel assets installed.

Ran the README's config publish and `scholarly:install`; running the install
again preserved the existing collection and fieldset. The test site uses
fictional profiles and papers only, with a normal local Statamic Pro trial
for the control-panel review. No license checks were bypassed.

Applied the tested source fix to the isolated installed package, then checked
the actual feed in the browser: the old output included an approved draft;
the fixed output removed it while retaining the two public approved papers.
A draft researcher's feed returned `No such researcher.` The automated tests
separately assert the 404 response status.

The final branch archive is also checked separately before handoff. A real
public registry download and the eventual release tag still need verification
after publication is approved.

### Real control panel

Verified in the browser on the clean site:

- Login, publication listing, verification fields and the status utility load.
- The status utility displays missing identifiers and the missing NCBI email,
  without claiming an empty bibliography is a successful fetch.
- Tab reaches the status page's review link with visible focus; Enter opens
  the publication listing.
- Confirming a fictional candidate changes its displayed state to Approved
  and records the reviewer's identity.
- A long-title fixture remains available in the listing. The observed
  browser viewport is about 689 pixels wide; this does not prove phone-size
  or 400% zoom behavior.

The screenshot from this local review contains fictional data. The five
existing listing screenshots remain in `docs/screenshots/`.

## Marketplace assessment

The public-output issue was a concrete Rule 07 concern and is repaired
locally, with adversarial cases rather than only successful examples.
The old install constraint and all-human-approval wording were Rule 09/10
issues and are corrected. Native collections, actions, tags, permissions,
and a read-only utility support Rules 02 and 03. Original purpose, license,
examples and documented data flows were retained.

Do not claim a complete accessibility audit, a complete security audit, or
Marketplace approval from these checks. A screen-reader walkthrough, a
phone-size/zoom pass, live ORCID/PubMed operation and every possible host
configuration were not checked in this release-preparation session.

## Remaining launch work

- [ ] Owner authorizes pushing the local branch; the repo requires explicit
  permission before push or merge (`CLAUDE.md`, Process).
- [ ] Open the pull request and verify the new hosted PHP checks.
- [ ] Review and merge, then tag the agreed patch release.
- [ ] Enable working public source/support and Composer access at launch;
  update the README's private-access paragraph when that is true.
- [ ] Install the actual published release download in a clean site.
- [ ] Complete the remaining hands-on accessibility checks.
- [ ] Confirm listing name availability, attach the actual screenshots,
  and submit the reviewed listing.

For existing installs, purge cached publication pages and feeds when taking
the fix. Old browser/CDN responses cannot be revoked by changing the query.
