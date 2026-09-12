# Decizii de arhitectură — considerații și alternative discutate

Acest document rezumă deciziile luate în faza de proiectare, alternativele respinse și motivul. Scopul e să nu redeschidem aceleași discuții mai târziu fără context.

## 1. Structura arborelui: `parent_id` + `ltree`, nu Nested Set

> Formalizat ca [ADR-0001](adr/0001-hierarchy-adjacency-list-ltree.md).

**Decizie:** `TreeNode` folosește `parent_id` ca relație fundamentală, plus coloana `path` de tip PostgreSQL `ltree` (indexată GiST) pentru interogări rapide de subarbore (`path <@ '...'`, `path @> '...'`).

**Respins:** Nested Set (`lft`/`rgt`) — mutarea unui subarbore necesită renumerotarea unui număr mare de rânduri, ceea ce e fragil sub concurență.

**Notă tehnică importantă:** adjacency list + path materializat NU face mutarea subarborelui O(1) — `parent_id` se schimbă O(1), dar `path`-ul tuturor descendenților tot trebuie actualizat. Diferența față de Nested Set e că actualizarea e simplă și atomică (o tranzacție: verificare că noul părinte nu e descendent → schimbare `parent_id` → recalculare `path` pe subarbore → commit/rollback), nu o renumerotare fragilă.

**Detaliu:** `id` este ULID/UUID, nu bazat pe nume — `path` folosește identificatori interni stabili, deci redenumirea unui nod (`Calitate` → `Starea drumurilor`) nu afectează `path`-ul.

## 2. Modelul de vot: multiplu per nod, configurabil, cu Prioritate calculată (nu votată)

> Formalizat ca [ADR-0003](adr/0003-voting-model.md).

**Decizie:**
- Un `CivicSubject` poate avea zero sau mai multe tipuri de vot active simultan (`SubjectVoteType`), nu un singur tip fix.
- `VoteType` e o entitate configurabilă (`VALIDITY`, `YES_NO`, `IMPORTANCE`...), nu hardcodată.
- Un user are maximum un vot activ per `(subject, vote_type)` — poate să-l schimbe, nu să-l dubleze (`UNIQUE(subject_id, user_id, vote_type_id)`).
- `VoteAggregate`/`VoteResult` sunt calculate per `(subject, vote_type)`, nu per subiect.

**Respins:** „Prioritate" ca tip de vot separat de „Importanță" — ar fi confuz pentru utilizator (i s-ar cere să voteze practic de două ori același lucru). În loc de asta, **Prioritatea e un scor calculat de sistem** din: importanță votată, număr validări, utilizatori afectați, timp de existență, severitate, autoritate competentă identificată etc.

**Extensie viitoare:** `VoteSession` pentru evaluări periodice (ex. evaluarea unui ales la 3/6/12/24 luni), ca voturile ulterioare să nu modifice retroactiv scorul unei perioade anterioare.

## 3 & 4. Integritate: semnături + hash chain din prima; Merkle Tree + witness servers în Faza 2

**Decizie MVP:**
- Semnătură pe device (public/private key) de la început — cost mic, previne alterarea silențioasă a unui vot de către server.
- `VoteEvent` append-only cu hash chain simplu (`previous_hash → hash`) în loc de Merkle Tree — suficient pentru a detecta o alterare, mult mai simplu de implementat.
- Event sourcing **doar** pentru domeniul unde integritatea contează (voturi, validări, evaluări electorale) — restul aplicației rămâne Symfony/Doctrine normal.

**Amânat pentru Faza 2** (după ce există trafic real care justifică costul): Merkle Tree, witness servers, timestamping public extern.

**Limită recunoscută explicit:** semnăturile criptografice dovedesc că un vot n-a fost alterat ulterior, dar **nu rezolvă Sybil attacks** (un atacator poate crea 50 de conturi Google valide și semna 50 de voturi valide). Integritatea datelor ≠ unicitatea persoanei.

