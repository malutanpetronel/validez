# Plan de implementare

## Baza tehnică

Manifestele inițiale sunt replicate din proiectele locale:

- `backend/composer.json`: `artaNftBE/symfony/composer.json` — PHP >=8.2, Symfony 7.4, API Platform 4.2, Doctrine ORM 3.6, JWT și refresh tokens.
- `frontend/package.json`: `artaNftFE/package.json` — React 18, React Admin 5, MUI 7, react-scripts și Cordova pentru browser/Android.
- Arborele pornește de la abordarea `time-track-admin/src/EchipeTree.js`, cu `rc-tree` ^5.13.1. Reutilizăm interacțiunea; categoriile Validez nu sunt echipe și nu preluăm relațiile cu colaboratorii.

Numele, descrierea și licența sunt adaptate la Validez. Scriptul frontend de test pornește runner-ul existent. Restul dependențelor și intervalelor de versiuni Artanft sunt păstrate pentru paritate, inclusiv modulele calendar/PDF/QR; necesitatea lor poate fi reevaluată ulterior.

Acestea sunt manifeste inițiale, nu aplicații executabile. Nu au fost instalate dependențele sau generate lockfile-uri. Instalarea trebuie să verifice rezolvarea versiunilor, peer dependencies și build-ul; nu copiem lockfile-uri din proiecte cu manifeste diferite. CLI-ul Cordova, SDK-ul Android și configurația Cordova trebuie pregătite separat. Scripturile Composer presupun scheletul Symfony, iar scripturile frontend presupun sursele React și directorul `www`.

## Step 1 — arbore funcțional de la interfață până la baza de date

1. Inițializare Symfony/API Platform și React/Cordova, configurație de mediu, CORS, PostgreSQL cu extensia `ltree` și conectare frontend–API. Configurare identitate Cordova și verificare pornire în browser.
2. Entitatea și migrarea `TreeNode`: ULID, `parent_id` nullable, `path`, `name`, `type`, `position`, autor și date de creare/modificare. Respectăm ADR-0001 și separarea de `CivicSubject` din ADR-0002. Segmentele `ltree` folosesc identificatori stabili.
3. API pentru citirea rădăcinilor/copii, creare și redenumire. Operație explicită pentru mutare și ordonare: schimbarea părintelui, actualizarea tuturor căilor din subarbore și a pozițiilor afectate se execută într-o tranzacție. Backend-ul respinge părintele inexistent, mutarea în sine sau într-un descendent și modificările neautorizate. Strategia de blocare trebuie să protejeze și mutările concurente împotriva ciclurilor.
4. Autentificare minimă și permisiuni: navigare publică; modificarea structurii rezervată administratorilor. Identitatea autorului vine din autentificare, nu din payload.
5. Ecran arbore cu `rc-tree`: creare rădăcină/copil, redenumire, expandare, drag and drop pentru schimbarea părintelui și ordonare între frați. Adapterul relațiilor API păstrează ULID ca string; parserul numeric din Timetrack nu se copiază. Încărcare pe ramuri pentru a nu cere întregul arbore la fiecare navigare.
6. Pe mobil și pentru tastatură: acțiune „Mută în…” cu selectarea părintelui și controale de ordonare. La eșecul salvării, interfața restabilește structura confirmată de server și afișează eroarea.
7. Verificări: persistență după refresh, ordonare, mutare cu descendenți, respingerea ciclurilor inclusiv sub concurență și controlul accesului. Verificare UI în browser și smoke test al build-ului Android când mediul SDK este disponibil.

Criteriu de încheiere: un administrator creează `Drumuri > Cluj > Calitate > DN1`, redenumește și mută o ramură, ordonează frații, iar structura rămâne corectă după refresh. Un vizitator poate naviga arborele, fără drept de modificare. Ștergerea cu descendenți/conținut se definește separat, după stabilirea regulilor de arhivare.

## Pașii următori

- **Step 2:** `CivicSubject` asociat nodurilor, listă și detaliu, creare/editare și reguli de acces.
- **Step 3:** media, linkuri și localizare; verificare pe dispozitiv a funcționalităților Cordova necesare.
- **Step 4:** tipuri de vot configurabile, vot unic per utilizator/subiect/tip, agregări și evenimente de integritate conform modelului existent.
- **Step 5:** comentarii, raportări și moderare.
- **Step 6:** prioritate calculată, trust score și măsuri anti-abuz; stabilizare și distribuire mobilă.

Build-ul mobil este verificat încă din Step 1 și la integrarea funcționalităților native, pentru a descoperi devreme problemele de navigare, autentificare și acces la API din WebView.

