# Architecture Decision Records

Deciziile de arhitectură ale proiectului Validez, formalizate ca ADR-uri. Numărul unui ADR nu se reutilizează niciodată — un ADR abandonat rămâne cu numărul lui și `Status: Rejected`; unul înlocuit devine `Status: Superseded by ADR-XXXX`. Următoarea decizie primește următorul număr disponibil.

## Index

| ADR | Decision | Status |
|---|---|---|
| [0001](0001-hierarchy-adjacency-list-ltree.md) | Hierarchy: adjacency list + PostgreSQL ltree | Accepted |
| [0002](0002-node-vs-civic-subject.md) | Node vs CivicSubject | Accepted |
| [0003](0003-voting-model.md) | Voting model | Accepted |
| [0004](0004-trust-score-configuration.md) | Trust score configuration | Accepted |
| [0005](0005-project-license.md) | Project license (AGPL-3.0-or-later) | Accepted |

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
