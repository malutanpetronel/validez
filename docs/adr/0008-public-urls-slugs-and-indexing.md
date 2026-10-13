# ADR-0008: URL-uri publice cu slug și indexare pe web

**Status:** Accepted
**Date:** 2026-10-13

## Context

Frontend-ul este o singură aplicație React, construită o dată și folosită în două moduri:

- **mobil** — Cordova, încărcată din `file://`, cu `createHashRouter` (`frontend/src/router.js`);
- **web** — `validez.webnou.ro`, servit static, API-ul la `validez-be.webnou.ro`.

Pe web, rutele cu hash (`#/subiecte/{id}`, `#/arbore`) înseamnă pentru Google **o singură pagină**: fragmentul `#…` nu ajunge la server, iar `index.html` are același `<title>` și același conținut gol pentru orice URL. Google execută JavaScript, dar întârziat și negarantat; rezultatul tipic sunt pagini neindexate sau marcate duplicate. Platforma trăiește din subiecte și categorii care trebuie găsite din căutare.

## Decision

1. **Router dependent de platformă, aceleași rute.**
   `window.cordova` → `createHashRouter`; altfel → `createBrowserRouter`. Definiția rutelor rămâne comună.

2. **URL-uri publice cu slug:**
   - categorii, ierarhic: `/categorie/drumuri/cluj/calitate` — fiecare segment este slug-ul unui nod, în ordinea din arbore;
   - subiecte, sub categoria lor: `/categorie/drumuri/cluj/calitate/subiect/{slug}` — subiectul se identifică **doar după slug** (unic global); calea categoriei din față se verifică, iar dacă nu e cea curentă (subiect mutat, categorie redenumită/mutată) răspunsul este **301** spre calea corectă. Ierarhia apare și în breadcrumbs și în JSON-LD `BreadcrumbList`.
   Rutele cu id (`/subiecte/{id}`) rămân funcționale intern și pe mobil, dar nu sunt canonice.

3. **Slug-urile se generează și se persistă în backend**, nu în router:
   - `TreeNode.slug` unic **între frații aceluiași părinte**; `CivicSubject.slug` unic global;
   - `subiect` este **slug rezervat** pentru categorii (separă calea categoriei de slug-ul subiectului); o categorie cu acest nume primește `subiect-2`;
   - generare cu `Symfony\Component\String\Slugger\AsciiSlugger` (diacriticele românești: „Școală” → `scoala`), lungime limitată, sufix numeric la coliziune (`-2`, `-3`);
   - slug-ul se fixează la creare; la redenumire/schimbarea titlului se generează unul nou **doar** dacă entitatea este publicată, iar cel vechi intră în istoric;
   - tabel de istoric `slug_redirect` (tip entitate, slug/cale veche, id entitate) → **301** spre URL-ul curent; la fel pentru mutarea unei categorii (calea veche a întregului subarbore redirecționează). Pentru subiecte istoricul ține doar slug-ul propriu (schimbarea titlului); mutarea între categorii nu are nevoie de istoric, calea fiind verificată la fiecare cerere.

4. **Shell SEO servit de Symfony** (varianta A) pentru rutele publice indexabile:
   - Nginx pe `validez.webnou.ro` trimite `/categorie/*` (inclusiv `…/subiect/{slug}`) și `/sitemap.xml` către backend; restul rămâne static, cu `try_files $uri /index.html`;
   - Symfony rezolvă slug-ul/calea, verifică vizibilitatea și randează `index.html` din build-ul FE (template Twig) cu: `<title>`, `meta description`, `link rel="canonical"`, Open Graph, JSON-LD (`BreadcrumbList`; pentru subiect tipul potrivit, ex. `Article`/`DiscussionForumPosting`) și un rezumat text (titlu, descriere, breadcrumbs, linkuri spre subcategorii/subiecte) în `<div id="root">`;
   - `index.html` nu se modifică pe disc — este citit ca șablon (păstrează tag-urile spre asset-urile cu hash ale build-ului curent), iar meta și rezumatul se injectează în memorie la fiecare cerere;
   - șablonul ajunge la BE prin deploy-ul FE: după pornirea containerului nou, ansible copiază `index.html` din `validez_fe_web:/usr/share/nginx/html/index.html` în `/var/www/validez-fe/shared/seo/index.html`; BE montează `/var/www/validez-fe/shared/seo` **read-only**. Copierea la deploy (nu la build) ține șablonul sincronizat cu asset-urile servite efectiv;
   - React pornește peste acest conținut și preia controlul (`createRoot`, nu hidratare SSR);
   - slug inexistent sau subiect `HIDDEN`/`PENDING` → **404 real** cu `noindex`; slug istoric → **301**.

