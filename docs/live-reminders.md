# Validez — note pentru live

Tot ce trebuie făcut sau verificat la punerea pe producție (mediul de producție, în spatele proxy-ului de intrare), adunat pe măsură ce apare în dezvoltare. Bifează aici verificările generale. IP-urile reale, inventarele și căile private de administrare se păstrează exclusiv în notele operaționale private, în afara fișierelor versionate.

Marcaje: **[PE LAPTOP]** = `<checkout-validez>` sau checkout-ul Ansible de pe laptop; **[PE SERVER]** = VPS-ul de producție.

## 1. Decizii încă deschise

- [x] **Domeniul BE.** `validez-be.webnou.ro` (decis 12.10.2026, după modelul `artanft-be`). Apare în trei locuri, de schimbat împreună dacă se schimbă vreodată: `backend/.env.prod.sample` (`VIRTUAL_HOST`, `DEFAULT_URI`), `backend/nginx/vhost.d.prod` (comentariul cu calea de copiere), `frontend/docker-build-push.sh` (`API_BASE`).
- [x] **FE** rămâne `validez.webnou.ro` (decis), cu `CORS_ALLOW_ORIGIN` pe el în `backend/.env.prod.sample`.

## 2. DNS

- [x] **[PE LAPTOP]** Înregistrări A către `<PRODUCTION_SERVER_IP>` pentru `validez.webnou.ro` și pentru domeniul BE, în zona BIND, pe NS-urile proprii (ca la ArtaNFT), apoi `ansible-playbook <checkout-ansible>/main.yml -i <inventar-dns-primar> -f 10 --tags bind` și același lucru pentru `<inventar-dns-secundar>`.
- [x] Verificare propagare pe serverele DNS autoritative și pe 8.8.8.8 / 1.1.1.1 înainte de primul deploy (Let's Encrypt eșuează altfel) — verificat 12.10.2026.

## 3. Ansible (de scris înainte de primul deploy)

- [x] **[PE LAPTOP]** Playbook-uri `deploy_validez_be.yml` și `deploy_validez_fe.yml` în `<checkout-ansible>/`, plus inventare `environments/validez_be` și `environments/validez_fe` (model: `deploy_artanft_be.yml` / `deploy_artanft_fe.yml`) — scrise 12.10.2026.
- [x] Repository-ul e **monorepo**: compose-ul BE rulează din `<release>/backend`, cel FE din `<release>/frontend`. Căile din playbook trebuie să țină cont de subfolder (la ArtaNFT, compose-ul era în rădăcina release-ului).
- [x] Shared ansistrano pentru BE: `.env` → `/var/www/validez-be/shared/backend/.env`; directoare `/var/www/validez-be/shared/backend/public/uploads`, `/var/www/validez-be/shared/backend/var`, `/var/www/validez-be/shared/backend/vendor`, `/var/www/validez-be/shared/backend/config/jwt`; `chown -R 1000:1000` pe ele la deploy (devuser din imaginea PHP).
- [x] Shared pentru FE: `.env` → `/var/www/validez-fe/shared/frontend/.env`.
- [x] Playbook-ul BE copiază `backend/nginx/vhost.d.prod` în `/srv/nginx/vhost.d/validez-be.webnou.ro` **înainte** de pornirea containerelor (nginx-proxy îl include doar dacă există la generarea configurației) și face reload cu `nginx -t` când se schimbă — lecția ArtaNFT din 01.10.2026.
- [x] Ordinea în playbook-ul BE: `composer install --no-dev --optimize-autoloader` ca devuser → `doctrine:migrations:migrate -n` → `cache:clear --env=prod` → abia apoi trafic (nginx pornește ultimul).

## 4. Pe server, o singură dată (BE)

- [x] **[PE SERVER]** `/var/www/validez-be/shared/backend/.env` pornind de la `backend/.env.prod.sample`, cu toate cheile (fișierul înlocuiește complet `.env`-ul Symfony):
  - [x] `COMPOSE_PROJECT_NAME=validez_be` — **obligatoriu**: altfel proiectul compose se numește `backend` și se amestecă cu orice alt proiect dintr-un folder `backend` (problema din 03.10.2026 cu EvoPresence).
  - [x] `DOCKER_IMAGE=` tag-ul afișat de `backend/docker-build-push.sh`.
  - [x] `DB_PASSWORD`, `APP_SECRET`, `JWT_PASSPHRASE` noi, nu cele de pe dev.
  - [x] `SYMFONY_TRUSTED_PROXIES` cu IP-urile/CIDR-urile exacte ale nginx-ului intern și ale Nginx_Proxy (nu `0.0.0.0/0`) — subrețelele rețelelor `proxy` și `validez_be_internal`, 12.10.2026. **Important pentru login:** `login_throttling` (5 încercări greșite/minut) numără per IP; fără proxy-uri de încredere, toți utilizatorii apar cu IP-ul proxy-ului și se blochează unii pe alții.
  - [x] `MAILER_DSN` real și `MAILER_FROM_ADDRESS` valid: înregistrarea publică trimite coduri de confirmare pe email (Step 2.2). Implicit, local se folosește Mailcatcher.
  - [x] Verificare ALTCHA pe browser și Android; `ALTCHA_COST` implicit 1500. Cheile sunt derivate din `APP_SECRET`, fără secrete suplimentare.
- [x] **[PE SERVER]** Chei JWT generate pe server, nu copiate de pe dev, în `/var/www/validez-be/shared/backend/config/jwt/` (`private.pem`, `public.pem`, cu passphrase-ul din `.env`), proprietar 1000:1000.
- [x] **[PE SERVER]** Directorul de date PostgreSQL: `/srv/www/validez-be.webnou.ro/db_data` (din `backend/docker-compose.prod.yml`).
- [x] `ltree`: migrarea rulează `CREATE EXTENSION IF NOT EXISTS ltree`; utilizatorul din imaginea oficială `postgres:16-alpine` e superuser, deci merge. Dacă vreodată baza vine din altă parte, extensia trebuie creată de un superuser.

## 5. Pe server, o singură dată (FE)

- [x] **[PE SERVER]** `/var/www/validez-fe/shared/frontend/.env` pornind de la `frontend/.env.prod.sample`: `COMPOSE_PROJECT_NAME=validez_fe` (același motiv ca la BE), `VIRTUAL_HOST=validez.webnou.ro`, `WEB_CONTAINER=validez_fe_web`, `DOCKER_IMAGE=` tag-ul afișat de `frontend/docker-build-push.sh`.

## 6. Build și deploy

- [ ] **[PE LAPTOP]** `cd <checkout-validez>/backend && ./docker-build-push.sh` → tag-ul în `/var/www/validez-be/shared/backend/.env` pe server.
- [ ] **[PE LAPTOP]** `cd <checkout-validez>/frontend && ./docker-build-push.sh` → tag-ul în `/var/www/validez-fe/shared/frontend/.env` pe server. Scriptul cere ramura `main`, incrementează versiunea, face commit doar pentru `frontend/src/version.json` și `git push origin main` înainte de build; la eșec se oprește. Modificările de cod se comit separat înainte de rulare. Adresa API-ului (`https://validez-be.webnou.ro`) e **arsă în bundle la build** — dacă se schimbă domeniul BE, imaginea FE trebuie reconstruită.
- [ ] **[PE LAPTOP]** Deploy cu Ansible (BE întâi, apoi FE).
- [ ] **[PE SERVER]** Verifică versiunea Docker Engine (`docker version`). Serverul are docker-compose 1.29, care pe Engine 25+ crapă cu `KeyError: 'ContainerConfig'` la recrearea unui container (ne-a lovit pe laptop pe 03.10.2026). La ArtaNFT deploy-ul a mers, dar dacă apare: `docker rm -f` pe containerul vizat și `up -d` din nou, sau Compose v2. Fișierele compose rămân pe `version: "3.5"` / `"3"` pentru 1.29.

## 7. După primul deploy

- [ ] **[PE SERVER]** Primul administrator, interactiv (parola se cere ascuns, fără `-T`): `cd /var/www/validez-be/current/backend && docker compose -f docker-compose.prod.yml exec php bin/console app:user:create adresa@exemplu.ro "Nume" --admin` (cu `docker-compose` dacă pe server e doar 1.29).
- [ ] Verificări HTTP pe live:
  - [x] `GET https://validez-be.webnou.ro/api/tree_nodes` → 200 fără autentificare.
  - [x] `POST https://validez-be.webnou.ro/api/tree_nodes` fără token → 401.
  - [x] `https://validez-be.webnou.ro/api/docs` → 404 din proxy (vhost.d), nu din Symfony.
  - [x] CORS doar pentru `https://validez.webnou.ro` (și `http://localhost` pentru WebView-ul Cordova).
  - [ ] Login din FE cu ALTCHA, creare nod, refresh după 30 de minute fără re-login.
  - [x] Înregistrare publică: email cu cod, confirmare, login automat, retrimitere după cooldown; un cont neconfirmat nu poate intra — verificat 12.10.2026.
  - [ ] Mai multe cereri succesive la API: toate corecte, niciun „File not found." (ar indica nginx care ajunge la PHP-ul altui proiect — aliasul `validez-fpm` și rețeaua `validez_be_internal` sunt acolo exact pentru asta).
  - [x] Login greșit de 6 ori → blocare; de pe alt IP → încă merge (confirmă `SYMFONY_TRUSTED_PROXIES`) — verificat 12.10.2026, telefon pe date mobile.

## 8. Mentenanță periodică

- [ ] **[PE SERVER]** Curățarea refresh token-urilor expirate (tabela `refresh_token` crește la fiecare login/refresh, cu rotație): cron zilnic, linia din `Deploy.md` pasul 13 (`crontab -e`): `30 4 * * * docker exec -u devuser validez_be_php php /var/www/validez-be/bin/console gesdinet:jwt:clear --env=prod >/dev/null 2>&1`.
- [ ] Backup pentru `/srv/www/validez-be.webnou.ro/db_data` (sau `pg_dump` programat).

## 9. De știut (fără acțiune imediată)

- Migrațiile sunt denumite în **UTC** (ora containerului). Prima a fost redenumită pe 03.10.2026 (`Version20261003204935` → `Version20261003174935`) ca ordinea să fie corectă pe o bază nouă; pe prod nu contează, nu rulase nicăieri acolo.
- Migrarea Step 1.4 se oprește singură dacă `tree_node` are deja noduri fără autor; pe o bază nouă de prod nu e cazul.
- Sesiunea FE (token + refresh token) stă în `localStorage`, ca la ArtaNFT — compromis acceptat pentru Cordova; protecția vine din rotația refresh token-ului și din TTL-ul scurt al token-ului de acces.
- Build-ul Android (smoke test) e programat la Step 1.7 și cere SDK-ul pe laptop.

## 10. Confidențialitate

- [ ] Confirmă operatorul și contactul Validez pentru pagina `#/privacy`, precum și perioadele de păstrare și furnizorii reali de găzduire/email înainte de publicarea politicii.
- [ ] Verifică informarea cookie, redeschiderea și pagina privacy pe browser și Android; verifică și accesul din înregistrare.
- [x] **Android, API de producție** (15.10.2026, verificat de Petro pe telefon: arborele se încarcă după `npm run android`): hook-ul `frontend/hooks/check-api-base.js` (`before_prepare`, doar Android) oprește `cordova run/build android` dacă `www/` conține build-ul de dev (`http://localhost:8020` din `.env.local`) — `npm run build && cordova run android` nu mai poate instala o aplicație cu arborele gol.
- [ ] **Voturi (ADR-0007), înainte de activare pe producție:** rolul PostgreSQL al aplicației nu trebuie să fie owner pe `integrity_event_log` (owner-ul poate face `TRUNCATE`, poate dezactiva sau elimina triggerul append-only). Migrațiile rulează cu un rol separat de cel folosit de aplicație la runtime, iar rolul aplicației primește doar `SELECT, INSERT` pe jurnal. Configurarea curentă încă folosește același utilizator (`validez`). Scriptul [configure-roles.sql](../backend/docker/postgres/admin/configure-roles.sql) și [instrucțiunile](postgresql-roles.md) sunt pregătite și testate local cu rollback; nu sunt aplicate pe producție. Adaptarea Compose/playbookului trebuie să elimine și secretele administrative/de migrare din fișierele montate în PHP, nu doar să schimbe `DATABASE_URL`. Scriptul se reaplică după migrări, înainte de trafic.
- [x] **Voturi, activare (13.10.2026):** rolurile PostgreSQL separate aplicate; secțiunea „Voturi” din privacy în versiunea 2026-10-13 (termene concrete: urmele de integritate cât timp există subiectul și rezultatele lui, copiile de siguranță cel mult 30 de zile); `VOTING_ENABLED=true` în `/var/www/validez-be/shared/backend/.env`, deploy BE cu `-e validez_allow_voting=true`. Verificarea juridică de specialitate rămâne de făcut; dacă schimbă prelucrarea semnificativ, se incrementează versiunea informării (rândul următor).
- [x] **Copii de siguranță, 30 de zile** (14.10.2026: cronul `/etc/cron.d/validez-backup-rotation` verificat pe server, nicio copie peste 30 de zile; backupurile furnizorului VPS nefolosite — dacă se activează, retenția lor trebuie să fie de cel mult 30 de zile)**:** **[PE SERVER]** cronul de rotație din `Deploy.md`, secțiunea E, instalat; verificat că nicio altă copie a bazei (snapshot VPS, copii pe alt disc sau pe laptop) nu depășește 30 de zile.
- [ ] **Voturi, la fiecare actualizare relevantă a informării:** **[PE SERVER]** setează `VOTE_CONSENT_NOTICE_VERSION` în `/var/www/validez-be/shared/backend/.env` (altfel rămâne valoarea implicită din `config/packages/votes.yaml`). Utilizatorii reconfirmă înainte de următorul vot; voturile existente rămân.
- [ ] **Voturi, după deploy sau restore** (după restore: întâi procedura din `Deploy.md` E2, cu `app:votes:reapply-erasures` înaintea verificării — ADR-0009)**:** **[PE SERVER]** `cd /var/www/validez-be/current/backend && docker compose -f docker-compose.prod.yml exec -T php bin/console app:votes:verify` (cod 0 = consistent). `bin/smoke-votes.sh` e doar pentru dev: dezactivează temporar triggerul la curățare.
- [x] **Loguri fără rutele de vot (ADR-0010), după primul deploy cu fixul** (verificat 15.10.2026: fișierele în `conf.d`/`vhost.d`, `include` generat de docker-gen, `PUT` pe `vote-consent` → 401 și 0 linii în `proxy-web` și `validez_be_nginx`, rotație 3 × 4 MB activă; prima variantă cu `$uri` nu filtra în nginx-ul BE — corectat cu `$request_uri`): **[PE SERVER]** `ls -l /srv/nginx/conf.d/validez-be-log.conf /srv/nginx/vhost.d/validez-be.webnou.ro_location`, `docker exec proxy-web grep -n "_location" /etc/nginx/conf.d/default.conf` (include pentru validez-be), apoi un `PUT` fără token pe `https://validez-be.webnou.ro/api/me/vote-consent` (401) nu trebuie să apară în `docker logs proxy-web` și nici în `docker logs validez_be_nginx`; `docker inspect validez_be_php --format '{{json .HostConfig.LogConfig}}'` arată `max-size 4m`. La o rută nouă care dezvăluie un vot: adaug-o în ambele liste (`backend/nginx/default.conf`, `backend/nginx/proxy-log-map.conf.prod`).
