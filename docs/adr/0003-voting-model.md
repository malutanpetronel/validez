# ADR-0003: Voting model

**Status:** Accepted
**Date:** 2026-09-12

## Context

Trebuie decis dacă un `CivicSubject` are un singur tip de vot fix, sau mai multe tipuri configurabile simultan, și cum se relaționează „Importanța" votată cu „Prioritatea" afișată.

## Decision

- Un `CivicSubject` poate avea zero sau mai multe tipuri de vot active simultan, prin `SubjectVoteType`.
- `VoteType` e o entitate configurabilă (`VALIDITY`, `YES_NO`, `IMPORTANCE`...), nu hardcodată în cod.
- Un user are maximum un vot activ per `(subject, vote_type)` — poate să-l schimbe, nu să-l dubleze: `UNIQUE(subject_id, user_id, vote_type_id)`.
- `VoteAggregate`/`VoteResult` sunt calculate per `(subject, vote_type)`, nu per subiect.
- **„Prioritate" nu e un tip de vot** — e un scor calculat de sistem din: importanță votată, număr validări, utilizatori afectați, timp de existență, severitate, autoritate competentă identificată.
- La schimbarea unui vot: `Vote` reprezintă starea curentă; orice schimbare creează întâi un `VoteEvent` nou (`previous_hash -> hash`), apoi `Vote` e actualizat — niciodată `UPDATE` direct fără eveniment.

## Rationale

- Configurabilitatea per subiect permite combinații reale: o sesizare de drum poate avea `VALIDITY` + `IMPORTANCE`; o propunere poate avea doar `YES_NO`; o evaluare de mandat poate combina toate trei.
- A cere userului să voteze separat „Importanță" și „Prioritate" ar fi confuz — practic același gest repetat. Separarea votat/calculat clarifică rolul fiecăruia.
- Constraint-ul unique per `(subject, user, vote_type)` previne dublarea votului fără să blocheze schimbarea lui — coerent cu ideea de opinie care poate evolua.
- `VoteEvent` cu hash chain (vezi și ADR-0004 pentru integritate) transformă schimbarea de vot într-un istoric verificabil (`VALID → INVALID → VALID`), nu într-o simplă suprascriere.

## Consequences

### Positive
- Model flexibil, extensibil la tipuri noi de vot fără schimbare de schemă (doar rând nou în `VoteType`).
- Prioritatea rămâne un semnal de sistem, ajustabil independent de comportamentul de vot al userilor (poate integra și alte semnale ulterior, fără să ceară un nou tip de vot).
- Istoric complet și verificabil al schimbărilor de vot.

### Negative / Trade-offs
- Schemă mai complexă decât „un nod = un vot" (tabele suplimentare: `VoteType`, `SubjectVoteType`, `VoteAggregate`/`VoteResult` per tip).
- UI-ul trebuie să gestioneze explicit „ce tipuri de vot sunt active pentru acest subiect", nu un singur widget fix.

## Alternatives considered

- **Un singur tip de vot fix per nod** — respins. Prea rigid pentru varietatea de conținut civic (sesizare vs. propunere vs. evaluare de mandat).
- **„Prioritate" ca tip de vot separat** — respins. Ar cere userului să voteze practic același lucru de două ori; nu separă clar „ce cred oamenii" de „ce calculează sistemul".
- **`UPDATE` direct pe `Vote` la schimbare, fără `VoteEvent`** — respins. Pierde istoricul verificabil, contrazice premisa de integritate a proiectului.

## Security / Transparency implications

Istoricul de `VoteEvent` (hash chain) face schimbările de vot auditabile — relevant pentru încrederea publică în rezultate. Vezi și ADR-0004 pentru cum se leagă de configurarea anti-Sybil.

## References

- [[docs/domain.puml]] — `VoteType`, `SubjectVoteType`, `Vote`, `VoteEvent`, `VoteAggregate`, `VoteResult`
- [[docs/workflow.puml]]
- [[docs/security.puml]]
- [[docs/design_considered_aspects.md]] §2
