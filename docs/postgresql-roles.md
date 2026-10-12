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
2. Rulează migrarea voturilor cu accesul administrativ actual la prima tranziție. La deployurile următoare, migrările folosesc conexiunea `validez_migrator`.
3. Din `backend/`, cu utilizatorul administrativ înlocuit în comandă:

   ```bash
   docker compose -f docker-compose.prod.yml exec -T db psql -X -v ON_ERROR_STOP=1 -U ADMIN_DB_USER -d validez < docker/postgres/admin/configure-roles.sql
   ```

   Scriptul este tranzacțional și reluabil. Eșuează dacă migrarea voturilor lipsește sau dacă rolul aplicației are membri/privilegii administrative neașteptate.
4. Setează parole distincte prin sesiunea interactivă `psql`, fără a le pune în argumentele shell, SQL versionat sau loguri:

   ```text
   \password validez_app
   \password validez_migrator
   ```

5. Separă configurarea deploymentului de configurarea montată în PHP. **În configurația actuală, `.env` conține `DB_PASSWORD` administrativ și este montat în PHP. Schimbarea numai a `DATABASE_URL` nu izolează secretele.** Parola administrativă și parola migratorului nu trebuie montate sau injectate în containerul care servește cereri; conexiunea de migrare se execută într-un proces/container temporar cu acces controlat.
6. Modifică runtime să folosească numai conexiunea `validez_app`, cu parola codificată corespunzător în URL. `POSTGRES_USER` al containerului DB rămâne utilizatorul de bootstrap: nu se înlocuiește cu `validez_app` și nu se reinicializează volumul.
7. Reaplică scriptul **după fiecare migrare**, înainte de trafic. Permisiunile noilor tabele nu sunt acordate automat, pentru a evita acordarea accidentală de UPDATE/DELETE unui jurnal recreat. Verificarea de la finalul scriptului eșuează dacă vreo tabelă operațională (în afară de jurnal și de evidența migrărilor) nu are DML pentru runtime sau dacă runtime deține vreun obiect.
8. Verifică rolul efectiv al conexiunii runtime, funcțiile aplicației, migrarea cu rolul separat și `app:votes:verify`. Testul local de mai jos nu înlocuiește verificarea conexiunii reale pe producție.

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

## Integrare încă necesară pentru producție

Scriptul SQL și testul sunt pregătite; configurația Compose și playbookul trebuie adaptate împreună pentru separarea secretelor și conexiunilor. Nu înlocui credențialele runtime înainte să existe o cale de migrare separată. După tranziție, testează și login, publicare/moderare, mutarea arborelui, votare/retragere și export sub rolul aplicației.

Ordinea din playbook, înainte de comutarea symlinkului release-ului; **orice eșec oprește publicarea release-ului**:

1. migrările, într-un proces/container temporar autentificat ca `validez_migrator`;
2. `configure-roles.sql`, cu conexiunea administrativă (realiniază proprietatea, acordă DML tabelelor noi și verifică politica);
3. `app:votes:verify`, cu conexiunea runtime (`validez_app`): confirmă și că runtime poate citi toate tabelele de care are nevoie;
4. abia apoi comutarea symlinkului și restartul PHP.

Referințe: [privilegii PostgreSQL 16](https://www.postgresql.org/docs/16/ddl-priv.html), [default privileges](https://www.postgresql.org/docs/16/sql-alterdefaultprivileges.html). Scriptul transferă explicit numai obiectele aplicației, deoarece `REASSIGN OWNED` poate afecta și obiecte partajate: [documentație](https://www.postgresql.org/docs/16/sql-reassign-owned.html).
