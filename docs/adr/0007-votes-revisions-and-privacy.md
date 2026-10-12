# ADR-0007 — Voturi: revizii, înghețare, date private și integritate

**Data:** 12.10.2026  
**Stare:** acceptat; implementare în Step 4. Completează ADR-0003 (modelul de vot) și interacționează cu ADR-0006 (moderare). Votarea nu se activează pe producție până la rezolvarea punctelor deschise privind datele reziduale, backupurile și informarea.

## Context

ADR-0003 stabilește tipurile de vot configurabile, un vot activ per `(subiect, utilizator, tip)` și istoricul prin `VoteEvent` cu lanț de hash. Rămăseseră nedecise: ce se întâmplă cu un vot când subiectul se modifică după vot, ce se întâmplă pe subiectele închise și cum se protejează datele de vot, care pot dezvălui opinii politice indiferent de tipul subiectului.

## Decizie

### 1. Tipuri și valori

- Tipuri implicite la crearea subiectului: `ISSUE` → `VALIDITY` + `IMPORTANCE`; `PROPOSAL` → `YES_NO` + `IMPORTANCE`; `PETITION` → `YES_NO`. Tipurile rezervate administratorilor nu primesc tipuri implicite; administratorul le configurează prin `SubjectVoteType`.
- Valori: `VALIDITY` = valid / invalid; `YES_NO` = da / nu; `IMPORTANCE` = 1–5.
- Un tip care are deja voturi nu se șterge de pe subiect; se dezactivează. Rezultatele lui rămân vizibile, iar votarea pe el se comportă ca la un subiect înghețat (doar retragere).
- Autorul își poate vota propriul subiect.

### 2. Cine votează

- Doar utilizatori autentificați, cu emailul confirmat și cu consimțământul pentru voturi dat (secțiunea 6).
- Doar pe subiecte `PUBLISHED`. Pe `PENDING` și `HIDDEN` nu se votează. Dacă un subiect publicat revine în `PENDING` (ADR-0006: editare de către un autor fără drept de publicare directă), votarea se suspendă până la reaprobare.

### 3. Votul este legat de revizie

- Fiecare schimbare efectivă a titlului, descrierii sau estimării (sumă, monedă, explicație) creează o `SubjectRevision` nouă. O salvare fără schimbări nu creează revizie. Nu există corecturi „minore” care să ocolească revizia.
- Tipul face parte din instantaneu, deci schimbarea lui creează și ea revizie, pentru ca instantaneul curent să corespundă subiectului. Se poate întâmpla doar înaintea primului vot, deci nu afectează niciun votant. Comparația se face pe valori (după normalizarea estimării), nu pe câmpurile prezente în cerere.
- Revizia păstrează instantaneul complet al câmpurilor de sens, nu doar diferența. Notele personale (`SubjectPrivateNote`), stadiul și vizibilitatea nu fac parte din revizie.
- Tipul subiectului se blochează după primul vot înregistrat vreodată pe subiect, chiar dacă votul a fost retras ulterior.
- `Vote` reține revizia pe care s-a votat. După o revizie nouă, votul rămâne „nereconfirmat”: utilizatorul vede „Subiectul s-a modificat de la votul tău”, diferența dintre revizia votată și cea curentă, și poate reconfirma (aceeași valoare sau alta) ori retrage.
- Reconfirmarea creează un eveniment privat nou în `UserVoteHistory` și un eveniment în `IntegrityEventLog`, pentru revizia curentă.
- Rezultatele per `(subiect, tip)` au două părți: rezultatul principal, numai din voturile pe revizia curentă, și numărul voturilor date pe versiuni anterioare, nereconfirmate. Agregatul se recalculează din `Vote` în aceeași tranzacție cu evenimentul.

### 4. Istoricul reviziilor și diferența

