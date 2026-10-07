# Note tehnice personale și estimare de cost pentru propuneri

**Stare:** propunere pentru prima implementare, discutată și revizuită la 07.10.2026. Nu este încă implementată.

## Scop și loc în plan

Autorul unei propuneri poate păstra detalii de implementare ca memorie de lucru pentru o eventuală realizare. Acest lucru este util inclusiv autorilor activi, cu multe idei. Opțional, poate publica o estimare de cost și o explicație a ceea ce acoperă suma.

Descrierea publică continuă să prezinte problema, soluția și beneficiile. Notele personale rămân separate de conținutul public.

Prima implementare este propusă ca **Step 2.1**, după subiectele funcționale și înainte de Step 3, conform [planului](implementation-plan.md). Istoricul estimării după primele voturi se tratează la Step 4.

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

Suma se stochează ca `decimal` exact sau ca număr întreg de unități monetare minore, niciodată ca `float`. În API și în formular, valoarea decimală se transmite ca string, de exemplu `"940.00"`; calculele nu trebuie să piardă precizia prin conversie la virgulă mobilă. Precizia și limitele se stabilesc înainte de migrare. Monedele acceptate și precizia lor trebuie validate explicit.

## Note private: resursă separată

Se propune `SubjectPrivateNote` cu subiect, autor și text, cu maximum o notă per subiect și autor. Autorul se determină din autentificare și trebuie să fie autorul subiectului; identificatorul trimis de client nu conferă drepturi.

Nota se citește și se modifică exclusiv printr-un endpoint dedicat, de exemplu `/api/civic_subjects/{id}/note`. Nu se adaugă textul notei sau o relație serializabilă către aceasta în răspunsurile publice ale `CivicSubject`. Listele și detaliile publice nu încarcă nota; endpointul propriu verifică autorizarea la fiecare operație, inclusiv pentru administratori.

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

## Detalii rămase înainte de implementare

- Limitele textelor, inclusiv primul rând al explicației.
- Precizia și limita sumei, monedele acceptate și reprezentarea exactă în baza de date.
- Contractul operațiilor endpointului notei și răspunsurile pentru acces refuzat sau notă absentă.
- La Step 4: istoricul modificărilor estimării publice după primele voturi.
