# ADR-0012: Geografia administrativă ca dimensiune separată de arbore

**Status:** Accepted
**Date:** 2026-10-16

## Context

Conceptul inițial amesteca tematica și geografia în același arbore (de exemplu `Drumuri → Cluj → Calitate → DN1`), iar [sesizarea către autorități](../authority-notifications.md) descrie `TreeNode` ca „clasificarea tematică și geografică”.

Întrebarea: dacă județele și localitățile nu sunt noduri în arbore, pierdem filtrarea geografică și adresarea către instituții?

Le pierdem doar dacă geografia nu există nicăieri în model. Un arbore cu județe și localități are însă probleme proprii:

- **Duplicare:** fiecare temă se repetă sub fiecare localitate (42 de județe, aproximativ 3.200 de UAT-uri).
- **Subiecte care traversează mai multe unități:** DN1 trece prin mai multe județe, un râu poluat la fel, o lege e națională. Un singur nod-părinte geografic nu le descrie. Mai multe noduri ar însemna subiecte duplicate și voturi împărțite.
- **Încadrare neverificată:** utilizatorul alege „Cluj → Florești” pentru o groapă care e de fapt în Cluj-Napoca. Nimic nu verifică alegerea.

Între timp, [ADR-0011](0011-subject-media-links-and-location.md) a adus pinul de localizare. Pinul face parte din revizie și poate fi confirmat prin votul `LOCATION`. Unitatea administrativă a locului se poate deci *deriva* din pin, nu doar declara.

Tema, aria geografică și instituția competentă sunt trei dimensiuni distincte. Această decizie le separă. În plus, locul observației (pinul) și teritoriul vizat (aria) sunt și ele distincte.

## Decizie

### 1. Arborele rămâne tematic

`TreeNode` nu primește noduri de județ sau localitate ca schelă administrativă. Un nod local e permis doar când *tema însăși* e locală, de exemplu un proiect anume („Autostrada A3”, „Centura Dejului”).

### 2. `AdministrativeUnit`

- Populată din nomenclatorul SIRUTA (INS), pe niveluri: țară, județ, UAT. Bucureștiul are cele 6 sectoare ca unități proprii, pentru că adresarea se face pe sector. Satele și localitățile componente nu intră deocamdată.
- Ierarhia folosește aceeași abordare ca arborele ([ADR-0001](0001-hierarchy-adjacency-list-ltree.md): părinte + `ltree`).
- Codul SIRUTA e identificatorul stabil; denumirile și slug-urile pot fi corectate fără să rupă legăturile.
- Fiecare UAT are poligonul limitei administrative, dintr-o sursă publică cu cod SIRUTA. Sursa exactă și licența ei se fixează la implementare.
- **Setul de limite e versionat** (`BoundarySet`: sursă, dată, sumă de control). Un import nou creează o versiune nouă; versiunile vechi nu se șterg cât timp sunt referite.

### 3. PostGIS

Derivarea din poligoane face parte din arhitectură, deci se folosește PostGIS:

- imaginea PostgreSQL de producție și de dezvoltare devine varianta PostGIS a aceleiași versiuni majore, ca volumul de date existent să rămână compatibil;
- extensia `postgis` nu e „trusted” (verificat în imagine: `postgis.control` nu are `trusted`), deci o creează doar un superuser. **Corectură (16.10.2026):** extensia se creează administrativ — `initdb` pe volume noi, un pas administrativ din `bin/deploy-db.sh` înaintea migrărilor pe bazele existente —, iar migrarea doar verifică prezența ei și se oprește cu un mesaj clar dacă lipsește. Nici migratorul (`validez_owner`), nici `validez_app` nu primesc drepturi de superuser;
- se instalează doar `postgis`, nu și `postgis_topology` / `postgis_tiger_geocoder` din scriptul `initdb` al imaginii (scriptul imaginii e oricum ascuns de directorul `initdb` propriu montat peste `/docker-entrypoint-initdb.d`);
- `validez_app` are doar `SELECT` pe obiectele extensiei (identificate prin apartenența la extensie); tabelele aplicației, inclusiv cele cu geometrii, primesc DML ca orice tabelă operațională;
- poligoanele se stochează ca `geometry` (SRID 4326) cu index GiST; pinul rămâne latitudine/longitudine, iar punctul se construiește din ele la derivare.

