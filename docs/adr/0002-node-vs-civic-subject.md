# ADR-0002: Node vs CivicSubject

**Status:** Accepted
**Date:** 2026-09-12

## Context

Trebuie decis dacă fiecare „problemă"/subiect civic (ex. o sesizare, o propunere, o promisiune electorală) e un nod distinct în arbore, sau dacă arborele reprezintă doar structura, cu conținutul votabil separat.

## Decision

`TreeNode` reprezintă exclusiv structura ierarhică a arborelui. `CivicSubject` reprezintă conținutul civic asociat unui `TreeNode` (votabil, comentabil, moderabil), cu `type` extensibil: `ISSUE`, `PROPOSAL`, `PROJECT`, `PROMISE`, `ELECTORAL_EVALUATION`, `PETITION`, `TOPIC_EVALUATION`. Un nod poate avea simultan copii (alte noduri) și mai multe `CivicSubject` asociate. `Vote`, `Comment` și `Flag` aparțin întotdeauna de `CivicSubject`, niciodată direct de `TreeNode`.

## Rationale

- Un singur nod din arbore (`Drumuri > Cluj > Calitate`) poate avea în realitate 1.000 de sesizări diferite — dacă fiecare ar fi obligată să fie un nod distinct, arborele ar exploda structural și s-ar amesteca navigarea (structură) cu conținutul (instanțe votabile).
- Un nume generic (`CivicSubject`) în loc de `Issue` evită o refactorizare majoră când apar tipuri noi de conținut (promisiuni electorale, evaluări de mandat, petiții) — toate folosesc același mecanism de `Media`, `Vote`, `Comment`, `Flag`, `Authority`, workflow.
- Evaluarea aleșilor se leagă natural de acest model: `Node > Aleși > Primar` ca structură, iar `CivicSubject type=ELECTORAL_EVALUATION` ca instanță votabilă sub acel nod.

## Consequences

### Positive
- Arborele rămâne o structură stabilă, ușor de navigat și de reorganizat (vezi ADR-0001).
- Adăugarea unui tip nou de conținut civic (ex. `TypeX` nou) nu cere schimbare de schemă pe `TreeNode`.
- `Vote`/`Comment`/`Flag` au un singur punct de atașare (`CivicSubject`), fără ambiguitate „e pe nod sau pe conținut?".

### Negative / Trade-offs
- Un nivel suplimentar de indirecție față de „votez direct pe nod" — UI-ul trebuie să facă explicit pasul nod → listă de subiecte → subiect.

## Alternatives considered

- **`Issue` ca entitate de bază a arborelui** — respins. Ar obliga fiecare problemă să fie nod distinct, în loc să poată exista mai multe subiecte sub același nod.
- **Vot direct pe `TreeNode`** — respins. Amestecă structura cu conținutul; face imposibilă existența mai multor subiecte votabile independente sub același nod.

## Security / Transparency implications

N/A pentru acest ADR.

## References

- [[docs/domain.puml]] — `TreeNode`, `CivicSubject`
- [[docs/workflow.puml]]
- [[docs/design_considered_aspects.md]] §5
