# Validez — note pentru live

Tot ce trebuie făcut sau verificat la punerea pe producție (server 213.202.211.72, în spatele Nginx_Proxy), adunat pe măsură ce apare în dezvoltare. Bifează aici, nu în altă parte.

Marcaje: **[PE LAPTOP]** = `/home/petro/Documents/Docker/www/validez` sau checkout-ul Ansible de pe laptop; **[PE SERVER]** = VPS-ul de producție.

## 1. Decizii încă deschise

- [ ] **Domeniul BE.** Provizoriu `validez-be.webnou.ro` (după modelul `artanft-be`). Apare în trei locuri, de schimbat împreună dacă se decide altfel: `backend/.env.prod.sample` (`VIRTUAL_HOST`, `DEFAULT_URI`), `backend/nginx/vhost.d.prod` (comentariul cu calea de copiere), `frontend/docker-build-push.sh` (`API_BASE`).
- [ ] **FE** rămâne `validez.webnou.ro` (decis), cu `CORS_ALLOW_ORIGIN` pe el în `backend/.env.prod.sample`.

## 2. DNS

- [ ] **[PE LAPTOP]** Înregistrări A către 213.202.211.72 pentru `validez.webnou.ro` și pentru domeniul BE, în zona BIND, pe NS-urile proprii (ca la ArtaNFT), apoi `ansible-playbook ~/Documents/Docker/ansible_vps/petro-vps/main.yml -i ~/Documents/Docker/ansible_vps/petro-vps/environments/prod_api -f 10 --tags bind` și același lucru pentru `prod_ns2`.
- [ ] Verificare propagare pe ns2/api și pe 8.8.8.8 / 1.1.1.1 înainte de primul deploy (Let's Encrypt eșuează altfel).

## 3. Ansible (de scris înainte de primul deploy)

- [ ] **[PE LAPTOP]** Playbook-uri `deploy_validez_be.yml` și `deploy_validez_fe.yml` în `~/Documents/Docker/ansible_vps/petro-vps/`, plus inventare `environments/validez_be` și `environments/validez_fe` (model: `deploy_artanft_be.yml` / `deploy_artanft_fe.yml`).
- [ ] Repository-ul e **monorepo**: compose-ul BE rulează din `<release>/backend`, cel FE din `<release>/frontend`. Căile din playbook trebuie să țină cont de subfolder (la ArtaNFT, compose-ul era în rădăcina release-ului).
- [ ] Shared ansistrano pentru BE: `.env` → `/var/www/validez-be/shared/backend/.env`; directoare `/var/www/validez-be/shared/backend/public/uploads`, `/var/www/validez-be/shared/backend/var`, `/var/www/validez-be/shared/backend/vendor`, `/var/www/validez-be/shared/backend/config/jwt`; `chown -R 1000:1000` pe ele la deploy (devuser din imaginea PHP).
- [ ] Shared pentru FE: `.env` → `/var/www/validez-fe/shared/frontend/.env`.
- [ ] Playbook-ul BE copiază `backend/nginx/vhost.d.prod` în `/srv/nginx/vhost.d/validez-be.webnou.ro` **înainte** de pornirea containerelor (nginx-proxy îl include doar dacă există la generarea configurației) și face reload cu `nginx -t` când se schimbă — lecția ArtaNFT din 01.10.2026.
- [ ] Ordinea în playbook-ul BE: `composer install --no-dev --optimize-autoloader` ca devuser → `doctrine:migrations:migrate -n` → `cache:clear --env=prod` → abia apoi trafic.

## 4. Pe server, o singură dată (BE)

- [ ] **[PE SERVER]** `/var/www/validez-be/shared/backend/.env` pornind de la `backend/.env.prod.sample`, cu toate cheile (fișierul înlocuiește complet `.env`-ul Symfony):
  - [ ] `COMPOSE_PROJECT_NAME=validez_be` — **obligatoriu**: altfel proiectul compose se numește `backend` și se amestecă cu orice alt proiect dintr-un folder `backend` (problema din 03.10.2026 cu EvoPresence).
  - [ ] `DOCKER_IMAGE=` tag-ul afișat de `backend/docker-build-push.sh`.
  - [ ] `DB_PASSWORD`, `APP_SECRET`, `JWT_PASSPHRASE` noi, nu cele de pe dev.
  - [ ] `SYMFONY_TRUSTED_PROXIES` cu IP-urile/CIDR-urile exacte ale nginx-ului intern și ale Nginx_Proxy (nu `0.0.0.0/0`). **Important pentru login:** `login_throttling` (5 încercări greșite/minut) numără per IP; fără proxy-uri de încredere, toți utilizatorii apar cu IP-ul proxy-ului și se blochează unii pe alții.
  - [ ] `MAILER_DSN` real și `MAILER_FROM_ADDRESS` valid: înregistrarea publică trimite coduri de confirmare pe email (Step 2.2). Implicit, local se folosește Mailcatcher.
  - [ ] Verificare ALTCHA pe browser și Android; `ALTCHA_COST` implicit 1500. Cheile sunt derivate din `APP_SECRET`, fără secrete suplimentare.
- [ ] **[PE SERVER]** Chei JWT generate pe server, nu copiate de pe dev, în `/var/www/validez-be/shared/backend/config/jwt/` (`private.pem`, `public.pem`, cu passphrase-ul din `.env`), proprietar 1000:1000.
- [ ] **[PE SERVER]** Directorul de date PostgreSQL: `/srv/www/validez-be.webnou.ro/db_data` (din `backend/docker-compose.prod.yml`).
- [ ] `ltree`: migrarea rulează `CREATE EXTENSION IF NOT EXISTS ltree`; utilizatorul din imaginea oficială `postgres:16-alpine` e superuser, deci merge. Dacă vreodată baza vine din altă parte, extensia trebuie creată de un superuser.

## 5. Pe server, o singură dată (FE)

- [ ] **[PE SERVER]** `/var/www/validez-fe/shared/frontend/.env` pornind de la `frontend/.env.prod.sample`: `COMPOSE_PROJECT_NAME=validez_fe` (același motiv ca la BE), `VIRTUAL_HOST=validez.webnou.ro`, `WEB_CONTAINER=validez_fe_web`, `DOCKER_IMAGE=` tag-ul afișat de `frontend/docker-build-push.sh`.

## 6. Build și deploy

- [ ] **[PE LAPTOP]** `cd /home/petro/Documents/Docker/www/validez/backend && ./docker-build-push.sh` → tag-ul în `/var/www/validez-be/shared/backend/.env` pe server.
- [ ] **[PE LAPTOP]** `cd /home/petro/Documents/Docker/www/validez/frontend && ./docker-build-push.sh` → tag-ul în `/var/www/validez-fe/shared/frontend/.env` pe server. Adresa API-ului (`https://validez-be.webnou.ro`) e **arsă în bundle la build** — dacă se schimbă domeniul BE, imaginea FE trebuie reconstruită.
- [ ] **[PE LAPTOP]** Deploy cu Ansible (BE întâi, apoi FE).
- [ ] **[PE SERVER]** Verifică versiunea Docker Engine (`docker version`). Serverul are docker-compose 1.29, care pe Engine 25+ crapă cu `KeyError: 'ContainerConfig'` la recrearea unui container (ne-a lovit pe laptop pe 03.10.2026). La ArtaNFT deploy-ul a mers, dar dacă apare: `docker rm -f` pe containerul vizat și `up -d` din nou, sau Compose v2. Fișierele compose rămân pe `version: "3.5"` / `"3"` pentru 1.29.

## 7. După primul deploy

- [ ] **[PE SERVER]** Primul administrator, interactiv (parola se cere ascuns, fără `-T`): `cd /var/www/validez-be/current/backend && docker compose -f docker-compose.prod.yml exec php bin/console app:user:create adresa@exemplu.ro "Nume" --admin` (cu `docker-compose` dacă pe server e doar 1.29).
- [ ] Verificări HTTP pe live:
  - [ ] `GET https://validez-be.webnou.ro/api/tree_nodes` → 200 fără autentificare.
  - [ ] `POST https://validez-be.webnou.ro/api/tree_nodes` fără token → 401.
  - [ ] `https://validez-be.webnou.ro/api/docs` → 404 din proxy (vhost.d), nu din Symfony.
  - [ ] CORS doar pentru `https://validez.webnou.ro` (și `http://localhost` pentru WebView-ul Cordova).
  - [ ] Login din FE cu ALTCHA, creare nod, refresh după 30 de minute fără re-login.
  - [ ] Înregistrare publică: email cu cod, confirmare, login automat, retrimitere după cooldown; un cont neconfirmat nu poate intra.
  - [ ] Mai multe cereri succesive la API: toate corecte, niciun „File not found." (ar indica nginx care ajunge la PHP-ul altui proiect — aliasul `validez-fpm` și rețeaua `validez_be_internal` sunt acolo exact pentru asta).
  - [ ] Login greșit de 6 ori → blocare; de pe alt IP → încă merge (confirmă `SYMFONY_TRUSTED_PROXIES`).

## 8. Mentenanță periodică

- [ ] **[PE SERVER]** Curățarea refresh token-urilor expirate (tabela `refresh_token` crește la fiecare login/refresh, cu rotație): cron zilnic `cd /var/www/validez-be/current/backend && docker compose -f docker-compose.prod.yml exec -T php bin/console gesdinet:jwt:clear`.
- [ ] Backup pentru `/srv/www/validez-be.webnou.ro/db_data` (sau `pg_dump` programat).

## 9. De știut (fără acțiune imediată)

- Migrațiile sunt denumite în **UTC** (ora containerului). Prima a fost redenumită pe 03.10.2026 (`Version20261003204935` → `Version20261003174935`) ca ordinea să fie corectă pe o bază nouă; pe prod nu contează, nu rulase nicăieri acolo.
- Migrarea Step 1.4 se oprește singură dacă `tree_node` are deja noduri fără autor; pe o bază nouă de prod nu e cazul.
- Sesiunea FE (token + refresh token) stă în `localStorage`, ca la ArtaNFT — compromis acceptat pentru Cordova; protecția vine din rotația refresh token-ului și din TTL-ul scurt al token-ului de acces.
- Build-ul Android (smoke test) e programat la Step 1.7 și cere SDK-ul pe laptop.
