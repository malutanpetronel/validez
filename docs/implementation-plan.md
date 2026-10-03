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
