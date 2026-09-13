# The corpus

A fixed set of inputs whose answers are written down. It is what stops the
de-duplicator changing what it reports by accident.

Each case is a directory holding `input.json` (candidates as the sources
reported them) and `expected.json` (what de-duplication must produce).

## The rule

**Behaviour changes only by editing the corpus, in its own commit, with the
reason written down.** A change to the merging logic that alters a result and
does not touch a case is a defect.

Adding a de-duplication rule means adding its case first.

## What it is and is not

The corpus records what the code does, not what it ought to do. It cannot catch
a bug that is already shipping. Whether a rule is right is the job of that
rule's own tests: this catches a change nobody meant to make.

That distinction earns its keep here more than in most projects. De-duplication
output looks plausible when it is wrong. Two copies of one paper render as two
entries and somebody notices; two different papers merged into one render as a
single believable entry and nobody does. A scoring change that shifts a band
boundary silently re-routes real publications between approval, review and
discard, and no single-case unit test sees it.

## Sharing

There is no second implementation to stay in step with, and the corpus does not
depend on one to be worth running. If a second implementation ever exists,
sharing these cases is available and costs nothing to try. It is not designed
for.