### 4. Trei noțiuni separate: loc, arie declarată, arie efectivă

**Locul** este pinul (opțional, ADR-0011): unde a fost observată problema. Pinul are o **încadrare administrativă** (p.6): UAT-ul locului, starea încadrării și versiunea limitelor folosite.

**Aria declarată** este `scope` plus unitățile adăugate de autor sau de administrator (`SubjectAdministrativeUnit`). Tabelul conține doar unități declarate; UAT-ul pinului nu se scrie aici.

**Aria efectivă** este calculată din `scope`, nu stocată ca listă editabilă. Ea e folosită pentru filtrare (p.8), pagini de localitate și competență (p.10):

| `scope` | Aria efectivă |
|---|---|
| `LOCAL` | UAT-urile declarate ∪ UAT-ul locului (dacă încadrarea e determinată, p.6) |
| `COUNTY` | județele declarate |
| `NATIONAL` | țara |

La `COUNTY` și `NATIONAL`, UAT-ul pinului **nu** intră în aria efectivă: spune doar unde a fost observat ceva. O lege națională cu pin în Florești nu apare în filtrul Florești (decât prin comutatorul de arie mai largă, p.8, ca orice subiect național).

Reguli între pin și aria declarată:

- Pinul nu modifică aria declarată, iar aria declarată nu modifică încadrarea pinului.
- Un UAT al locului greșit se corectează mutând pinul, nu declarând alt UAT „în locul” lui.
- Punerea, mutarea sau scoaterea pinului schimbă doar încadrarea locului. Dacă după scoaterea pinului un subiect `LOCAL` nu mai are niciun UAT în aria efectivă, formularul cere completarea ariei declarate înainte de salvarea reviziei.

### 5. Contractul pentru `scope`

| `scope` | Unități declarate permise | Condiție de validitate |
|---|---|---|
| `LOCAL` | doar UAT-uri, din oricâte județe | aria efectivă conține cel puțin un UAT |
| `COUNTY` | doar județe, unul sau mai multe | cel puțin un județ declarat |
| `NATIONAL` | niciuna | — (aria e țara, implicit) |

- Pinul e permis la orice `scope`.
- Un subiect `LOCAL` care se întinde pe UAT-uri din județe diferite rămâne `LOCAL` (de exemplu un drum comunal între două comune vecine din județe diferite). `COUNTY` se alege când subiectul privește județul ca întreg.
- Un subiect `LOCAL` cu pin a cărui încadrare nu e determinată (p.6) și fără UAT declarat nu e încă valid: autorul rezolvă încadrarea sau declară UAT-ul.
- Un subiect **nou** nu poate rămâne fără arie: formularul cere `scope`, iar validarea aplică tabelul.
- Subiectele create înainte de această decizie au `scope = NULL` („arie nespecificată”). Nu apar în filtrele geografice până la completare și nu sunt blocate la vizualizare sau vot.
- **Pin adăugat ulterior** la un subiect cu arie declarată: aria declarată rămâne neschimbată. La `LOCAL`, UAT-ul locului se adaugă la aria efectivă; dacă era deja declarat, nu se numără de două ori.
- `scope` și unitățile declarate fac parte din revizie, ca pinul (ADR-0011, p.11; [ADR-0007](0007-votes-revisions-and-privacy.md), fără corecturi „minore”).

### 6. Încadrarea pinului poate fi verificată, nu e garantată corectă

Point-in-polygon nu garantează încadrarea corectă: pinul poate fi pus greșit, iar limitele pot avea goluri, suprapuneri sau puncte exact pe frontieră. Încadrarea pinului are o stare:

| Stare | Când | UAT-ul locului |
|---|---|---|
| `RESOLVED` | exact un UAT conține punctul | determinat automat |
| `AMBIGUOUS` | mai multe UAT-uri (frontieră, suprapunere) | nedeterminat; candidații se afișează autorului și moderării |
| `UNMATCHED` | niciun UAT, dar punctul e în interiorul României (gol în limite) | nedeterminat; cel mai apropiat UAT e propus drept candidat |
| `OUTSIDE` | punct în afara României | niciunul; semnalat la moderare |

