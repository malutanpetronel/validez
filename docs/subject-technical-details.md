# Note tehnice personale și estimare de cost pentru propuneri

**Stare:** implementat local la 07.10.2026 — Step 2.1. Istoricul modificărilor după primele voturi rămâne pentru Step 4.

## Scop și loc în plan

Autorul unei propuneri poate păstra detalii de implementare ca memorie de lucru pentru o eventuală realizare. Acest lucru este util inclusiv autorilor activi, cu multe idei. Opțional, poate publica o estimare de cost și o explicație a ceea ce acoperă suma.

Descrierea publică continuă să prezinte problema, soluția și beneficiile. Notele personale rămân separate de conținutul public.

Prima implementare este **Step 2.1**, după subiectele funcționale și înainte de Step 3, conform [planului](implementation-plan.md). Istoricul estimării după primele voturi se tratează la Step 4.

## Câmpuri și stocare

Cele trei informații discutate inițial (`tehnic`, `tehnic_pret`, `tehnic_include`) folosesc denumiri în engleză, coerente cu modelul existent. Se adaugă explicit moneda.

| Câmp | Etichetă în interfață | Conținut | Stocare și acces |
|---|---|---|---|
| `technicalNotes` | Note personale — nu sunt publice | Text liber: componente, pași, soluții și observații de lucru. | Textul unei entități separate `SubjectPrivateNote`; acces API exclusiv autorului subiectului. |
| `costEstimate` | Cost estimativ | Sumă exactă, opțională. | Pe `CivicSubject`; publică doar pentru `PROPOSAL`. |
| `costCurrency` | Monedă | Cod ISO 4217; formularul propune `RON` doar la introducerea unei sume. Exemplul de mai jos folosește `EUR`. | Pe `CivicSubject`; afișat împreună cu suma. |
| `costEstimateScope` | Ce acoperă estimarea | Primul rând precizează unitatea sau întinderea estimării; apoi pot urma detalii despre includeri și excluderi. | Pe `CivicSubject`; public doar pentru `PROPOSAL`. |

Secțiunile sunt opționale. Publicarea unei sume necesită o monedă validă și o explicație nevidă. Valoarea nulă înseamnă lipsa estimării; zero este o valoare distinctă și nu trebuie ascuns printr-o verificare de tip truthy.

Pentru Step 2.1, baza de date impune printr-o constrângere `CHECK` că cele trei câmpuri publice (`costEstimate`, `costCurrency`, `costEstimateScope`) sunt fie toate `NULL`, fie toate completate, cu explicația nevidă după eliminarea spațiilor. Moneda nu are un default independent în baza de date. Formularul propune `RON` numai când autorul introduce o sumă, inclusiv zero; eliminarea estimării golește toate cele trei câmpuri. API-ul validează aceeași regulă pe starea finală a resursei, inclusiv la actualizări parțiale.

Suma se stochează ca `NUMERIC(12, 2)` exact, cu valori între `0.00` și `9999999999.99`, niciodată ca `float`. În API și în formular, valoarea decimală se transmite ca string, de exemplu `"940.00"`; calculele nu trebuie să piardă precizia prin conversie la virgulă mobilă. Monedele acceptate sunt RON, EUR, USD și GBP, toate cu două zecimale. API-ul validează suma fără conversie la float.

## Note private: resursă separată

Modelul folosește `SubjectPrivateNote` cu subiect, autor și text, cu maximum o notă per subiect și autor. Autorul se determină din autentificare și trebuie să fie autorul subiectului; identificatorul trimis de client nu conferă drepturi.

Nota individuală se citește și se modifică exclusiv prin endpointul dedicat `/api/civic_subjects/{id}/note`. Nu se adaugă textul notei sau o relație serializabilă către aceasta în răspunsurile publice ale `CivicSubject`. Listele și detaliile publice nu încarcă nota; endpointul propriu verifică autorizarea la fiecare operație, inclusiv pentru administratori.

Această separare reduce riscul unei expuneri prin configurarea grupurilor de serializare. Nu constituie o garanție împotriva oricărei greșeli de implementare; izolarea trebuie verificată prin teste de acces și de răspuns public.

„Nu sunt publice” descrie accesul prin aplicație. Operatorul platformei poate avea acces la baza de date și la backup-uri; interfața nu promite că notele sunt secrete față de operator. Notele se includ în mecanismele de export și ștergere a datelor utilizatorului. Tratamentul copiilor de siguranță se documentează în politica de retenție.

## Disponibilitate inițială pe tipuri

Tipurile și definițiile lor provin din [ADR-0002](adr/0002-node-vs-civic-subject.md). Disponibilitatea de mai jos este propunerea pentru această funcționalitate.

| Tip | Note personale și estimare |
|---|---|
| `PROPOSAL` — Propunere | Disponibile, opționale. |
| `PROJECT` — Proiect | Indisponibile. |
| `ISSUE` — Problemă / sesizare | Indisponibile. |
| `PETITION` — Petiție | Indisponibile. |
| `PROMISE` — Promisiune | Indisponibile. |
| `ELECTORAL_EVALUATION` — Evaluare electorală / a unui ales | Indisponibile. |
| `TOPIC_EVALUATION` — Evaluare tematică | Indisponibile. |

