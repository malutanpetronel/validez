#!/bin/bash
# Test local pentru configure-roles.sql, pe stackul de dev. Rulare, din backend/: bash bin/test-postgresql-roles.sh
#
# Setarea migratorului (ALTER ROLE ... IN DATABASE ... SET role) se aplica doar la conectare, deci rolurile
# trebuie sa existe comise: testul lucreaza pe o baza temporara cu schema copiata din baza de dev si se
# conecteaza separat ca fiecare rol (socket local, autentificare trust in containerul oficial).
#
# O singura instanta pe cluster: rolurile au nume comune; numele fix al bazei temporare e mutexul
# (CREATE DATABASE e atomic). Curatenia elimina doar ce a creat aceasta rulare: baza temporara si rolurile,
# numai daca nu existau inainte (altfel testul refuza sa porneasca).
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."

ADMIN=${ADMIN_DB_USER:-validez}
SOURCE_DB=${SOURCE_DB:-validez}
TEST_DB=validez_roles_test
SQL=docker/postgres/admin/configure-roles.sql
ROLES="'validez_owner','validez_migrator','validez_app'"

psql_as(){ local user=$1 db=$2; shift 2; docker compose exec -T db psql -X -v ON_ERROR_STOP=1 -qtA -U "$user" -d "$db" "$@"; }
admin(){ psql_as "$ADMIN" postgres "$@"; }
pass(){ printf '  OK   %s\n' "$1"; }
die(){ printf '  FAIL %s\n' "$1" >&2; exit 1; }
# expect_failure "descriere" "mesaj așteptat" comandă...: comanda trebuie să eșueze cu mesajul dat.
expect_failure(){
    local label=$1 message=$2 out; shift 2
    if out=$("$@" 2>&1); then die "$label: a reușit, trebuia să eșueze"; fi
    grep -qF "$message" <<<"$out" || die "$label: alt mesaj de eroare: $out"
    pass "$label"
}

created_db=0; created_roles=0
cleanup(){
    if [ $created_db = 1 ]; then
        admin -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$TEST_DB' AND pid <> pg_backend_pid()" >/dev/null || true
        admin -c "DROP DATABASE IF EXISTS $TEST_DB" >/dev/null || echo "  ATENȚIE: șterge manual baza $TEST_DB" >&2
    fi
    if [ $created_roles = 1 ]; then
        admin -c "DROP ROLE IF EXISTS validez_app, validez_migrator, validez_owner" >/dev/null || echo "  ATENȚIE: șterge manual rolurile validez_*" >&2
    fi
}
trap cleanup EXIT

echo "== pregătire"
[ "$(admin -c "SELECT COUNT(*) FROM pg_roles WHERE rolname IN ($ROLES)")" = 0 ] \
    || die "rolurile validez_* există deja în acest cluster; testul nu le atinge"
admin -c "CREATE DATABASE $TEST_DB" >/dev/null 2>&1 \
    || die "baza $TEST_DB există (altă rulare în curs sau una întreruptă; șterge-o manual dacă nu rulează nimic)"
created_db=1
pass "instanță unică (baza $TEST_DB creată de această rulare)"

echo "== refuz pe validez_test, fără schimbări"
# Varianta cu ROLLBACK în loc de COMMIT: chiar dacă refuzul ar lipsi, nimic nu rămâne pe baza existentă.
expect_failure "configure-roles.sql refuză validez_test" "Nu aplica rolurile pe validez_test" \
    bash -c "sed 's/^COMMIT;\$/ROLLBACK;/' $SQL | docker compose exec -T db psql -X -v ON_ERROR_STOP=1 -qtA -U $ADMIN -d validez_test"
[ "$(admin -c "SELECT COUNT(*) FROM pg_roles WHERE rolname IN ($ROLES)")" = 0 ] || die "refuzul a lăsat roluri create"
pass "niciun rol creat de refuz"

echo "== baza temporară: schema copiată, script aplicat de două ori"
docker compose exec -T db pg_dump -U "$ADMIN" -d "$SOURCE_DB" --schema-only --no-owner --no-privileges \
    | psql_as "$ADMIN" "$TEST_DB" >/dev/null
created_roles=1
psql_as "$ADMIN" "$TEST_DB" < $SQL >/dev/null
psql_as "$ADMIN" "$TEST_DB" < $SQL >/dev/null
pass "script aplicat și reaplicat (reluabil)"