**Rezolvarea manuală** a stărilor `AMBIGUOUS` și `UNMATCHED` aparține încadrării pinului, nu ariei declarate. Autorul sau un administrator alege unul dintre candidați. Se păstrează alegerea, cine a făcut-o, când, și candidații dintre care a ales. Starea devine `RESOLVED_MANUALLY`, iar UAT-ul locului e determinat. Rezolvarea funcționează la orice `scope`, pentru că nu creează o unitate declarată.

- Rezolvarea manuală se poate face doar între candidați. O încadrare `RESOLVED` nu se poate suprascrie manual.
- Mutarea pinului anulează rezolvarea manuală și refă derivarea.
- Confirmarea locului prin votul `LOCATION` (ADR-0011) confirmă pinul, nu și încadrarea administrativă. O stare diferită de `RESOLVED` se afișează lângă confirmare.

### 7. Instantaneul reviziei vs. clasificarea curentă

Sunt două lucruri separate:

**Instantaneul din `SubjectRevision`** este imuabil. La salvarea reviziei se fixează: pinul, `scope`, unitățile declarate, încadrarea pinului (stare, UAT, eventuala rezolvare manuală, candidații, versiunea `BoundarySet`) și aria efectivă rezultată. Votul se leagă de această revizie (ADR-0007), deci ce s-a votat nu se schimbă retroactiv.

**Clasificarea administrativă curentă** a subiectului este recalculabilă. Ea pornește din instantaneul reviziei curente și poate evolua fără revizie nouă doar prin:

- **un import nou de limite:** derivarea pinului se refă cu noul `BoundarySet`.
  - Dacă noul rezultat e `RESOLVED`, el înlocuiește și o eventuală rezolvare manuală (golul sau suprapunerea a fost corectată în date).
  - Dacă rezultatul rămâne nedeterminat, rezolvarea manuală existentă se păstrează, cât timp alegerea ei e încă printre candidați; altfel subiectul revine în coada de moderare.
- **rezolvarea manuală a unei încadrări nedeterminate** (p.6).

Aceste schimbări nu creează revizie (pinul și aria declarată nu s-au schimbat) și nu cer reconfirmarea voturilor. Când aria efectivă se schimbă, se notează în istoricul public al subiectului: ce s-a schimbat, de ce și față de ce versiune de limite.

Filtrarea, paginile de localitate și propunerea instituțiilor folosesc clasificarea curentă. Istoricul reviziilor afișează instantaneele.

**O schimbare a clasificării curente declanșează reevaluarea propunerilor de instituții** (regulile din p.10), la fel ca schimbarea categoriei sau a localizării din [authority-notifications.md](../authority-notifications.md):

- instituțiile propuse automat se recalculează: cele care nu mai rezultă din reguli se scot din propunere, cele noi se adaugă bifate doar dacă îndeplinesc condiția de bifare automată;
- selecțiile explicite ale utilizatorului (bife puse sau scoase manual, instituții adăugate din registru) se păstrează; dacă o instituție aleasă explicit nu mai rezultă din reguli, rămâne selectată și e semnalată pentru revizuire, fără să fie scoasă în tăcere;
- destinatarii depunerilor deja trimise (`AuthoritySubmission`, `AuthorityDelivery`) nu se modifică; o instituție nou propusă după trimitere poate primi doar o depunere nouă, explicită;
- reevaluarea se notează în istoricul sesizării, împreună cu motivul (import de limite sau rezolvarea încadrării).

O depunere către autorități (`AuthoritySubmission` din [authority-notifications.md](../authority-notifications.md)) fixează în pachet clasificarea curentă de la momentul trimiterii, cu versiunea limitelor. Recalcularea ulterioară nu rescrie documentele trimise.

La restaurarea bazei, instantaneele și clasificarea curentă vin din backup. Recalcularea după restaurare e o operație separată și explicită.

### 8. Semantica filtrării

Filtrul geografic se aplică după **aria efectivă** din clasificarea curentă (p.4, p.7), nu după pin și nu după toate legăturile.