5. **Sitemap generat de backend:** `/sitemap.xml` (index + fișiere paginate) cu toate categoriile și subiectele publicate, cu `lastmod`; `robots.txt` cu link spre sitemap.

6. **Ce nu se indexează:** autentificare, înregistrare, `#/voturile-mele`, formulare de creare/editare, paginile de administrare, propunerile de categorii — `noindex` (prin meta din React și/sau `X-Robots-Tag`).

7. **Linkuri reale:** navigarea între categorii și subiecte folosește `<Link>`/`<a href>` (nu doar `onClick` + `navigate()`), ca robotul să descopere paginile. Linkurile partajate din aplicația mobilă indică URL-ul web canonic.

## Rationale

- Un singur build FE, fără Node în producție: Cordova nu trece niciodată prin Symfony, deci aplicația mobilă rămâne neatinsă.
- Fiecare categorie și subiect devine o pagină reală, cu titlu și conținut proprii, încă din primul răspuns HTTP, fără să depindem de randarea JavaScript de către Google.
- Ierarhia în URL-ul categoriei reflectă arborele (ADR-0001) și e lizibilă; subiectele rămân plate pentru stabilitate la mutări.
- Backend-ul deține deja regulile de vizibilitate (ADR-0002, ADR-0006); 404-urile și `noindex` sunt corecte prin construcție.

## Consequences

### Positive
- Indexare per categorie și per subiect, previzualizări corecte la partajare (Facebook @valideaza, mesagerie).
- URL-uri stabile, cu redirect la redenumire și mutare.

### Negative / Trade-offs
- Backend-ul depinde de `index.html`-ul build-ului FE curent, copiat automat de playbook-ul FE; un BE pornit înaintea primului deploy FE cu acest pas nu are șablon (răspunde cu shell-ul minim/fallback).
- Rezumatul din shell dublează o parte din randarea React (Twig vs componente) — se ține minimal.
- `BrowserRouter` pe web cere fallback în Nginx pentru toate rutele SPA; linkurile vechi cu `#/…` trebuie redirecționate client-side spre ruta nouă.
- Coloane și migrare noi (`slug`, `slug_redirect`), plus completarea slug-urilor pentru datele existente.

## Alternatives considered

- **Prerender** (Puppeteer/serviciu extern pentru boți) — încă o piesă de rulat și ținut sincronizată; risc de cloaking dacă diferă de ce vede utilizatorul.
- **SSR complet** (Vite SSR / Next.js) — cel mai bun SEO, dar Node în producție și dublarea infrastructurii; incompatibil cu buildul unic folosit și de Cordova fără efort mare.
- **Subiecte cu URL plat** (`/subiect/{slug}`) — stabil la mutări, dar fără contextul categoriei în URL; respins: stabilitatea se obține și cu calea verificată + 301.
- **Copierea `index.html` din `docker-build-push.sh`** (pe laptop, la build) — șablonul ar ajunge pe server înaintea imaginii FE, cu asset-uri încă inexistente, și scriptul ar avea nevoie de SSH; respins în favoarea pasului din playbook.
- **BE cere `index.html` prin HTTP de la containerul FE** — fără fișier partajat, dar dependență la rulare între containere; respins.
- **Nginx SSI** (fragment generat de BE inclus în `index.html`) — BE nu mai atinge fișierul, dar nu mai pot exista 404/301 reale; respins.
- **Doar `BrowserRouter`, fără shell** — dependent integral de randarea JS a Google; titluri/meta identice; respins.