- Istoricul reviziilor și diferența între două revizii sunt vizibile oricui vede subiectul, după aceleași reguli de vizibilitate (ADR-0002, ADR-0006).
- Diferența se calculează din instantanee: pe câmp pentru estimare, la nivel de cuvânt pentru titlu și descriere.
- Votantul vede direct diferența dintre revizia votată de el și revizia curentă.

### 5. Înghețarea

- Pe `RESOLVED` și `CLOSED` se blochează voturile noi, schimbarea opțiunii și reconfirmarea. Retragerea rămâne permisă. Rezultatele rămân vizibile.
- Retragerea produce un eveniment privat cu starea rezultată nulă și un eveniment de integritate fără valoare în clar; actualizează agregatul, inclusiv numărul voturilor nereconfirmate.
- Aceeași excepție de retragere se aplică retragerii consimțământului și ștergerii contului, inclusiv pe subiecte înghețate, `PENDING` sau `HIDDEN`.
- La redeschiderea subiectului, votarea se reia pe revizia curentă. Voturile retrase nu se reactivează automat.

### 6. Confidențialitate și consimțământ

- Toate voturile se tratează ca date care pot dezvălui opinii politice, indiferent de tipul subiectului.
- Temeiul vizat este consimțământul explicit: bifă separată, nebifată inițial, la primul vot, cu text scurt și legătură la `#/privacy`. Se înregistrează versiunea informării și momentul consimțământului. Retragerea este disponibilă fără a șterge contul.
- Public sunt doar agregatele. Utilizatorul își vede propriile voturi; nimeni nu vede voturile altor persoane prin interfață sau API, nici administratorii. Accesul tehnic operațional se limitează la personalul autorizat și se auditează separat, fără copierea opțiunilor în logurile de acces.
- Retragerea unui vot elimină contribuția sa din rezultate, dar nu retrage consimțământul și nu șterge automat întregul istoric privat. Retragerea consimțământului elimină toate voturile active și întregul istoric privat de vot al persoanei, inclusiv datele de reconfirmare și elementele private ale amprentelor criptografice.
- Nu folosim excepția pentru date făcute publice pentru voturi private și nu presupunem că interesul legitim justifică singur păstrarea opțiunilor sensibile după retragere. Eventualele obligații de păstrare pentru o situație juridică concretă se evaluează separat.
- Pagina privacy explică scopul, datele, accesul, durata păstrării, diferența dintre retragerea votului și a consimțământului, ștergerea, backupurile și limitele auditului. Nu numește dovezile reziduale „anonime” fără evaluare.

### 7. Separarea datelor private de jurnalul de integritate

Rolul evenimentului `VoteEvent` din ADR-0003 este separat aici între istoricul privat `UserVoteHistory` și dovada `IntegrityEventLog`; această secțiune înlocuiește interpretarea unui eveniment permanent care conține opțiuni atribuibile.

Cele două zone sunt separate logic, prin tabele și permisiuni în aceeași bază tranzacțională PostgreSQL. Nu introducem două baze independente care ar necesita un protocol suplimentar pentru atomicitate.

**Date private, ștergibile:**

- `Vote`: `user_id`, `subject_id`, `vote_type_id`, `subject_revision_id`, valoarea și datele de creare/modificare; unicitate pe `(subject_id, user_id, vote_type_id)`. Valoarea este validată după configurația tipului, inclusiv scala 1–5 pentru importanță.
- `UserVoteHistory`: utilizator, subiect, tip, acțiune, valorile și reviziile înainte/după, moment, legătura la eveniment și valoarea aleatorie privată necesară verificării commitmentului. Istoricul atribuit unei persoane se poate șterge complet.
- Consimțământul și datele necesare gestionării ștergerii sunt stocate separat, cu scop și retenție proprii. Nu sunt adăugate în jurnalul permanent ca identificatori.
- Modelul anterior `HMAC(secret, subject_id ‖ user_id)` în evenimentele permanente este înlocuit: nu păstrăm acolo un pseudonim determinist al persoanei.

