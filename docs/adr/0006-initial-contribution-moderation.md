# ADR-0006 — Moderarea contribuțiilor inițiale și propunerile de categorii

**Data:** 08.10.2026  
**Stare:** acceptat și implementat local. Completează regulile de acces din ADR-0002 și Step 2.

## Decizie

1. Primele trei subiecte ale unui utilizator obișnuit necesită aprobarea unui administrator. Utilizatorul poate crea Probleme, Propuneri și Petiții în categoriile existente. Subiectele primesc vizibilitatea `PENDING`, afișată ca „În așteptarea aprobării”; doar autorul și administratorii le pot vedea. Pentru ceilalți, detaliul răspunde cu 404, iar listele nu le includ.
2. După trei contribuții distincte aprobate de un administrator, subiectele următoare se publică direct. Administratorul poate retrage acest drept în caz de abuz și poate ridica ulterior restricția. Cât timp restricția este activă, contribuțiile următoare necesită aprobare indiferent de numărul aprobărilor. Ridicarea restricției nu ocolește pragul de trei.
3. Utilizatorii pot propune categorii noi, inclusiv rădăcini; numai administratorii modifică arborele. Administratorul aprobă și creează categoria sau reutilizează o categorie existentă sub același părinte, pentru a evita duplicatele. Poate și respinge propunerea. Propunerile de categorii nu contează la pragul de trei subiecte aprobate.

## Reguli precise

- Pragul privește aprobările, nu numărul de subiecte trimise: un utilizator poate avea mai mult de trei subiecte în așteptare. O respingere nu contează.
- Un subiect contribuie cel mult o dată la prag, chiar dacă este ascuns, editat și aprobat din nou. Aprobarea este înregistrată separat de vizibilitatea curentă.
- Publicarea unui subiect nepublic de către un administrator înregistrează aprobarea. Publicarea directă a unui utilizator cu drept câștigat nu adaugă o aprobare administrativă.
- Contribuțiile care erau deja publice înaintea acestei schimbări rămân publice. Migrarea nu le consideră automat aprobate administrativ și nu acordă automat dreptul de publicare directă.
- Retragerea dreptului nu ascunde retroactiv toate subiectele publicate; administratorul le poate ascunde separat. Subiectele deja în așteptare nu se publică automat la atingerea pragului sau la ridicarea restricției.
- Dacă un autor fără drept de publicare directă modifică un subiect publicat, acesta revine în așteptare, pentru a nu permite schimbarea conținutului aprobat fără verificare. Notele tehnice personale rămân private și nu declanșează moderarea subiectului.
- Administratorii publică direct și păstrează drepturile existente asupra tipurilor rezervate lor. Conturile noi primesc doar `ROLE_USER`; confirmarea emailului nu acordă publicare directă.
- Dreptul este calculat din baza de date la scriere, nu din JWT. Retragerea se aplică și unei sesiuni deja deschise. Crearea, aprobarea și retragerea sunt serializate pe utilizator într-o tranzacție.
- Propunerile de categorii au `PENDING`, `APPROVED` sau `REJECTED`. Autorul vede propriile propuneri; administratorul le vede pe toate, cu paginare. Numele (3–120 caractere), motivul (maximum 2000) și părintele sunt validate pe server.
- O categorie existentă ori o propunere în așteptare cu același nume, fără diferențiere de majuscule, sub același părinte blochează o propunere duplicată. Aprobarea verifică din nou și reutilizează nodul existent dacă între timp a fost creat. Duplicatele semantice rămân responsabilitatea administratorului.
- Aprobarea și crearea nodului se execută în aceeași tranzacție sub `TreeLock`, cu poziție calculată între frați; o propunere soluționată nu poate crea un al doilea nod.

## Interfață și API

În meniul utilizatorilor obișnuiți apar `Help` (`#/help`) și `Propune o categorie` (`#/categorii-propuse`). Help explică cele trei reguli, inclusiv după înregistrare și autentificarea automată. Numele utilizatorului rămâne numai în meniul deschis, iar bara afișează `Logged in`.

Administratorii au `Moderare subiecte` (`#/moderare`) și `Propuneri de categorii`. Coada permite citirea, aprobarea/publicarea sau respingerea unui subiect. Din detaliul subiectului, administratorul poate inspecta și retrage/ridica restricția de publicare a autorului. Formularul existent permite schimbarea vizibilității, inclusiv reaprobarea unui subiect respins.

| Endpoint | Scop |
|---|---|
| `GET /api/civic_subjects?visibility=PENDING` | Subiecte în așteptare, respectând vizibilitatea și paginarea existente. |
| `PATCH /api/civic_subjects/{id}` cu `visibility: PUBLISHED` sau `HIDDEN` | Aprobare/publicare sau respingere; numai administratorul. |
| `GET /api/me/publishing` | Număr de aprobări, drept curent și restricție pentru utilizatorul autentificat. |
| `GET/PUT /api/users/{id}/publishing` | Administratorul citește politica sau setează `revoked: true/false`. |
| `GET/POST /api/category-suggestions` | Listare proprie/toate pentru administrator, respectiv trimitere propunere. |
| `PATCH /api/category-suggestions/{id}` | Administratorul trimite `status: APPROVED/REJECTED`. |

Moderarea comentariilor, raportările și mecanismele anti-abuz generale rămân în Step 5. Acest ADR implementează aprobarea inițială a subiectelor, fără a pretinde că înlocuiește acel pas.
