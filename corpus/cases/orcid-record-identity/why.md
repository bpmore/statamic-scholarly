A live fetch on November 2, 2026 returned four ORCID works with no DOI or
PMID. Each full refresh created four more entries. Preserve ORCID work
put-codes, scoped to the API record, so a repeat updates the same entry.

Two different work records with the same title stay separate. Source record
identity is a persistence lookup, not a new title or cross-source merge rule.
Existing entries without source identity cannot be matched by title safely.
