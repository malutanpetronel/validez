# Sesizare automată către autorități competente

**Stare:** specificație de implementat, documentată la 12.10.2026 pe baza discuției furnizate de utilizator. Asocierea autorităților, selecția cu bife, trimiterea și urmărirea nu sunt încă implementate.

## Clasificare, instituții și competențe

Arborele `TreeNode` este clasificarea tematică; geografia e o dimensiune separată (`AdministrativeUnit`, aria efectivă a subiectului — [ADR-0012](adr/0012-administrative-geography-as-separate-dimension.md)). Instituțiile sunt într-un registru unic `Authority`, separat de arbore. `CompetenceRule` leagă un nod tematic și o arie (`AdministrativeUnit`, cu descendenți) de o instituție responsabilă; fiecărui nod îi pot fi asociate una sau mai multe instituții, iar aceeași instituție poate deservi sute de noduri.

Exemple de asocieri (instituțiile enumerate nu sunt noduri copil):

| Categorie | Instituții asociate |
|---|---|
| Drumuri → Calitate, arie: județul Cluj | CNAIR (DN), Consiliul Județean Cluj (DJ), Primăria Cluj-Napoca (străzi), Poliția Rutieră |
| Mediu → Poluare | Garda de Mediu, APM, Primărie |

Exemplele ilustrează configurarea; competența concretă se stabilește prin reguli, inclusiv aria geografică și responsabilitatea pentru situația raportată. Administratorii gestionează registrul și regulile. O regulă poate fi aplicabilă doar nodului sau întregului subarbore, explicit. Instituțiile rezultate din mai multe reguli sunt deduplicate.

Datele de contact ale unei instituții se actualizează o singură dată în registru. Schimbarea responsabilității se face în regula comună aplicabilă nodurilor/subarborelui vizat și se propagă la selecțiile automate viitoare. Dacă există reguli independente, acestea trebuie actualizate explicit. Destinatarii aleși de utilizator și istoricul sesizărilor deja trimise nu se rescriu retroactiv.

## Asociere automată și selecție cu bife

La crearea unei sesizări, aplicația identifică regulile aplicabile categoriei și localizării și bifează automat instituțiile competente. Exemplu:

```text
☑ Primăria Cluj-Napoca
☑ Consiliul Județean Cluj
☑ CNAIR
☐ Garda de Mediu
```

Bifarea automată se face numai dacă regula este neambiguă și aria necesară evaluării ei este determinată: prin derivare `RESOLVED`, prin completare manuală validă sau implicit prin `scope = NATIONAL` ([ADR-0012](adr/0012-administrative-geography-as-separate-dimension.md), p.10).

Utilizatorul poate debifa instituțiile propuse sau adăuga alte instituții din registru. Se păstrează selecția finală și proveniența fiecărei asocieri (regulă automată sau adăugare manuală). Schimbarea categoriei/localizării recalculează propunerile și permite revizuirea selecției, fără să anuleze în tăcere alegerile utilizatorului.

Crearea subiectului nu trimite implicit mesaje externe. Trimiterea pornește prin butonul „Trimite sesizarea” sau prin modul automat activat explicit pentru sesizare. Contribuțiile `PENDING`/`HIDDEN` nu declanșează trimiterea automată; se respectă moderarea din ADR-0006.

## Document și canale de trimitere

După apăsarea „Trimite sesizarea”, aplicația pregătește un document formal adresat instituțiilor selectate, pe baza unui șablon, și un pachet cu:

- descrierea sesizării și datele necesare ale expeditorului;
- fotografii și videoclipuri, ca atașamente sau linkuri când canalul are limite;
- localizarea GPS și starea confirmării ei;
- istoricul voturilor și validărilor disponibil la momentul trimiterii, cu rezultate agregate și referințe la evenimentele relevante.

Pachetul nu include note private sau date personale ale votanților. Documentul generat nu primește automat număr de înregistrare de la instituție; acesta se adaugă la confirmarea instituției.

Canalele prevăzute sunt emailul, API-urile oficiale unde există și integrările cu ghișee/portaluri de petiții. Fiecare canal necesită un conector propriu și verificarea cerințelor sale. Un canal fără integrare disponibilă oferă documentul pentru depunere manuală și nu este prezentat ca trimitere automată funcțională.

Se păstrează versiunea documentului, destinatarii și datele de contact folosite la trimitere, dovezile transmiterii și erorile. Reluările după eșec trebuie să evite depunerile duplicate; un rezultat incert se verifică înainte de o nouă depunere.

## Confirmare și urmărire

Fiecare sesizare transmisă primește un număr unic intern, data și ora, lista instituțiilor notificate și istoric de stare. Numărul intern este separat de numărul de înregistrare atribuit de fiecare instituție.

Stările urmărite sunt:

| Stare | Semnificație |
|---|---|
| Trimisă | Există dovadă a transmiterii prin canalul ales. |
| Înregistrată | Instituția a confirmat înregistrarea, cu numărul și data proprii. |
| În analiză | Instituția a comunicat că analizează sesizarea. |
| Rezolvată | Instituția a comunicat rezolvarea. |
| Respinsă | Instituția a respins sesizarea; se păstrează motivul comunicat. |