## Stare

- [x] **1.1** (03.10.2026) — Symfony 7.4 + API Platform 4.2 (`/api` 200), PostgreSQL 16 cu `ltree`, CORS pentru FE (3002, LAN, `http://localhost` Cordova), React/MUI cu hash router, `config.xml` `ro.webnou.validez`, `cordova build browser` OK; Docker dev/prod pentru ambele. Lăsate pentru pașii lor: înregistrarea `gesdinet/jwt-refresh-token-bundle` (1.4), smoke test Android (1.7).
- [x] **1.2** (03.10.2026) — `TreeNode` (ULID generat în constructor, `path` ltree din ULID-uri base32, `name`, `position`, `timestamptz`) + migrare: GiST pe `path`, `UNIQUE NULLS NOT DISTINCT (parent_id, position) DEFERRABLE INITIALLY DEFERRED`, `CHECK position >= 0`, `CHECK parent_id <> id`, FK `RESTRICT`. Verificat: `migrations:diff` fără modificări, `schema:validate` OK, constrângerile testate direct în PostgreSQL, 5 teste unitare. Amânate explicit: `created_by` (odată cu modelul `User`), `type` (semantică nedecisă).
- [x] **1.3** (03.10.2026) — `GET /api/tree_nodes` (rădăcini) și `?parent={ulid}` (un nivel, cu `hasChildren` dintr-o singură interogare), `POST` (poziție la capătul fraților sub `pg_advisory_xact_lock` — 6 creări concurente → poziții 0..5), `PATCH` redenumire; scrierea prin voter `TREE_EDIT`: **temporar permisă doar în `APP_ENV=dev`**, altfel `ROLE_ADMIN` (ramura dev se scoate la 1.4); firewall `main` stateless. Mutare și ordonare: `POST /api/tree_nodes/{id}/move` `{parent, position?}` (`App\Tree\TreeMover`) — o tranzacție sub același `TreeLock`, stare citită după lock, respinge mutarea în sine/descendent (422), părinte inexistent (422), nod inexistent (404); rescrie `parent_id` și `path` pe tot subarborele, renumerotează dens frații la sursă și destinație. Verificat: 8 teste de integrare pe `validez_test` (17/17 total), 10 runde de mutări încrucișate simultane → mereu 200/422, fără ciclu, căi și poziții consistente.
- [x] **1.4** (03.10.2026) — `User` minim (ULID, email normalizat unic, `displayName`, `roles`, parolă hash; tabela `app_user`), login `POST /api/auth` (json_login + lexik JWT 30 min, `login_throttling` 5/min), refresh `POST /api/token/refresh` (gesdinet, 7 zile, sliding, rotație `single_use`), firewall `api` stateless. Navigare publică; `TREE_EDIT` = `ROLE_ADMIN` (ramura temporară „dev” eliminată). `TreeNode.created_by` NOT NULL din utilizatorul autentificat, niciodată din payload (datele de dev șterse înainte de migrare; migrarea se oprește dacă găsește noduri). Primul admin: `bin/console app:user:create <email> <nume> --admin`. FE: `#/login`, Intră/Ieși, acțiuni de modificare doar pentru admin, `apiFetch` cu un singur refresh partajat și reîncercare fără token pentru citiri publice. Verificat: 29 teste BE (inclusiv HTTP: vizitator 401, utilizator 403, admin 2xx, autor din token, rotația refresh), 17 teste FE; migrațiile up/down/up pe o bază nouă. Prima migrare redenumită în UTC (`Version20261003174935`), ca ordinea să coincidă cu cele generate în container.
- [x] **1.5** (03.10.2026) — ecran `#/arbore` cu `rc-tree`: creare rădăcină/copil, redenumire (buton sau dublu-click), expandare cu încărcare pe ramuri, ULID păstrat ca string; drag and drop pentru schimbarea părintelui și ordonare între frați (doar admin), peste `POST /api/tree_nodes/{id}/move`. Ținta se calculează fără nodul tras (semantica API): pe nod → primul copil; între rânduri → înainte/după; sub un nod expandat → primul copil; mutările fără efect nu ajung la server. După răspuns (succes sau eroare) se reîncarcă de pe server nivelurile sursă și destinație, cu ramurile încărcate ale subarborelui mutat păstrate. Verificat: 29 teste FE (logica țintei și a reîncărcării + `onDrop` → API cu rc-tree simulat). Rămâne pentru 1.6: „Mută în…” (drag and drop HTML5 nu funcționează la atingere) și controale de ordonare de la tastatură.