- **Potrivire teritorială (implicit):** selectarea unității U întoarce subiectele a căror arie efectivă conține U sau o unitate descendentă a lui U.
  - Selectat Florești: apar subiectele `LOCAL` care conțin Florești.
  - Selectat județul Cluj: apar subiectele `COUNTY` care conțin județul și subiectele `LOCAL` cu cel puțin un UAT din județ.
  - Subiectele `NATIONAL` nu apar implicit sub nicio unitate sub nivelul de țară, oriunde ar fi pinul lor.
- **Arie mai largă (opțional, comutator):** „include și subiectele care privesc județul sau țara”. Adaugă subiectele a căror arie efectivă conține un *strămoș* al lui U (județul lui, țara). În listă apar într-o secțiune separată, ca să nu se amestece cu cele strict locale.
- **Contoare:** fiecare subiect se numără o singură dată per nod și per filtru, oricâte unități din aria lui ar cădea în selecție (numărare distinctă după subiect).
- Subiectele cu `scope = NULL` nu apar sub niciun filtru geografic.
- API: parametru de filtrare după cod SIRUTA, plus parametrul comutatorului.

### 9. Interfață și pagini

- **În TreePage, geografia e o fațetă, nu un al doilea arbore.** Un selector „Județ / Localitate” filtrează subiectele și contoarele din subarborele tematic, cu semantica din p.8. Nu se construiește o navigare geografică paralelă.
- **Pagini de localitate (pas ulterior, extinde [ADR-0008](0008-public-urls-slugs-and-indexing.md)):** `/localitate/{judet}/{uat}`, cu subiectele grupate pe teme, generate din date, nu din arbore. O pagină fără subiecte publicate primește `noindex` și nu intră în sitemap, ca să nu publicăm mii de pagini goale.

### 10. Competența instituțiilor ține de temă + arie + activ

Specificația din [authority-notifications.md](../authority-notifications.md) se amendează astfel:

- aria unei `CompetenceRule` referă `AdministrativeUnit` (cu descendenți), nu noduri geografice din arbore; regula se evaluează pe aria efectivă din clasificarea curentă;
- pentru unele teme competența ține de *activ*: la drumuri, clasa drumului (DN → CNAIR/DRDP, DJ → consiliul județean, stradă/DC → primăria). Clasa e un atribut al subiectului în categoria respectivă și se definește în Step 3.1;
- bifarea automată se face numai dacă regula este neambiguă și aria necesară evaluării ei este determinată: prin derivare `RESOLVED` (sau `RESOLVED_MANUALLY`), prin completare manuală validă sau implicit prin `scope = NATIONAL`. Simpla existență a unei unități declarate nu validează automat orice regulă: regula se aplică doar dacă aria ei acoperă aria efectivă la nivelul de care are nevoie. Altfel nu se bifează nimic, iar autorul alege din registrul de instituții filtrat după aria subiectului.

### 11. Clasamentele administrațiilor se amână

Statisticile de tip rezolvate / fără răspuns / timp mediu cer stări verificabile per instituție (`AuthorityDelivery`, `AuthorityResponse`), nu stadiul `CivicSubject.stage` setat în platformă.

## Rationale

