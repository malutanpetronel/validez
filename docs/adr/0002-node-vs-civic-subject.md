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
- UI-ul distinge categoriile (`TreeNode`) de frunzele de conținut (`CivicSubject`), fără să transforme subiectele în noduri structurale.

## Alternatives considered

- **`Issue` ca entitate de bază a arborelui** — respins. Ar obliga fiecare problemă să fie nod distinct, în loc să poată exista mai multe subiecte sub același nod.
- **Vot direct pe `TreeNode`** — respins. Amestecă structura cu conținutul; face imposibilă existența mai multor subiecte votabile independente sub același nod.

## Security / Transparency implications

N/A pentru acest ADR.

## References

- [[docs/domain.puml]] — `TreeNode`, `CivicSubject`
- [[docs/workflow.puml]]
- [[docs/design_considered_aspects.md]] §5

## Prezentare în interfață (04.10.2026)

Subiectele sunt afișate ca frunze sub categoria de care aparțin, cu pictogramă distinctă. O ramură încarcă la cerere subcategoriile și subiectele directe (`GET /api/civic_subjects?node={ulid}&scope=direct`), apoi afișează maximum 5 subiecte, cele mai noi primele. Selecția frunzei deschide detaliile lângă arbore (sub el pe ecrane mici). Frunzele nu pot fi mutate prin drag and drop ca noduri structurale.

Peste 5 subiecte, „Vezi tot (N subiecte)” deschide `#/arbore/{nodeId}/subiecte`, cu filtre tip/stadiu și 20 de subiecte pe pagină. Această listă conține doar subiectele categoriei, ca frunzele; filtrarea API fără `scope=direct` include în continuare întregul subarbore. `#/subiecte` afișează separat subiectele recente. Utilizatorii autentificați pot folosi „Adaugă un subiect aici”, cu categoria preselectată.

„Înapoi la arbore”, din listă sau din detaliul deschis prin listă, reface selecția categoriei și ramurile deschise. Contextul de navigare este transmis prin starea routerului; datele categoriilor și subiectelor sunt recitite din API la revenire. Un acces direct, fără context anterior, deschide arborele inițial.

## Definiții tipuri de subiect (07.10.2026)

Fiecare `CivicSubject.type` are un sens și limite clare, ca votul (Step 3), stadiul și regulile de acces să însemne același lucru pentru toți.

| Cod | Ce este | Ce nu este | Cine creează | Exemplu |
|---|---|---|---|---|
| `ISSUE` — Problemă / sesizare | O situație existentă, constatabilă, care trebuie remediată. Ceilalți confirmă dacă e reală. | Nu propune o soluție și nu cere ceva formal unei autorități. | utilizatori | „Gropi pe DN1 la km 23” |
| `PROPOSAL` — Propunere | O idee de schimbare, încă nedecisă și nefinanțată. Se votează dacă ar trebui făcută. | Nu are încă un responsabil, un buget sau un termen. | utilizatori | „Pistă de biciclete pe Calea Dorobanților” |
| `PROJECT` — Proiect | O inițiativă decisă sau în derulare, cu un responsabil (autoritate), buget și termen. Se urmărește execuția. | Nu mai e în discuție dacă se face, ci cum se face și dacă se face bine. | admini | „Reabilitare DN1 Cluj–Dej, 2026–2027” |
| `PETITION` — Petiție | O cerere formală, adresată unei autorități anume, care strânge susținători. | Nu e simpla constatare a unei probleme: cere explicit ceva cuiva. | utilizatori | „Cerem Primăriei Dej iluminat pe strada X” |
| `PROMISE` — Promisiune | Un angajament public al unui ales sau candidat, urmărit în timp până e respectat sau încălcat. | Nu e o idee a cetățenilor: autorul angajamentului e alesul. | admini | „Primarul X: parc nou până în 2027” |
| `ELECTORAL_EVALUATION` — Evaluare electorală | Evaluarea activității unui ales pe un mandat sau pe o perioadă. | Nu evaluează o temă sau o problemă punctuală, ci o persoană într-o funcție. | admini | „Activitatea primarului X — primele 12 luni” |
| `TOPIC_EVALUATION` — Evaluare tematică | Evaluarea stării unui domeniu într-un teritoriu, ca un barometru public. | Nu e o problemă concretă și nu privește o persoană. | admini | „Calitatea drumurilor în județul Cluj” |

Coloana „Cine creează” reflectă decizia din Step 2 (`SubjectType::isAdminOnly()`).

### Granița Propunere ↔ Proiect

Criteriul decisiv: **există deja o decizie și un responsabil?** Dacă nu — `PROPOSAL`; dacă da — `PROJECT`.

O propunere votată și adoptată de o autoritate **nu se transformă** în proiect: adminul creează un `PROJECT` nou, legat de propunerea de origine. Astfel, voturile date pe propunere („ar trebui făcut?”) rămân separate de evaluarea proiectului („se face bine?”) — un vot nu își schimbă sensul după o schimbare de tip. Același principiu se aplică unei sesizări care devine petiție.

### Decizii (07.10.2026)

#### 1. `stage`: sens comun, etichete pe tip

Modelul păstrează o singură mașină de stări pentru toate tipurile, cu sens comun — filtrele, statisticile și regulile de acces lucrează cu aceleași valori:

| `stage` | Sens comun |
|---|---|
| `OPEN` | deschis, nimic început încă |
| `IN_PROGRESS` | se lucrează / e în desfășurare |
| `RESOLVED` | scopul subiectului a fost atins |
| `CLOSED` | încheiat fără ca scopul să fie atins (respins, abandonat, încălcat) |

În interfață, fiecare tip își afișează propriile cuvinte pentru aceleași stări:

| Tip | `OPEN` | `IN_PROGRESS` | `RESOLVED` | `CLOSED` |
|---|---|---|---|---|
| `ISSUE` — Problemă | Deschisă | În lucru | Rezolvată | Închisă nerezolvată |
| `PROPOSAL` — Propunere | Deschisă | În analiză | Adoptată | Respinsă |
| `PROJECT` — Proiect | Planificat | În execuție | Finalizat | Anulat |
| `PETITION` — Petiție | Deschisă | În analiză | Admisă | Respinsă |
| `PROMISE` — Promisiune | Neîndeplinită încă | În curs | Respectată | Încălcată |
| `ELECTORAL_EVALUATION` / `TOPIC_EVALUATION` — Evaluări | Deschisă | — | — | Încheiată |

Evaluările folosesc doar `OPEN` (evaluare deschisă) și `CLOSED` (evaluare încheiată): „rezolvat” nu are sens pentru ele. La Step 3 se pot închide automat odată cu perioada de vot.

#### 2. Legătura între subiecte

Legătura „provine din” (ex. `PROJECT` ← `PROPOSAL`, `PETITION` ← `ISSUE`) se adaugă la Step 3.

#### 3. Schimbarea tipului după primul vot

Se tratează la Step 4, împreună cu nota din `implementation-plan.md` despre editarea după primele voturi: schimbarea tipului se blochează după primul vot și se înlocuiește cu un subiect nou, legat de cel vechi.
