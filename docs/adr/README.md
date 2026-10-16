# Architecture Decision Records

Deciziile de arhitectură ale proiectului Validez, formalizate ca ADR-uri. Numărul unui ADR nu se reutilizează niciodată — un ADR abandonat rămâne cu numărul lui și `Status: Rejected`; unul înlocuit devine `Status: Superseded by ADR-XXXX`. Următoarea decizie primește următorul număr disponibil.

## Index

| ADR | Decision | Status |
|---|---|---|
| [0001](0001-hierarchy-adjacency-list-ltree.md) | Hierarchy: adjacency list + PostgreSQL ltree | Accepted |
| [0002](0002-node-vs-civic-subject.md) | Node vs CivicSubject | Accepted |
| [0003](0003-voting-model.md) | Voting model | Accepted, amended by ADR-0007 |
| [0004](0004-trust-score-configuration.md) | Trust score configuration | Accepted |
| [0005](0005-project-license.md) | Project license (AGPL-3.0-or-later) | Accepted |
| [0006](0006-initial-contribution-moderation.md) | Initial contribution moderation and category proposals | Accepted |
| [0007](0007-votes-revisions-and-privacy.md) | Votes: revisions, freezing, private data and integrity | Accepted |
| [0008](0008-public-urls-slugs-and-indexing.md) | Public URLs with slugs and web indexing (SEO shell) | Accepted |
| [0009](0009-erasure-registry-for-restores.md) | Erasure registry reapplied after database restores | Accepted |
| [0010](0010-access-logs-and-vote-routes.md) | Access logs without vote routes | Accepted |
| [0011](0011-subject-media-links-and-location.md) | Subject media, links and location | Accepted |
| [0012](0012-administrative-geography-as-separate-dimension.md) | Administrative geography as a separate dimension (SIRUTA, derived from pin) | Accepted |

## Template

```markdown
# ADR-XXXX: Titlu

**Status:** Proposed | Accepted | Rejected | Superseded by ADR-YYYY
**Date:** YYYY-MM-DD

## Context

De ce trebuie luată această decizie și ce problemă rezolvăm.

## Decision

Decizia adoptată.

## Rationale

De ce am ales această variantă.

## Consequences

### Positive
- ...

### Negative / Trade-offs
- ...

## Alternatives considered

- ...
- ...

## Security / Transparency implications

Dacă este relevant.

## References

- ADR-uri asociate
- documentație externă
```