Starea se păstrează per instituție: una poate răspunde sau respinge în timp ce alta încă analizează. Pregătirea, așteptarea pragurilor și erorile de livrare sunt stări tehnice separate; un email acceptat de transport nu dovedește înregistrarea la instituție. Aceste stări nu înlocuiesc `CivicSubject.stage` sau `visibility`.

Răspunsurile se asociază sesizării și se publică în dreptul ei, cu protejarea datelor personale. Pot fi preluate prin conector sau introduse de un operator autorizat, cu sursă și dovadă. Utilizatorii urmăresc evoluția în aplicație. Comunitatea poate confirma sau contesta rezolvarea reală, separat de răspunsul instituției.

## Praguri inteligente și flux colaborativ

Regulile de transmitere pot impune cumulativ, de exemplu:

- minimum 20 de validări;
- peste 80% voturi „Validez”;
- minimum 5 utilizatori diferiți;
- cel puțin N fotografii, doar dacă regula o cere (fotografiile sunt opționale pe subiect — ADR-0011);
- localizare GPS confirmată.

Aceste valori sunt exemple configurabile, nu praguri implementate sau valori implicite stabilite. Pentru `VALIDITY`, „Validez / Invalidez” corespund valorilor modelului de vot existent; procentul se calculează din voturile active relevante. Un utilizator are un singur vot activ per subiect/tip: numărul validărilor nu se umflă prin numărarea schimbărilor din `VoteEvent`. Metoda de confirmare GPS și semnificația verificării fotografiilor trebuie definite la implementare.

Fluxul prevăzut:

1. Un utilizator creează o sesizare, adaugă descrierea, fotografii, videoclipuri și localizare, apoi revizuiește instituțiile bifate.
2. Comunitatea votează „Validez / Invalidez” și „Importanță”, conform tipurilor de vot active.
3. Sistemul verifică pragurile și afișează condițiile îndeplinite și cele încă neîndeplinite.
4. La atingerea pragurilor, propune trimiterea sau o declanșează dacă modul automat a fost activat explicit. Înainte de transmitere se reverifică pragurile, publicarea și destinatarii.
5. Autoritățile răspund; răspunsurile și evoluția fiecărei depuneri sunt afișate la sesizare.
6. Comunitatea confirmă sau contestă că problema a fost rezolvată efectiv.

Modul manual, modul cu propunere la atingerea pragurilor și modul automat sunt distincte. Politica trebuie să precizeze dacă pragurile condiționează și butonul manual. Evaluarea pragurilor și versiunea regulilor folosite se păstrează în istoric. Reevaluările nu trimit repetat aceeași sesizare; o completare/retransmitere constituie o acțiune distinctă, urmărită explicit.

## Model de date propus

| Entitate | Responsabilitate |
|---|---|
| `Authority` | Registru unic: identitate, denumire, arie de competență, contacte și canale disponibile; fără `subject_id`. |
| `CompetenceRule` | Nod tematic + instituție, responsabilitate, arie (`AdministrativeUnit`), aplicare pe subarbore și perioadă de valabilitate. |
| `SubjectAuthority` | Asocierea subiect–instituție, selecția finală, proveniența automată/manuală și regula sursă, dacă există. |
| `AuthoritySubmission` | Depunere pentru un subiect: număr intern unic, inițiator/mod automat, document și pachet fixate la trimitere, data și evaluarea pragurilor. |
| `AuthorityDelivery` | Destinatarul unei depuneri: instituție, canal/contact folosit, încercări/dovezi, stare, număr și dată de înregistrare externă. |
| `AuthorityResponse` | Răspuns și documente primite, sursă, dată și versiune publicabilă. |
| `AuthorityDeliveryEvent` | Istoric al transmiterii și schimbărilor de stare, cu moment, sursă și dovadă. |

O sesizare `ISSUE` transmisă rămâne același `CivicSubject`; trimiterea formală nu o transformă automat în `PETITION` și nu schimbă sensul voturilor (ADR-0002). Un subiect poate avea mai multe depuneri explicite, fiecare cu livrări către una sau mai multe instituții.

## Etape și criterii de încheiere

- **Step 3.1:** registrul instituțiilor, regulile de competență, selecția automată cu bife și revizuirea destinatarilor; pregătirea pachetului cu media și GPS din Step 3.
- **Step 4.1, după Step 4:** trimitere manuală, propunere/declanșare automată pe praguri, includerea istoricului voturilor, conectori, confirmări și urmărirea răspunsurilor. Emailul este primul canal de implementat; API-urile și portalurile se adaugă individual în funcție de integrările disponibile.

Criterii: aceeași instituție este reutilizată fără duplicare; regulile propun destinatarii corecți; utilizatorul poate debifa/adăuga; o trimitere produce documentul și istoric per destinatar; reluarea nu dublează depunerea; numărul intern și înregistrările externe sunt distincte; pragurile folosesc voturile active; răspunsurile sunt urmărite, iar confirmarea comunității este separată de rezolvarea declarată de instituție.
