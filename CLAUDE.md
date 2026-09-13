# CLAUDE.md

Rules for any model working in this repository. Most of them exist to protect
one thing: this addon makes claims about named people, and those claims have to
be honest about how sure they are.

## THE PRODUCT

- **Never present a candidate as verified.** `candidate` is not a weaker
  `approved`, it is a different thing. Nothing on a public surface may blur them.
- **Never state that a publication is a researcher's work.** Outside an ORCID
  claim the addon is matching names, affiliations and topics. It reports an
  estimate, and the wording everywhere has to say so.
- **A confidence score is a probability, not a guarantee.** A calibrated 0.94
  means roughly one in seventeen is wrong. Copy, labels and docs say so. An
  uncalibrated score is worse than none, because the threshold on it means
  nothing.
- **A source that could not run must say so.** This is the one that matters
  most. Zero publications is indistinguishable from a researcher who has none,
  an expired credential, a rate-limit block, a renamed API field and a typo in
  an identifier. A run that cannot tell you which has not answered the question.
  `FetchOutcome` exists for this and must not be flattened to an array.

## THE CORPUS IS THE CONTRACT

Every de-duplication rule has at least one fixed case whose answer is written
down, in `corpus/`.

- **Behaviour changes only by editing the corpus, in its own commit, with the
  reason written down.** A merging change that alters a result and does not
  touch a case is a defect.
- Adding a rule means adding its case first.
- The corpus records what the code does, not what it ought to do. Whether a rule
  is right is the job of that rule's own tests; this catches a change nobody
  meant to make.

It matters more here than in most projects because de-duplication output looks
plausible when it is wrong. Two copies of one paper render as two entries and
somebody notices. Two different papers merged into one render as a single
believable entry and nobody does.

## PORTABILITY IS THE FEATURE

This addon runs on sites nobody here has seen.

- **Never hardcode a collection name, a blueprint handle or a field handle.**
  They come from config. `ResearcherFieldMap` is where that lives and the tests
  for it deliberately use handles that differ from the shipped defaults.
- **Never ship an institution's affiliation string as a default.**
  `affiliation_patterns` ships empty.
- **Entry access goes through Statamic's facades**, never Eloquent models, so
  the addon works under the flat-file Stache and under the Eloquent driver. Note
  that a host can have the driver installed while entries are still on files, so
  installing it is not the same as exercising it.
- The pure logic stays framework free: de-duplication, identifier formats, the
  field map and the NCBI query builder take values and return values. That is
  what lets them be tested without booting anything.

## STACK

- PHP 8.2 or newer, tested on 8.2, 8.3 and 8.4. Composer. PSR-4. A Statamic
  addon is an ordinary Laravel package.
- Statamic 6. The provider extends `AddonServiceProvider` and declares its
  surface as properties. Statamic discovers anything in `src/Listeners` and binds
  it to the event named by its first type hint, so listeners are not registered
  by hand.
- Control-panel UI comes from Statamic's own component library. Do not hand-roll
  a widget.
- **Nothing fetches on a render path.** Every network call is in a queued job or
  a console command. A computed field that fetches turns a control-panel listing
  into a serialised run of network calls.

## TESTING

- Mutation-test before trusting a check: break the thing it guards and confirm
  the test fails. A test that passes against broken code is not a test.
- `expect($x)->not->toContain($needle, "message")` is vacuously true.
  `toContain` takes a list of needles, not a failure message. Same for
  `toBeNull()`. Use `in_array(...)` or a boolean with `toBeTrue($message)`.
- A loop over a possibly-empty array asserts nothing. Assert the empty case
  directly.
- **Check your fixtures are realistic before believing a failure.** A
  de-duplication case using a DOI with a short registrant prefix once looked
  exactly like a merging bug. The code was right and the test data was not.
  `corpus/cases/short-doi-prefix-is-not-a-doi` pins it.

## WRITING

- No em dashes anywhere: not in code comments, commit messages, docs, or
  user-facing copy. Use colons, commas, parentheses, or two sentences.
- Comments say why, and name the failure that motivated the rule.
- Commit messages and pull request bodies say why, and name what was checked and
  what was not.

## PROCESS

- Branch, work, run the tests, open a pull request. Never push to main directly.
- Never push or merge unless asked.
- Read `docs/SPEC.md` for what is being built and `docs/DECISIONS.md` before
  choosing an approach. Add a decision entry when your change settles a
  question, including the alternatives you turned down, and keep the spec
  matching the code.