echo "== runtime (conexiune nouă ca validez_app)"
psql_as validez_app "$TEST_DB" >/dev/null <<'SQL'
SELECT id FROM public.integrity_event_log LIMIT 0;
INSERT INTO public.integrity_event_log SELECT * FROM public.integrity_event_log WHERE false;
BEGIN;
SELECT 1 FROM public.app_user LIMIT 1 FOR UPDATE;
SELECT 1 FROM public.civic_subject LIMIT 1 FOR UPDATE;
COMMIT;
-- ADR-0012 p.3: obiectele postgis sunt citibile, functiile utilizabile.
SELECT srid FROM public.spatial_ref_sys WHERE srid = 4326;
SELECT ST_AsText(ST_SetSRID(ST_MakePoint(23.6, 46.77), 4326));
DO $checks$
DECLARE statement text;
BEGIN
    FOREACH statement IN ARRAY ARRAY[
        'UPDATE public.integrity_event_log SET sequence_number = sequence_number WHERE false',
        'DELETE FROM public.integrity_event_log WHERE false',
        'TRUNCATE public.integrity_event_log',
        'ALTER TABLE public.integrity_event_log DISABLE TRIGGER trg_integrity_event_log_append_only',
        'DROP TRIGGER trg_integrity_event_log_append_only ON public.integrity_event_log',
        'CREATE TABLE public.__validez_role_test_forbidden (id integer)',
        'CREATE TEMP TABLE __validez_role_test_temp (id integer)',
        'INSERT INTO public.doctrine_migration_versions (version) VALUES (''x'')',
        'INSERT INTO public.spatial_ref_sys SELECT * FROM public.spatial_ref_sys WHERE false',
        'UPDATE public.spatial_ref_sys SET srtext = srtext WHERE false',
        'DELETE FROM public.spatial_ref_sys WHERE false',
        'SET ROLE validez_owner'
    ] LOOP
        BEGIN
            EXECUTE statement;
            RAISE EXCEPTION 'Permisiune interzisa acceptata: %', statement;
        EXCEPTION WHEN insufficient_privilege THEN
            NULL;
        END;
    END LOOP;
END
$checks$;
SQL
pass "SELECT/INSERT și lockuri de rând permise; postgis doar citire; jurnal, trigger, CREATE, TEMP, migrări, scriere în spatial_ref_sys și escaladare respinse"

echo "== migrator (conexiune nouă ca validez_migrator, fără SET ROLE explicit)"
psql_as validez_migrator "$TEST_DB" >/dev/null <<'SQL'
DO $role$
BEGIN
    IF current_setting('role') <> 'validez_owner' OR current_user <> 'validez_owner' THEN
        RAISE EXCEPTION 'Migratorul nu lucreaza ca owner la conectare (role=%, current_user=%)', current_setting('role'), current_user;
    END IF;
END
$role$;
CREATE TABLE public.__validez_role_test_new (id integer PRIMARY KEY, note text);
ALTER TABLE public.__validez_role_test_new ADD COLUMN checked boolean;
DO $owner$
BEGIN
    IF (SELECT relowner FROM pg_class WHERE oid = 'public.__validez_role_test_new'::regclass) <> 'validez_owner'::regrole THEN
        RAISE EXCEPTION 'Tabela noua nu apartine validez_owner';
    END IF;
END
$owner$;
SQL
pass "rol efectiv validez_owner la conectare; tabela nouă aparține ownerului"

echo "== tabelă nouă: detectare, apoi remediere prin reaplicare"
# Doar blocul de verificare din script, fără reaplicarea GRANT-urilor. [$] = dolar literal (nu ancoră).
VERIFY=$(awk '/^DO [$]verify[$]/,/^[$]verify[$];/' $SQL)
grep -q 'fara DML pentru validez_app' <<<"$VERIFY" || die "nu am putut extrage blocul \$verify\$ din $SQL"
run_verify(){ printf '%s\n' "$VERIFY" | psql_as "$ADMIN" "$TEST_DB"; }
expect_failure "verificarea prinde tabela fără DML pentru runtime" "Exista tabele operationale fara DML pentru validez_app" run_verify
expect_failure "runtime nu poate folosi tabela înainte de reaplicare" "permission denied" \
    psql_as validez_app "$TEST_DB" -c "SELECT * FROM public.__validez_role_test_new"
psql_as "$ADMIN" "$TEST_DB" < $SQL >/dev/null
psql_as validez_app "$TEST_DB" >/dev/null <<'SQL'
INSERT INTO public.__validez_role_test_new (id, note) VALUES (1, 'a');
UPDATE public.__validez_role_test_new SET checked = true WHERE id = 1;
SELECT * FROM public.__validez_role_test_new;
DELETE FROM public.__validez_role_test_new WHERE id = 1;
SQL
pass "după reaplicare runtime are SELECT/INSERT/UPDATE/DELETE pe tabela nouă"

echo "Roluri PostgreSQL: verificări OK; baza temporară și rolurile create de test sunt șterse la ieșire."