**`IntegrityEventLog`, append-only:**

- Subiect, număr de secvență per subiect, versiunea formatului, tip de eveniment, commitment, `previous_event_hash`, `event_hash` și momentul operațiunii. Nu păstrează `user_id`, `vote_id`, pseudonim determinist, IP, user-agent, ID de sesiune sau opțiune în clar.
- Secvența este alocată sub lock per subiect, cu unicitate `(subject_id, sequence_number)`. Un ID global poate identifica tehnic rândul, dar nu determină ordinea lanțului sau a commiturilor.
- Commitmentul folosește SHA-256 peste o serializare canonică, versionată și cu separator de domeniu, a conținutului privat al operațiunii și a unei valori aleatorii independente de 32 octeți, generată criptografic pentru fiecare eveniment. Conținutul privat include tipul votului și reviziile, nu doar subiectul și opțiunea; retragerea are starea rezultată nulă.
- Valoarea aleatorie rămâne exclusiv în zona privată și nu se publică împreună cu commitmentul. Un salt public împreună cu un spațiu mic de opțiuni nu împiedică ghicirea valorii. După ștergere, rezistența la ghicire depinde și de eliminarea copiilor acestui secret.
- `event_hash` acoperă toate câmpurile jurnalului care trebuie protejate, inclusiv commitmentul, secvența, momentul și hashul anterior, prin serializare canonică. Hashul propriu este exclus din intrarea de calcul.
- Evenimentele existente nu se modifică, nu se înlocuiesc cu tombstone și nu se recalculează pentru a ascunde o ștergere. Schimbarea stării se înregistrează prin eveniment nou. Se șterg datele private asociate.
- Subiectul, momentul și ordinea pot permite corelări cu alte date. Lipsa identificatorilor direcți nu dovedește anonimizarea. Retenția jurnalului rezidual este condiționată de evaluarea acestui risc; caracterul append-only nu justifică păstrarea nelimitată.

### 8. Tranzacții, ștergere și backupuri

- Exprimarea, schimbarea, reconfirmarea și retragerea actualizează datele private, jurnalul și agregatele într-o singură tranzacție. Verificarea consimțământului, vizibilității, stadiului și reviziei are loc sub blocările relevante; o revizie nouă nu poate interveni neobservată între verificare și vot.
- Lockurile sunt cele de rând deja folosite la editarea subiectului: întâi `app_user` al votantului, apoi `civic_subject` (`PESSIMISTIC_WRITE`). Editarea blochează întâi autorul prin `PublishingPolicy::lock`, apoi același rând al subiectului. Editarea care creează revizia, schimbarea stadiului și votul se exclud astfel reciproc, cu aceeași ordine utilizator → subiect. Nu folosim advisory lock separat per subiect pentru vot.
- Retragerea consimțământului obține același lock de rând pe utilizator ca votarea, apoi lockurile de rând ale subiectelor afectate în ordine deterministă. Nu există un lock global pentru toate voturile.
- La retragere, se actualizează partea corespunzătoare a rezultatului: revizia curentă sau contorul voturilor nereconfirmate. La revizie nouă și reconfirmare, aceeași tranzacție menține separarea rezultatelor. Agregatele pot fi verificate/recalculate din voturile curente.
- Retragerea consimțământului și eliminarea datelor de vot la ștergerea contului actualizează agregatele, adaugă evenimentele necesare per subiect fără referință la persoană și șterg `Vote` și `UserVoteHistory`, inclusiv valorile aleatorii private. Funcționează și pe subiecte înghețate, ascunse sau în așteptare.
- Ștergerea contului din restul aplicației necesită reguli distincte pentru contribuții, revizii și relațiile existente; acest ADR definește eliminarea datelor de vot, nu autorizează implicit ștergerea tuturor contribuțiilor.
- Ștergerea operațională nu elimină singură copiile din backupuri. Se definește retenția backupurilor și un registru protejat al cererilor de ștergere, cu date minime și retenție proprie, în afara setului restaurat. Orice restaurare reaplică ștergerile înainte de redeschiderea traficului; o copie restaurată nu reactivează consimțământul retras.
- Crypto-shredding este o opțiune de implementare numai dacă cheile sunt separate și nu pot fi recuperate din backupuri, versiuni ale key store-ului, replici sau cache. Ștergerea salturilor fără criptarea datelor nu este crypto-shredding și nu elimină opțiunile în clar din backupuri. Rotația unei chei nu garantează distrugerea copiilor vechi.
- Lanțul per subiect servește integrității interne. După eliminarea conținutului privat putem verifica structura păstrată, dar nu reconstrui complet opțiunile istorice sau demonstra numai din hashuri corectitudinea numărării. Operatorul care controlează baza poate rescrie un lanț neancorat extern.
- Verificabilitatea publică prin Merkle, ancorare externă sau ZKP se proiectează într-un ADR separat. Compatibilitatea de format nu constituie o garanție de verificabilitate publică.