## Security / Transparency implications

- Shell-ul nu expune nimic din ce API-ul public nu expune deja: aceleași reguli de vizibilitate; `HIDDEN`/`PENDING` → 404.
- Rezultatele voturilor pot apărea în rezumat doar ca agregate publice (ADR-0007); niciun identificator de utilizator în HTML-ul indexabil.
- Conținutul injectat în Twig se escapează (titluri/descrieri scrise de utilizatori); JSON-LD serializat cu `json_encode` și flagurile HTML-safe.

## Plan de implementare

- [x] **S.1 — backend, slug-uri** (13.10.2026; detalii în `implementation-plan.md`, „Step S”): coloane `slug` pe `tree_node` (unic per părinte prin `slug_path` unic, `subiect` rezervat) și `civic_subject` (unic), tabel `slug_redirect`, serviciu de generare cu `AsciiSlugger`; migrare care completează slug-urile existente; regenerare la redenumire/schimbarea titlului cu înscriere în istoric; redirect pentru tot subarborele la mutarea unei categorii. API: `slug` și calea canonică în răspunsuri, `GET /api/tree_nodes/by-path?path=a/b/c`, `GET /api/civic_subjects/by-slug/{slug}` (cu 301 logic pentru slug istoric).
- [x] **S.2 — frontend, router** (13.10.2026; detalii în `implementation-plan.md`, „Step S”): `createBrowserRouter` pe web, `createHashRouter` pe Cordova; rute `/categorie/*` (categorie sau, după segmentul `subiect`, subiect); redirect client-side din vechile `#/subiecte/{id}`, `#/arbore`; dacă calea din URL nu e cea canonică, `replace` spre cea canonică; toate linkurile de navigare ca `<Link>`; `<title>`/meta actualizate la navigare. Titluri: „{subiect} — VALIDEZ”, „{categorie} · {părinte} — VALIDEZ”, pagina principală „VALIDEZ — Vezi. Verifică. Validează.”.
- [ ] **S.3 — backend, shell SEO:** controller pentru `/categorie/{path}` și `/categorie/{path}/subiect/{slug}` care citește `index.html` din `/var/www/validez-fe/shared/seo` (mount read-only) și îl randează cu meta, canonical, OG, JSON-LD și rezumat; 404 cu `noindex`; 301 din istoric sau la cale de categorie greșită.
- [ ] **S.4 — sitemap și robots:** `/sitemap.xml` paginat (categorii + subiecte publicate, `lastmod`), `robots.txt`.
- [ ] **S.5 — infrastructură și deploy:** Nginx pe `validez.webnou.ro` (`/categorie/*` și sitemap → backend, rest static cu fallback SPA); în `ansible_vps/petro-vps/config/ansistrano_after_symlink_tasks_validez_fe.yml`, după START CONTAINER, task „### copiem index.html - SEO” (`docker cp validez_fe_web:/usr/share/nginx/html/index.html /var/www/validez-fe/shared/seo/index.html`); mount read-only `/var/www/validez-fe/shared/seo` în containerul PHP al BE; ordinea și notele în `Deploy.md`, marcate [PE LAPTOP] / [PE SERVER].
- [ ] **S.6 — verificare:** `curl` pe câteva URL-uri (titlu/canonical/JSON-LD corecte, 404 pe subiect ascuns, 301 pe slug vechi), Rich Results Test și inspectarea URL-ului în Google Search Console, build Cordova (`cordova run android`) — navigarea pe mobil neschimbată.

## References

- ADR-0001 (ierarhie, ltree), ADR-0002 (Node vs CivicSubject), ADR-0006 (moderare, `PENDING`), ADR-0007 (date private vs agregate publice)
- `frontend/src/router.js`
- Google Search Central — JavaScript SEO basics; sitemaps
