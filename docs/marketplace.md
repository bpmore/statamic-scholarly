# Scholarly: Marketplace submission draft

Status: prepared for review, not submitted. Use v0.3.2, which contains the
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

## Listing content and screenshots

Use the current [README](https://github.com/bpmore/statamic-scholarly#readme)
as the listing body. It is the maintained installation and usage guide.
The README gallery links to six public captures in `docs/screenshots/`.
All people, papers and contact details in these captures are fictional.
The local demo's Statamic Pro trial label is visible.

The older separate long description has been replaced by the README, as
requested by the owner. Do not paste these handoff notes into the listing.

## Launch handoff

- Confirm the listing name is available in the actual submission form.
- Both fixes are merged through pull requests #25 and #26. All PHP version
  checks passed. The release target is v0.3.2.
- Confirm the release download installs, not only the working checkout.
- The repository and issue tracker are public. Packagist lists
  `bpmore/statamic-scholarly`; confirm its latest release is indexed.
- Upload the product screenshots and use README.md for the listing body,
  excluding these handoff notes.