**Decizie anti-Sybil (MVP):**
- Niveluri de încredere: email verificat → telefon verificat → trust score (vechime cont, istoric, sesizări de spam etc.).
- Trust score-ul **nu** transformă votul într-o putere de vot variabilă — se folosește doar pentru detectarea abuzului, filtrare spam, marcarea voturilor suspecte, limitarea acțiunilor.
- Transparență: se afișează separat `vote_count` (total) și `verified_vote_count` (de la conturi verificate), plus `suspected_abuse` — nu se ascunde activitatea suspectă.

## 5. `TreeNode` (structură) separat de `CivicSubject` (conținut votabil)

> Formalizat ca [ADR-0002](adr/0002-node-vs-civic-subject.md).

**Decizie:** `TreeNode` reprezintă exclusiv poziția în arbore (ex. `România > Cluj > Drumuri > Calitate`). Conținutul votabil/comentabil/moderabil e o entitate separată, `CivicSubject`, legată de un `TreeNode` prin `node_id`. Un nod poate avea simultan copii (alte noduri) și mai multe `CivicSubject` asociate.

**Respins:** o entitate `Issue` ca nod de bază al arborelui — ar fi obligat fiecare „problemă" să fie un nod distinct în ierarhie, în loc să poată exista mai multe subiecte sub același nod (ex. 1.000 de sesizări sub `Drumuri > Cluj > Calitate`).

**Respins:** numele `Issue` pentru entitatea de conținut — prea îngust. `CivicSubject.type` acoperă `ISSUE`, `PROPOSAL`, `PROJECT`, `PROMISE`, `ELECTORAL_EVALUATION`, `PETITION`, `TOPIC_EVALUATION`, extensibil fără schimbare de schemă.

**Adăugat în domain model:** `CivicSubject`, `Comment` (cu `parent_id`, adâncime limitată la 2–3 niveluri), `Flag`/`Report` (cu `target_type`/`target_id` generic — subiect, comentariu, media sau user — și coadă de moderare separată).

**Beneficiu:** evaluarea aleșilor, promisiunile electorale, propunerile și sesizările folosesc toate același mecanism (`Media`, `Vote`, `Comment`, `Flag`, `Authority`, workflow), fără entități paralele.

## Reguli arhitecturale explicite (de reținut peste tot în cod și diagrame)

- **`TreeNode` organizează informația. `CivicSubject` reprezintă conținutul civic.** `Vote`, `Comment` și `Flag` aparțin întotdeauna de `CivicSubject`, niciodată direct de `TreeNode`.
- **`Vote` = starea curentă. `VoteEvent` = istoricul imuabil al modificărilor.** La schimbarea unui vot nu se face `UPDATE` direct pe `Vote` — se creează întâi un `VoteEvent` nou (`previous_hash -> hash`), apoi `Vote` e actualizat să reflecte starea curentă. Așa rămâne un istoric verificabil: `VoteEvent #1 VALID -> VoteEvent #2 INVALID -> VoteEvent #3 VALID`.

## 6. Configurarea trust score-ului: versionată în repo, nu în DB editabilă silențios

Decizie ridicată la rang de **ADR** (decizie arhitecturală de durată, cu probabilitate mare să fie re-întrebată peste timp — „de ce nu punem pragurile astea în admin panel?"): vezi **`docs/adr/0004-trust-score-configuration.md`** pentru contextul complet, alternativele respinse și raționamentul.

Pe scurt:

- Formula, ponderile și pragurile generale sunt publice, versionate în `backend/config/trust_score.yaml`, și intră în producție exclusiv prin deploy.
- **Separare explicită**: politica anti-Sybil e publică; semnalele operaționale sensibile ale apărării curente (IP-uri suspecte temporare, throttling activ sub atac, indicatori de compromitere) nu sunt.
- `TrustScore` e o **valoare calculată**, legată de `trust_score_version` + `config_commit`, nu un număr static mutabil în DB — vezi `TrustConfigSnapshot` și `SecurityOverride` în `domain.puml`.
- Orice override runtime e explicit, temporar, auditat (`SecurityOverride`), niciodată tăcut.
- Semnalele de device/network au greutate deliberat redusă (privacy, false positive-uri pentru familii/birouri/școli/VPN) — nu sunt pilonul central al apărării.
- Rezistența reală la Sybil vine din costul de a simula coerent un utilizator legitim pe termen lung, nu din secretul unui prag.
