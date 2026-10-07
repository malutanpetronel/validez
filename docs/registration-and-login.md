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