## Puncte deschise

1. **Evaluarea și retenția dovezilor reziduale:** riscurile de corelare ale subiectului, secvențelor, momentelor, commitmenturilor și copiilor disponibile; termenul aplicabil și procedura de eliminare a jurnalelor dacă păstrarea nu este justificată. Nu presupunem că toate datele reziduale sunt anonime. Include logurile de acces și de aplicație: IP-ul, momentul și ruta de vot permit corelarea cu jurnalul. Se stabilesc retenție scurtă, excluderea rutelor de vot din log sau logarea lor fără IP; inclusiv logurile proxy-urilor și copiile lor intră în evaluare.
2. **Backupuri și chei:** durata exactă de păstrare, registrul de ștergeri, verificarea restaurării și alegerea între retenție limitată și criptare cu chei ștergibile. Ștergerea se testează inclusiv după restaurare.
3. Mascarea conținutului unei revizii (conținut ilegal sau abuziv) — Step 5.
4. Formularea finală a consimțământului și a secțiunii privacy, inclusiv acces, termene și limitele istoricului; verificare de specialitate înainte de activarea pe producție.

## Alternative respinse

- **Marcajul „modificat după N voturi” fără legarea votului de revizie** — nu arată pe ce versiune s-a votat și amestecă în rezultat voturi date pe sensuri diferite.
- **Resetarea voturilor la editare** — pierde opinia utilizatorilor pentru o modificare care poate fi neînsemnată și permite autorului să „șteargă” un rezultat nefavorabil.
- **Blocarea totală a editării după primul vot** — împiedică îndreptarea greșelilor reale.
- **Corecturi „minore” fără revizie** — loc de abuz; amânat fără termen.
- **Un singur lanț global** — serializează toate voturile din sistem sub un singur lock.
- **Identificatori sau pseudonime deterministe în jurnalul permanent** — mențin legături cu persoana și complică ștergerea.
- **Export public al evenimentelor sub pseudonim** — dezvăluie valori individuale; pseudonimul nu face datele anonime.
- **Criteriu de sensibilitate după tipul subiectului** — nesigur; orice subiect poate dezvălui opinii politice.

## Referințe

- ADR-0002, ADR-0003, ADR-0004, ADR-0006
- [EDPB — anonimizare și pseudonimizare](https://www.edpb.europa.eu/topics/ai-and-technology/anonymisation-pseudonymisation_en)
- [Comisia Europeană — temeiuri legale pentru prelucrarea datelor](https://commission.europa.eu/law/law-topic/data-protection/information-business-and-organisations/legal-grounds-processing-data_en)
- [RFC 9162 — Merkle trees și dovezi structurale](https://www.rfc-editor.org/rfc/rfc9162.html)
- Regulamentul (UE) 2016/679, art. 4 pct. 5 (pseudonimizare), art. 7 (consimțământ), art. 9 (categorii speciale)