Un proiect are deja un responsabil și un buget; „Estimarea autorului” s-ar putea confunda cu bugetul aprobat. Pentru `PROJECT` se va defini ulterior o informație distinctă, „Buget aprobat”, cu moneda și sursa indicate.

Conform ADR-0002, adoptarea unei propuneri duce la crearea unui proiect separat, legat de propunerea de origine. Estimarea nu se transformă automat în buget aprobat și nu se copiază automat ca atare.

Disponibilitatea depinde de tipul subiectului, nu de tipurile de vot active. Restricțiile se aplică atât în interfață, cât și în API.

## Schimbarea tipului

Schimbarea din `PROPOSAL` în `ISSUE` sau alt tip neeligibil este posibilă deja și trebuie tratată în prima implementare:

- Nota și estimarea existente se păstrează în stocare.
- Estimarea nu se mai expune în liste sau în detaliu; nota nu mai este disponibilă prin endpointul propriu, nici autorului, cât timp tipul este neeligibil.
- Completarea și modificarea acestor informații sunt refuzate pentru tipurile neeligibile.
- Revenirea la `PROPOSAL` restabilește disponibilitatea datelor păstrate. Formularul explică faptul că estimarea păstrată devine din nou publică.
- Exportul și ștergerea datelor personale includ și notele păstrate pe subiectele neeligibile.

Ascunderea nu provoacă ștergere automată. Regula de blocare a schimbării tipului după primul vot rămâne cea prevăzută în ADR-0002, de implementat împreună cu voturile.

## Exemplu și prezentare

Pentru „Kiss & Ride”:

- `technicalNotes`: memo privat despre senzori, alimentare, avertizare optică, componente și montaj, salvat separat.
- `costEstimate`: `"940.00"`.
- `costCurrency`: `EUR`.
- `costEstimateScope`: „Pentru un loc de parcare inteligentă”, urmat opțional de detalii despre echipamente, montaj, TVA, mentenanță și excluderi.

Prezentare publică:

> Estimarea autorului: 940 EUR
>
> Pentru un loc de parcare inteligentă.

În listă, suma nu se afișează niciodată fără primul rând complet al explicației. Acest rând permite revenirea pe mai multe linii și nu este trunchiat cu elipsă. Restul explicației poate rămâne în detaliu. Astfel, într-o propunere pentru trei locuri, suma pentru un singur loc nu pare costul întregului proiect. Nu adăugăm inițial un câmp separat pentru unitate.

Formularul separă vizual nota personală de estimarea publică. Lipsa estimării nu produce etichete goale. „Estimarea autorului” indică proveniența sumei și nu o prezintă ca buget aprobat sau ofertă comercială.

## Relația cu votul

[ADR-0003](adr/0003-voting-model.md) definește voturi configurabile per `CivicSubject`, precum `VALIDITY`, `YES_NO` și `IMPORTANCE`. Evaluarea unui ales este tot un `CivicSubject`, de tip `ELECTORAL_EVALUATION`.

Notele și estimarea nu sunt tipuri de vot și nu modifică automat voturile, agregările sau formula priorității. Notele private pot evolua fără schimbarea conținutului public votat. Modificările sumei, monedei și explicației publice pot influența votanții; istoricul lor după primele voturi se definește la Step 4, împreună cu regulile de editare a conținutului votat.

Susținerea propunerii nu implică selectarea autorului ca executant. Un eventual rol comercial al autorului poate fi declarat separat într-o etapă ulterioară.

## Contract API și limite implementate

- `GET /api/civic_subjects/{id}/note`: răspunde cu `{"technicalNotes": "..."}`; o notă absentă este reprezentată prin text gol, fără crearea unui rând în baza de date.
- `PUT /api/civic_subjects/{id}/note`: înlocuiește nota cu textul trimis în `technicalNotes`. Textul este limitat la 10.000 de caractere; textul gol după trim elimină nota.
- `DELETE /api/civic_subjects/{id}/note`: elimină nota și răspunde cu text gol.
- Accesul neautentificat este refuzat cu 401; alt utilizator, administratorul care nu este autor, un subiect inexistent sau de tip neeligibil primesc 404. Răspunsurile notei sunt `private, no-store`.
- `GET /api/me/subject-notes`: exportă numai notele utilizatorului autentificat, cu identificatorul, titlul și tipul subiectului, inclusiv notele păstrate pentru tipuri neeligibile.
- `DELETE /api/me/subject-notes`: șterge numai notele utilizatorului autentificat, inclusiv cele păstrate. Aceste operații sunt disponibile prin API; interfața generală de export/ștergere a contului nu face parte din Step 2.1.
- Nota se șterge prin cascade la ștergerea subiectului sau a autorului. Nu introduce dreptul de a șterge un subiect sau un cont.
- `costEstimateScope`: maximum 2.000 de caractere; primul rând după trim, maximum 200. Formularul permite virgula zecimală și trimite suma ca string cu punct.
- Crearea/editarea publică și salvarea notei sunt cereri separate. Dacă salvarea notei eșuează după salvarea subiectului, formularul explică situația și păstrează identificatorul pentru reîncercare fără duplicare.

## Etape ulterioare

- La Step 4: istoricul modificărilor estimării publice după primele voturi.
- Integrarea exportului și ștergerii notelor în viitorul flux general pentru datele contului și documentarea retenției backup-urilor.
- Buget aprobat și sursă pentru `PROJECT`, separat de estimarea autorului.