- Locul confirmat e o sursă de localizare mai bună decât o alegere din arbore: **încadrarea poate fi verificată**, iar cazurile neclare sunt marcate explicit (p.6), nu ascunse.
- Separarea loc / arie declarată / arie efectivă descrie corect atât problemele punctuale, cât și pe cele care acoperă un teritoriu, fără subiecte duplicate și fără ca locul observației să schimbe teritoriul vizat.
- Separarea instantaneu / clasificare curentă păstrează sensul voturilor și permite totuși corectarea datelor de limite.
- Arborele tematic rămâne mic și navigabil. Geografia se poate extinde (alte țări, alte niveluri) fără să atingă temele.
- O adresare imperfectă nu e fatală. Potrivit [OG 27/2002](https://legislatie.just.ro/Public/DetaliiDocument/35034), conform citării furnizate de utilizator:
  - art. 6¹: petiția greșit adresată se redirecționează în 5 zile de la înregistrare, iar petiționarul e informat despre redirecționare;
  - art. 8: termenul de răspuns e de 30 de zile, calculat de la înregistrarea la instituția competentă;
  - art. 9: termenul poate fi prelungit în condițiile legii.

  Bifarea conservatoare de la p.10 e deci suficientă la început. Un motor complet de competențe nu e necesar din prima zi.

## Consecințe

- **Infrastructură:** schimbarea imaginii PostgreSQL în varianta PostGIS (dezvoltare și producție), după verificarea restaurării, a variantei Alpine, a arhitecturii și a extensiilor existente. Ordinea de deploy devine: extensii (administrativ) → migrare → configurare roluri → verificare ca runtime → publicare. Pașii exacți intră în `Deploy.md`.
- **Doctrine:** `schema_filter` exclude exact tabelele gestionate separat (obiectele PostGIS și tabela de poligoane); crearea și modificarea lor rămân în migrări explicite.
- **Import:** o comandă de import pentru SIRUTA + poligoane, rulabilă repetat (idempotentă după codul SIRUTA), care creează un `BoundarySet` nou și declanșează recalcularea clasificării curente din p.7.
- **Model:** `SubjectAdministrativeUnit` conține doar unități declarate; încadrarea pinului și clasificarea curentă se stochează separat; `SubjectRevision` primește instantaneul geografic.
- **Date existente:** nodurile geografice deja create în arbore, dacă există, rămân. Nu se migrează automat. Administratorii le pot converti manual în teme locale. Subiectele existente primesc `scope = NULL` (p.5).
- **Moderare:** coada de moderare primește subiectele cu încadrare `AMBIGUOUS`, `UNMATCHED` sau `OUTSIDE`, inclusiv pe cele readuse de un import nou de limite.
- **Documentație:** `authority-notifications.md` se actualizează cu amendamentele de la p.10, iar `implementation-plan.md` primește pașii nou definiți: PostGIS și import, încadrarea pinului și stările ei, aria în formular, clasificarea curentă, fațeta de filtrare, pagini de localitate.

## Alternative respinse

- **Județe și localități ca noduri în arbore:** duplicare de teme, subiecte multi-județ imposibil de încadrat, încadrare neverificată.
- **Doi arbori navigabili paraleli (tematic și administrativ):** două moduri de navigare de explicat în UI pentru aceleași rezultate; fațeta acoperă nevoia.
- **Unitate declarată care înlocuiește UAT-ul locului:** dublă sursă de adevăr pentru același loc. Locul se corectează mutând pinul; încadrările nedeterminate se rezolvă între candidați (p.6).
- **Aria egală mereu cu UAT-ul pinului:** nu descrie subiectele care acoperă un teritoriu (râuri, drumuri, legi).
- **Filtrare pe toate legăturile (declarate + UAT-ul pinului), indiferent de `scope`:** o lege națională cu pin într-o comună ar apărea implicit în acea comună.
- **Filtrul include implicit subiectele județene și naționale:** ar umple fiecare localitate cu aceleași subiecte largi; rămân disponibile prin comutator (p.8).
- **Recalcularea direct în revizia curentă:** ar schimba retroactiv versiunea pe care s-a votat.
- **Derivare în PHP pe poligoane simplificate:** precizie mai mică la frontiere și o implementare geometrică proprie de întreținut; PostGIS e soluția standard pentru PostgreSQL.
- **Unitate din EXIF-ul fotografiilor:** exclus deja de [ADR-0011](0011-subject-media-links-and-location.md) (p.6).
- **Motor complet de competențe încă de la început:** supraproiectare la volumul actual; redirecționarea legală acoperă erorile de adresare.

## Referințe

- [ADR-0001](0001-hierarchy-adjacency-list-ltree.md), [ADR-0007](0007-votes-revisions-and-privacy.md), [ADR-0008](0008-public-urls-slugs-and-indexing.md), [ADR-0011](0011-subject-media-links-and-location.md)
- [Sesizarea către autorități](../authority-notifications.md)
- Nomenclatorul SIRUTA (Institutul Național de Statistică)
- [OG 27/2002 privind reglementarea activității de soluționare a petițiilor](https://legislatie.just.ro/Public/DetaliiDocument/35034), art. 6¹, 8, 9
