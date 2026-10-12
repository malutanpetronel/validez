# Roluri PostgreSQL pentru Validez

Scriptul [configure-roles.sql](../backend/docker/postgres/admin/configure-roles.sql) se rulează administrativ pe baza Validez, după migrări și înainte de deschiderea traficului. Nu este un script `initdb`: configurarea trebuie aplicată și volumelor existente. Nu schimbă parole și nu modifică alte baze; rolurile cu prefixul `validez_` sunt dedicate proiectului.

| Rol | Scop | Acces |
|---|---|---|
| `validez_owner` | Proprietatea schemei și obiectelor aplicației | `NOLOGIN`; fără superuser |
| `validez_migrator` | Migrări în timpul deployului | Login separat, membru al ownerului; nu este folosit de runtime |
| `validez_app` | Conexiunile PHP care servesc cereri | DML pe tabelele operaționale; numai SELECT/INSERT pe jurnal; SELECT pe evidența migrărilor; fără CREATE, TRUNCATE sau administrare |

Rolul existent de bootstrap rămâne administrativ. Backupul și administrarea folosesc conexiuni separate de runtime; scriptul nu acordă aplicației acces la credențialele lor. Membrii extensiei `ltree` nu își schimbă proprietarul. Funcțiile aplicației nu primesc EXECUTE implicit pentru runtime; dacă o funcționalitate nouă cere apel SQL direct, permisiunea se acordă explicit după evaluare.

Conexiunile `validez_migrator` lucrează ca `validez_owner` de la conectare (`ALTER ROLE … IN DATABASE … SET role`), deci tabelele create de migrări au direct proprietarul corect. Setarea se aplică la autentificare, nu la `SET SESSION AUTHORIZATION`.

Scriptul revocă `TEMPORARY` de la `PUBLIC` numai pe baza pe care rulează; superuserii (bootstrapul, `pg_dump`) nu sunt afectați. Nu sunt cunoscute operații administrative cu tabele temporare făcute de un rol non-superuser; configurația efectivă de backup de pe server nu a fost verificată și trebuie confirmată la aplicare. **Scriptul refuză `validez_test`**: baza de test e curățată cu `TRUNCATE … CASCADE` de utilizatorul de bootstrap și nu primește aceste roluri.

## Pregătire și aplicare

1. Păstrează `VOTING_ENABLED=false`. Înregistrează baza țintă și verifică un backup recuperabil înainte de schimbarea accesului.
2. La prima tranziție, migrările rulează o singură dată cu accesul administrativ (deploy cu `-e validez_db_bootstrap=true`, secțiunea „Deploy” de mai jos). La deployurile următoare folosesc `validez_migrator`.
3. Scriptul rulează automat în deploy, după migrări, prin `docker exec` pe containerul DB, ca `POSTGRES_USER` pe socketul local (fără parolă în comandă). Manual, pe server: `docker exec -i validez_be_db sh -c 'psql -X -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < /var/www/validez-be/current/backend/docker/postgres/admin/configure-roles.sql`

   Scriptul este tranzacțional și reluabil. Eșuează dacă migrarea voturilor lipsește sau dacă rolul aplicației are membri/privilegii administrative neașteptate.
4. Setează parole distincte prin sesiunea interactivă `psql`, fără a le pune în argumentele shell, SQL versionat sau loguri:

   ```text
   \password validez_app
   \password validez_migrator
   ```

5. Configurarea deploymentului e separată de cea montată în PHP (tabelul din „Deploy”): parola administrativă și cea a migratorului nu ajung în containerul care servește cereri; migrarea rulează într-un container temporar.
6. Runtime folosește numai `validez_app`, cu parola codificată corespunzător în URL (parolele hex generate cu `openssl rand -hex` nu cer codificare). `POSTGRES_USER` al containerului DB rămâne utilizatorul de bootstrap: nu se înlocuiește cu `validez_app` și nu se reinicializează volumul.
7. Scriptul se reaplică **după fiecare migrare**, înainte de trafic (automat în deploy). Permisiunile noilor tabele nu sunt acordate automat, pentru a evita acordarea accidentală de UPDATE/DELETE unui jurnal recreat. Verificarea de la finalul scriptului eșuează dacă vreo tabelă operațională (în afară de jurnal și de evidența migrărilor) nu are DML pentru runtime sau dacă runtime deține vreun obiect.
8. Deployul verifică apoi, prin aplicație, rolul efectiv și permisiunile conexiunii runtime (`app:db:runtime-check`) și `app:votes:verify`. Simularea locală nu înlocuiește verificarea conexiunii reale pe producție.

Nu activa votarea doar după configurarea rolurilor: condițiile privind retenția, backupurile și privacy din ADR-0007 rămân distincte.

## Verificare locală

Din `backend/`:

```bash
bash bin/test-postgresql-roles.sh
```

Setarea migratorului se aplică doar la conectare, deci testul lucrează cu roluri comise, pe o bază temporară (`validez_roles_test`) cu schema copiată din baza de dev, și se conectează separat ca fiecare rol (socket local, fără parole). Pași:

- refuzul pe `validez_test`, rulat cu `ROLLBACK` în loc de `COMMIT`, fără roluri create;
- scriptul aplicat de două ori (reluabil);
- ca `validez_app`: SELECT/INSERT pe jurnal și lockuri de rând permise; UPDATE/DELETE/TRUNCATE pe jurnal, DISABLE/DROP TRIGGER, CREATE, tabele temporare, scrierea în evidența migrărilor și `SET ROLE validez_owner` respinse;
- ca `validez_migrator`, fără `SET ROLE` explicit: rolul efectiv e `validez_owner`, iar tabela nouă îi aparține;
- tabela nouă: verificarea scriptului o semnalează și runtime nu o poate citi; după reaplicare, runtime are SELECT/INSERT/UPDATE/DELETE pe ea.

