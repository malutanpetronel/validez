# ADR-0011: Media, linkuri și localizare pe subiecte

**Status:** Accepted
**Date:** 2026-10-15

## Context

Step 3 adaugă la subiecte fotografii, linkuri și localizare. [Sesizarea către autorități](../authority-notifications.md) (Step 4.1) le folosește ca dovezi, iar [ADR-0007](0007-votes-revisions-and-privacy.md) leagă votul de revizia subiectului: trebuie stabilit dacă media schimbă sensul votului.

Riscuri specifice:

- fotografiile pot conține metadate EXIF, inclusiv coordonatele locului unde au fost făcute (de exemplu locuința autorului);
- fotografiile pot conține date ale altor persoane (fețe, numere de înmatriculare);
- un subiect `PENDING` sau `HIDDEN` nu trebuie să-și expună media prin URL-uri statice;
- harta introduce un terț nou (serverul de tile-uri vede IP-ul vizitatorului);
- fișierele nu sunt în `pg_dump`, deci nu intră în copiile de siguranță fără o decizie explicită.

În `backend/docker-compose.prod.yml` există deja un montaj `UPLOADS_DIR` sub `public/uploads` (servit static de nginx); această decizie îl înlocuiește.

## Decizie

1. **Media sunt opționale.** Un subiect poate avea zero fotografii; o propunere clară nu are nevoie de poze. Pragurile din Step 4.1 pot cere fotografii doar dacă sunt configurate explicit pentru o regulă.
2. **Media nu creează revizie nouă.** Adăugarea sau scoaterea unei fotografii sau a unui link nu schimbă revizia subiectului și nu cere reconfirmarea voturilor. Sunt dovezi care se adaugă. Istoricul public al subiectului arată ce s-a adăugat sau scos și când, ca o scoatere să nu treacă neobservată.
3. **Stocare pe discul VPS-ului**, în `/srv/www/validez-be.webnou.ro/uploads`, în afara `public/` și a arborelui de deploy. Directorul intră în backupul zilnic, cu aceeași rotație de 30 de zile ca baza.
4. **Servire prin Symfony.** Fiecare cerere verifică vizibilitatea subiectului (aceleași reguli ca detaliul subiectului: `PENDING`/`HIDDEN` doar pentru autor și administratori), apoi răspunde cu `X-Accel-Redirect` către o locație nginx `internal`. Nu există URL static public pentru media.
5. **Imagini:** JPEG, PNG, WebP și HEIC; cel mult 10 MB per fișier și 10 fotografii per subiect. Tipul se verifică după conținut, nu după extensie. Serverul re-encodează fiecare imagine (o variantă de afișare și o miniatură); originalul încărcat nu se păstrează.
6. **Metadatele se elimină** complet la re-encodare. Coordonatele din EXIF nu se folosesc pentru localizare.
7. **Video doar ca link** (de exemplu YouTube). Upload-ul de video și PDF-ul sunt amânate.
8. **Linkuri:** doar `http`/`https`, cel mult 10 per subiect, afișate cu `rel="nofollow ugc noopener"`. Serverul nu descarcă paginile pentru preview (fără risc de SSRF).
9. **Localizare opțională:** un punct pus explicit pe hartă, cu sursa lui (pin manual sau locația dispozitivului, cu permisiunea utilizatorului) și un câmp „confirmată”, folosit de pragurile din Step 4.1. Harta: Leaflet cu tile-uri OpenStreetMap, menționate în pagina de confidențialitate și în dosarul pentru specialist.
10. **Persoane în fotografii:** avertisment la upload („nu include fețe sau numere de înmatriculare care nu sunt necesare”). Autorul și administratorii pot scoate o fotografie; fișierul se șterge de pe disc, iar din copii dispare după cel mult 30 de zile. Estomparea automată rămâne pentru mai târziu.
11. **Localizarea face parte din revizie.** Punerea, mutarea sau scoaterea pinului creează revizie nouă, ca titlul și descrierea: la o sesizare, locul este subiectul ([ADR-0007](0007-votes-revisions-and-privacy.md), fără corecturi „minore”). Votantul vede vechiul și noul punct pe hartă când reconfirmă. Excepție: bifa „confirmată” nu creează revizie, pentru că nu schimbă locul.
12. **Autori sub moderare ([ADR-0006](0006-initial-contribution-moderation.md)):** fotografiile și linkurile adăugate de ei intră individual ca `PENDING` (vizibile doar autorului și administratorilor) și apar în `#/moderare`; subiectul și voturile lui rămân publice. Autorii cu drept de publicare directă publică media imediat; administratorii le pot scoate oricând. Mutarea pinului de către un autor sub moderare este editare de revizie (punctul 11), deci retrimite subiectul la aprobare, conform ADR-0006.

## Consecințe

- Montajul `UPLOADS_DIR` de sub `public/uploads` se înlocuiește cu cel din punctul 3, iar nginx BE primește o locație `internal`; nota despre `/uploads/` din `robots.txt` (Step S.4) nu mai e necesară.
- `/usr/local/bin/validez-backup.sh` arhivează și directorul de media; restaurarea din `Deploy.md` E2/E3 îl include.
- Pagina de confidențialitate și dosarul pentru specialist primesc: fotografii (eliminarea metadatelor, persoane terțe, scoatere), localizare, OpenStreetMap ca terț.
- Spațiu pe disc: după re-encodare, o fotografie ocupă de regulă sub 1 MB; limita de 10 MB se aplică doar la upload.
- Build-ul Android se verifică cu camera, galeria și locația (permisiuni, refuzul lor).

## Alternative respinse

- **Minimum 3 fotografii per subiect:** ar forța poze fără valoare pe propuneri clare.
- **Prag de distanță pentru pin (de exemplu sub 30 m fără revizie):** obiectiv, dar o regulă specială de explicat în UI și în informare; fără nevoie reală deocamdată.
- **Retrimiterea întregului subiect la aprobare pentru o fotografie a unui autor sub moderare:** ar ascunde un subiect public, cu voturi, deși media nu schimbă revizia.
- **Media ca parte a reviziei:** fiecare poză adăugată ar cere reconfirmarea tuturor voturilor, deși sensul subiectului nu se schimbă.
- **Fișiere statice sub `public/`:** ar expune media subiectelor nepublicate oricui are linkul.
- **Object storage extern:** un terț în plus pentru date personale, nejustificat la volumul actual.
- **Upload de video:** consum mare de disc și de backup; linkul acoperă nevoia.
