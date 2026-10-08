# Înregistrare și autentificare

**Stare:** implementat local la 07.10.2026, Step 2.2.

## Referința și adaptarea

Fluxul pornește de la implementarea existentă în proiectul `sf4`:

- `semese/src/pages/LoginPage.js` și `semese/src/pages/SignUpPage.js`;
- `src/Controller/RegistrationApiController.php`;
- `src/EventSubscriber/AltchaLoginSubscriber.php` și `src/Service/AltchaService.php`.

Validez folosește nume afișat, email și parolă, fără firmă, cod de aplicație, abonament sau rolurile fabricii de APK. Păstrează autentificarea JWT și rotația refresh token-urilor existente.

Biblioteca PHP oficială `altcha-org/altcha` 2.x este compatibilă cu widgetul ALTCHA 3.x deja instalat în frontend. Verificarea se face pe server, conform [documentației ALTCHA](https://altcha.org/docs/integration/server/).

## Fluxul utilizatorului

1. Din „Intră”, utilizatorul alege „Creează un cont” (`#/inregistrare`).
2. Completează numele afișat, emailul, parola și repetarea parolei, apoi verificarea ALTCHA.
3. Primește pe email un cod de șase cifre, valabil 15 minute.
4. Confirmă codul și este autentificat automat cu JWT și refresh token, apoi revine la pagina pentru care a intrat în cont sau la arbore.
5. Dacă nu primește codul, poate solicita unul nou după 60 de secunde, cu o verificare ALTCHA nouă. Poate și corecta adresa de email.

Login-ul unui cont neconfirmat, după verificarea corectă a parolei, oferă acces la pagina de confirmare. Nu retrimite automat emailul; utilizatorul solicită explicit un cod nou.

Parola publică are minimum 10 caractere și maximum 72 de octeți, compatibil cu algoritmul de hash configurat. Numele afișat are între 3 și 120 de caractere, emailul maximum 180. Emailul este normalizat cu trim și lowercase.

## API

| Endpoint | Rol |
|---|---|
| `GET /api/captcha/challenge` | Challenge ALTCHA nou, expiră în 10 minute. |
| `POST /api/register` | Primește `displayName`, `email`, `password`, `altcha`; pregătește contul și trimite codul. |
| `POST /api/register/confirm` | Primește `email`, `code`; confirmă adresa și returnează `token`, `refresh_token`. |
| `POST /api/register/resend` | Primește `email`, `altcha`; retrimite codul pentru un cont neconfirmat. |
| `POST /api/auth` | Login-ul existent, cu `email`, `password` și suplimentar `altcha`. |
| `POST /api/token/refresh` | Fluxul existent de refresh, fără ALTCHA. |

Înregistrarea și retrimiterea returnează `status: code_sent` și `retryAfter: 60`, fără codul propriu-zis și fără a indica existența unui cont activ. Conturile active nu sunt modificate de o nouă cerere de înregistrare. Pentru conturile neconfirmate, o nouă înregistrare după cooldown actualizează numele și parola și emite un cod nou.

Erorile sunt afișate în română. `Retry-After` este expus prin CORS pentru cooldown-ul interfeței. Widgetul ALTCHA este resetat după o încercare eșuată, deoarece soluția poate fi deja consumată.

## Reguli de acces și protecție

- Înregistrarea publică nu acceptă roluri sau confirmarea emailului din payload. Contul nou primește numai `ROLE_USER`.
- `emailVerified` este false pentru înregistrările publice. Migrarea păstrează conturile existente active; conturile create prin comanda administrativă sunt active implicit.
- Un cont neconfirmat nu poate primi o sesiune prin login. Mesajul despre confirmare apare numai după o parolă corectă.
- Codurile sunt generate aleator și stocate ca hash HMAC, legat de utilizator și `APP_SECRET`, nu în clar. După cinci coduri greșite, codul curent este invalidat.
- Înregistrarea/retrimiterea sunt limitate împreună la cinci cereri pe oră per IP. Confirmarea are limite separate de 15 cereri pe oră per IP și per email. Emiterea challenge-urilor este limitată la 60 pe minut per IP. Login-ul păstrează `login_throttling` existent.
- Emiterea și confirmarea codurilor sunt serializate per email într-o tranzacție, inclusiv actualizarea contorului de încercări.
- ALTCHA este verificat criptografic în backend; soluția este consumată o singură dată prin inserare atomică într-o tabelă cu cheie unică. Expirările sunt curățate la verificare. Stocarea comună protejează împotriva cererilor concurente pe procese diferite.
- Challenge-urile și răspunsurile înregistrării sunt `private, no-store`.
- Cheile HMAC ALTCHA sunt derivate separat din `APP_SECRET`; nu se copiază secrete din `sf4`.

## Mediu local și producție

Local, `MAILER_DSN=smtp://mailcatcher:1025`; mesajele se văd în Mailcatcher la `http://localhost:1082`. În teste se folosește transportul nul și se verifică mesajele fără livrare externă.

Pe producție trebuie configurate `MAILER_DSN` pentru SMTP real și `MAILER_FROM_ADDRESS` pentru o adresă de expeditor validă. Expeditorul implicit este `noreply@validez.webnou.ro`. `ALTCHA_COST` este implicit 1500, ca în implementarea de referință, și poate fi ajustat după verificare pe dispozitiv. Testele folosesc cost 1, cu verificare criptografică și consum real, fără bypass ALTCHA.

Widgetul este inclus din pachetul npm, cu limba română și culorile temei Validez. Nu depinde de un CDN. Verificarea pe browser/Android trebuie făcută într-un context care permite WebCrypto (localhost sau HTTPS).

`backend/bin/smoke-tree.sh` rezolvă acum un challenge înainte de login, prin `backend/bin/solve-altcha.php`. Orice client extern care folosește direct `/api/auth` trebuie de asemenea să furnizeze o soluție ALTCHA validă.

Recuperarea parolei și autentificarea prin furnizori externi nu fac parte din acest pas.

## După confirmarea contului: contribuții și Help

Un cont nou confirmat are `ROLE_USER` și contribuie conform [ADR-0006](adr/0006-initial-contribution-moderation.md). Primele trei subiecte distincte necesită aprobare administrativă; autorul le vede cu starea „În așteptarea aprobării”. După trei aprobări poate publica direct, dacă administratorul nu a retras acest drept. Utilizatorul poate propune categorii noi, dar numai administratorii creează noduri și rădăcini în arbore.

Meniul utilizatorului obișnuit include `Help`, cu explicația acestor trei reguli, și `Propune o categorie`. Bara afișează `Logged in`, iar numele utilizatorului apare doar după deschiderea meniului.

## Recuperarea parolei

**Stare:** implementată local la 08.10.2026.

Din pagina `#/login`, „Am uitat parola” deschide `#/am-uitat-parola` și păstrează emailul completat și destinația de după autentificare. Fluxul funcționează și în WebView, fără linkuri externe/deep links:

1. Utilizatorul completează emailul și verificarea ALTCHA, apoi solicită codul.
2. Mesajul este întotdeauna „Dacă există un cont confirmat pentru această adresă, vei primi un cod pe email”, fără a dezvălui existența contului. Doar conturile cu email confirmat primesc emailul; resetarea nu creează și nu confirmă conturi.
3. Introduce codul de șase cifre, noua parolă și repetarea acesteia. Codul este valabil 15 minute. Parola respectă aceeași regulă ca la înregistrare: minimum 10 caractere și maximum 72 de octeți UTF-8.
4. Poate solicita un cod nou după 60 de secunde, cu o verificare ALTCHA nouă, sau poate corecta adresa. Retrimiterea invalidează codul precedent.
5. După succes, revine explicit la login, cu emailul păstrat. Nu primește automat o sesiune nouă.

| Endpoint | Rol |
|---|---|
| `POST /api/password-reset/request` | Primește `email`, `altcha`; returnează generic `status: code_sent`, `retryAfter: 60`. Solicitarea inițială și retrimiterea folosesc același endpoint. |
| `POST /api/password-reset/confirm` | Primește `email`, `code`, `password`; consumă codul și schimbă parola; returnează `status: password_reset`, fără JWT sau refresh token. |

Codurile de resetare au câmpuri separate de confirmarea înregistrării și sunt stocate numai ca hash HMAC, legat de utilizator, scopul „password-reset” și `APP_SECRET`. După cinci coduri greșite, codul curent este invalidat. Solicitările sunt limitate la cinci pe oră per IP și una pe minut per email, inclusiv pentru adrese inexistente; confirmarea are bugete separate de 15 încercări pe oră per IP și per email. `Retry-After` este transmis interfeței. Răspunsurile reușite sunt `private, no-store`.

Operațiile se execută într-o tranzacție sub același lock per email ca înregistrarea și cu blocarea rândului utilizatorului. Parola rămâne neschimbată până la confirmarea unui cod valid. Codul este consumat o singură dată; tentativa eșuată nu modifică parola și nu invalidează sesiunile.

Resetarea reușită incrementează `User.credentialVersion` și șterge refresh token-urile contului. JWT-urile poartă această versiune, verificată din baza de date la decodare, astfel încât token-urile de acces anterioare resetării nu mai sunt acceptate. JWT-urile emise înainte de migrare sunt tratate ca versiunea zero, rămânând valide până la prima resetare. Hash-ul parolei nu este inclus în JWT. Frontend-ul șterge și sesiunea locală după succes.

Dacă trimiterea emailului eșuează, tranzacția este anulată și se înregistrează o eroare în log; răspunsul rămâne generic pentru a nu dezvălui existența contului prin eroarea SMTP. Utilizatorul poate reîncerca după cooldown. Se reutilizează configurația `MAILER_DSN` și `MAILER_FROM_ADDRESS` existentă.