**O singură instanță pe cluster**: rolurile au nume comune, iar numele fix al bazei temporare e mutexul. Testul refuză să pornească dacă baza temporară sau rolurile `validez_*` există deja și, la ieșire (inclusiv la eșec), șterge doar baza și rolurile create de rularea lui. Nu se rulează pe un cluster care are deja rolurile configurate. Necesită migrarea voturilor pe baza de dev și un administrator pe stackul local; nu folosește parole de producție.

## Deploy (Compose și Ansible)

| Fișier pe server | Conține | Cine îl folosește |
|---|---|---|
| `/var/www/validez-be/shared/backend/.env` (root:1000, 0640) | doar runtime: `APP_SECRET`, JWT, mailer, `VOTING_ENABLED=false`… și `DATABASE_URL` cu `validez_app` | montat în PHP (UID 1000) și în containerul de migrare |
| `/etc/validez-be/compose.env` (root, 0600) | `COMPOSE_PROJECT_NAME`, `DOCKER_IMAGE`, containerele, `DB_NAME`/`DB_USER`/`DB_PASSWORD` de bootstrap, `MIGRATOR_ENV_FILE`, `RUNTIME_ENV_FILE` | docker-compose (`--env-file`); nu e montat în containere |
| `/etc/validez-be/migrator.env` (root, 0600) | `DATABASE_URL` cu `validez_migrator` | doar `docker-compose run --rm migrate` |
| `/etc/validez-be/bootstrap-admin.env` (root, 0600) | `DATABASE_URL` administrativ | **doar** la prima tranziție; se șterge apoi |

Modelele sunt în `backend/deploy/*.sample` și `backend/.env.prod.sample`. Toate comenzile docker-compose pentru BE primesc `--env-file /etc/validez-be/compose.env`. După editarea `.env`-ului runtime, containerul PHP se recreează (deployul o face oricum): un fișier montat individual rămâne legat de inode-ul vechi dacă editorul îl înlocuiește.

Playbook-ul `ansible_vps/petro-vps/deploy_validez_be.yml`:

1. **Preflight, înaintea opririi containerelor** (`config/preflight_validez_be.yml`, `no_log` pe citirea fișierelor; afișează doar numele verificărilor eșuate): drepturile fișierelor, `.env`-ul runtime citibil de UID 1000 și fără `DB_*`/`POSTGRES_*`/`MIGRATOR_*` sau parolele de bootstrap/migrator, `DATABASE_URL` runtime cu `validez_app` și cel de migrare cu `validez_migrator`, fără `bootstrap-admin.env` rămas. `VOTING_ENABLED` activ în fișier oprește deployul, cu excepția `-e validez_allow_voting=true` (interpretat boolean; nu modifică fișierul). În modul bootstrap se cer doar `compose.env`, fișierul administrativ temporar și un `.env` runtime fără secretele de deploy.
2. Oprirea containerelor, release nou, `db` + `php`, `composer install`, `cache:clear`.
3. **Poarta de baze de date** (`backend/bin/deploy-db.sh`): migrări în container temporar (`validez_migrator`) → `configure-roles.sql` (administrativ) → în containerul PHP, cu conexiunea Doctrine efectivă: `app:db:runtime-check --expect-user=validez_app` (rol, absența privilegiilor administrative, permisiunile pe **toate** tabelele din `public`), apoi `app:votes:verify`.
4. **Publicarea**: pornirea `nginx`, doar dacă poarta a trecut. În modul bootstrap playbook-ul se oprește înainte de acest pas.

**La eșec site-ul rămâne oprit** (nginx nu pornește; containerele vechi au fost oprite la pasul 2). Reluare: corectezi cauza afișată (fișier de configurare, parolă, permisiuni) și rulezi **același** playbook din nou. Migrările deja aplicate nu se repetă, iar `configure-roles.sql` și verificările sunt reluabile. Nu porni `nginx` manual peste o poartă eșuată.

Verificarea locală a integrării, fără server:

- **[PE LAPTOP]** în `/home/petro/Documents/Docker/www/validez/backend`: `bash bin/test-deploy-db.sh` — proiect izolat `validez_prodtest`, bază goală, același `docker-compose.prod.yml` și `deploy-db.sh`; bootstrap → parole → deploy normal; nicio parolă de bootstrap/migrator în mediul sau fișierele PHP; tabelă fără GRANT introdusă după configurarea rolurilor (prinsă, apoi remediată); runtime conectat cu alt rol (deploy oprit); `bootstrap-admin.env` uitat (deploy oprit). Curăță la ieșire.
- **[PE LAPTOP]** în `/home/petro/Documents/Docker/ansible_vps`: `ANSIBLE_PLAYBOOK=/home/petro/.venv/ansible216/bin/ansible-playbook bash petro-vps/tests/test_preflight_validez_be.sh` — preflight-ul pe fixturi (bootstrap/normal, drepturi, chei și parole interzise, garda `VOTING_ENABLED`, nicio parolă în ieșirea Ansible) și taskurile de după symlink în `--check`, în ambele moduri.

După tranziție, testează și login, publicare/moderare, mutarea arborelui, votare/retragere și export sub rolul aplicației (votarea doar pe dev; pe producție `VOTING_ENABLED` rămâne `false`).

Referințe: [privilegii PostgreSQL 16](https://www.postgresql.org/docs/16/ddl-priv.html), [default privileges](https://www.postgresql.org/docs/16/sql-alterdefaultprivileges.html). Scriptul transferă explicit numai obiectele aplicației, deoarece `REASSIGN OWNED` poate afecta și obiecte partajate: [documentație](https://www.postgresql.org/docs/16/sql-reassign-owned.html).
