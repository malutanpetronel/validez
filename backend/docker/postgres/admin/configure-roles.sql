-- Rulare cu psql, ca superuser, pe baza Validez, DUPA migrari si INAINTE de trafic.
-- Rolurile sunt dedicate acestui proiect. Fara parole in SQL/repository.
-- Reluabil; nu reasigneaza obiectele extensiilor sau bazele altor proiecte.
\set ON_ERROR_STOP on
BEGIN;

DO $setup$
BEGIN
    IF NOT (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
        RAISE EXCEPTION 'Configurarea necesita un rol administrativ superuser.';
    END IF;
    IF to_regclass('public.integrity_event_log') IS NULL THEN
        RAISE EXCEPTION 'Ruleaza mai intai migrarea voturilor pe baza tinta.';
    END IF;
    -- Baza de test e curatata cu TRUNCATE ... CASCADE de utilizatorul de bootstrap; rolurile nu se aplica acolo.
    IF current_database() = 'validez_test' THEN
        RAISE EXCEPTION 'Nu aplica rolurile pe validez_test.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'validez_owner') THEN
        CREATE ROLE validez_owner NOLOGIN;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'validez_migrator') THEN
        CREATE ROLE validez_migrator LOGIN;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'validez_app') THEN
        CREATE ROLE validez_app LOGIN;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_auth_members WHERE member = 'validez_app'::regrole)
       OR EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'validez_app'
                  AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls)) THEN
        RAISE EXCEPTION 'validez_app are privilegii/membership neasteptate; verificare administrativa necesara.';
    END IF;
END
$setup$;

ALTER ROLE validez_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE validez_migrator LOGIN INHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE validez_app LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
GRANT validez_owner TO validez_migrator;
-- Conexiunile migratorului lucreaza ca owner: obiectele create de migrari au direct proprietarul corect.
-- Se aplica la conectare (nu la SET SESSION AUTHORIZATION), doar pe baza curenta.
SELECT format('ALTER ROLE validez_migrator IN DATABASE %I SET role = %L', current_database(), 'validez_owner') \gexec

-- Numai baza curenta; rolul de bootstrap/administrare nu este modificat.
SELECT format('GRANT CONNECT ON DATABASE %I TO validez_app, validez_migrator', current_database()) \gexec
-- Runtime nu are nevoie de tabele temporare. Superuserii (bootstrap/administrare) nu sunt afectati;
-- doar baza curenta, deci validez_test ramane neatinsa.
SELECT format('REVOKE TEMPORARY ON DATABASE %I FROM PUBLIC', current_database()) \gexec
REVOKE ALL ON SCHEMA public FROM PUBLIC, validez_app;
ALTER SCHEMA public OWNER TO validez_owner;
GRANT USAGE ON SCHEMA public TO validez_app;

-- Mutam doar obiectele aplicatiei din public, nu membrii extensiilor (ltree).
DO $ownership$
DECLARE obj record;
BEGIN
    FOR obj IN
        SELECT c.oid, c.relname, c.relkind
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'S', 'v', 'm')
          AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass
                          AND d.objid = c.oid AND d.deptype = 'e')
        ORDER BY CASE WHEN c.relkind = 'S' THEN 1 ELSE 0 END, c.relname
    LOOP
        EXECUTE format('ALTER %s public.%I OWNER TO validez_owner',
            CASE obj.relkind WHEN 'S' THEN 'SEQUENCE' WHEN 'v' THEN 'VIEW'
                 WHEN 'm' THEN 'MATERIALIZED VIEW' ELSE 'TABLE' END, obj.relname);
    END LOOP;
    FOR obj IN
        SELECT p.oid, p.proname, pg_get_function_identity_arguments(p.oid) AS args
        FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND p.prokind IN ('f', 'p')
          AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass
                          AND d.objid = p.oid AND d.deptype = 'e')
    LOOP
        EXECUTE format('ALTER ROUTINE public.%I(%s) OWNER TO validez_owner', obj.proname, obj.args);
        EXECUTE format('REVOKE ALL ON ROUTINE public.%I(%s) FROM PUBLIC, validez_app', obj.proname, obj.args);
    END LOOP;
END
$ownership$;

REVOKE ALL ON ALL TABLES IN SCHEMA public FROM PUBLIC, validez_app;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM PUBLIC, validez_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO validez_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO validez_app;

-- ADR-0012 p.3: obiectele extensiilor din public (ex. spatial_ref_sys, geometry_columns de la postgis) sunt doar
-- citite de runtime. Identificate prin apartenenta la extensie (pg_depend 'e'), nu dupa nume: tabelele aplicatiei,
-- inclusiv cele cu geometrii, raman in regula generala de mai sus.
DO $extension_objects$
DECLARE obj record;
BEGIN
    FOR obj IN
        SELECT c.relname, c.relkind
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'S')
          AND EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass
                      AND d.objid = c.oid AND d.deptype = 'e')
    LOOP
        EXECUTE format('REVOKE ALL ON %s public.%I FROM validez_app',
            CASE obj.relkind WHEN 'S' THEN 'SEQUENCE' ELSE 'TABLE' END, obj.relname);
        IF obj.relkind <> 'S' THEN
            EXECUTE format('GRANT SELECT ON TABLE public.%I TO validez_app', obj.relname);
        END IF;
    END LOOP;
END
$extension_objects$;

-- Jurnalul nu poate fi rescris/trunchiat de runtime, nici prin schimbarea triggerului.
REVOKE ALL ON public.integrity_event_log FROM validez_app;
GRANT SELECT, INSERT ON public.integrity_event_log TO validez_app;
-- Runtime nu modifica evidenta migrarilor.
REVOKE ALL ON public.doctrine_migration_versions FROM validez_app;
GRANT SELECT ON public.doctrine_migration_versions TO validez_app;

-- Obiectele NOI nu primesc automat DML pentru runtime: scriptul se reia dupa migrari.
-- Aceasta evita acordarea UPDATE/DELETE daca jurnalul este recreat de o migrare.
ALTER DEFAULT PRIVILEGES FOR ROLE validez_owner REVOKE ALL ON TABLES FROM PUBLIC, validez_app;
ALTER DEFAULT PRIVILEGES FOR ROLE validez_owner IN SCHEMA public REVOKE ALL ON TABLES FROM PUBLIC, validez_app;
ALTER DEFAULT PRIVILEGES FOR ROLE validez_migrator REVOKE ALL ON TABLES FROM PUBLIC, validez_app;
ALTER DEFAULT PRIVILEGES FOR ROLE validez_migrator IN SCHEMA public REVOKE ALL ON TABLES FROM PUBLIC, validez_app;
ALTER DEFAULT PRIVILEGES FOR ROLE validez_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC, validez_app;
ALTER DEFAULT PRIVILEGES FOR ROLE validez_migrator REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC, validez_app;

DO $verify$
BEGIN
    IF has_schema_privilege('validez_app', 'public', 'CREATE')
       OR has_database_privilege('validez_app', current_database(), 'TEMPORARY')
       OR has_table_privilege('validez_app', 'public.integrity_event_log', 'UPDATE')
       OR has_table_privilege('validez_app', 'public.integrity_event_log', 'DELETE')
       OR has_table_privilege('validez_app', 'public.integrity_event_log', 'TRUNCATE')
       OR has_table_privilege('validez_app', 'public.integrity_event_log', 'TRIGGER')
       OR NOT has_table_privilege('validez_app', 'public.integrity_event_log', 'SELECT')
       OR NOT has_table_privilege('validez_app', 'public.integrity_event_log', 'INSERT') THEN
        RAISE EXCEPTION 'Permisiunile efective ale validez_app nu respecta politica.';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_class WHERE relowner = 'validez_app'::regrole)
       OR EXISTS (SELECT 1 FROM pg_proc WHERE proowner = 'validez_app'::regrole) THEN
        RAISE EXCEPTION 'validez_app detine obiecte; proprietatea trebuie sa fie a validez_owner.';
    END IF;
    -- ADR-0012 p.3: runtime doar citeste obiectele extensiilor.
    IF EXISTS (
        SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm')
          AND EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e')
          AND (has_table_privilege('validez_app', c.oid, 'INSERT') OR has_table_privilege('validez_app', c.oid, 'UPDATE')
               OR has_table_privilege('validez_app', c.oid, 'DELETE') OR has_table_privilege('validez_app', c.oid, 'TRUNCATE')
               OR has_table_privilege('validez_app', c.oid, 'TRIGGER'))
    ) THEN
        RAISE EXCEPTION 'validez_app poate scrie in obiectele unei extensii.';
    END IF;
    -- Fiecare tabela operationala trebuie sa fie utilizabila de runtime imediat dupa script (prinde tabelele noi
    -- dintr-o migrare, inainte de trafic). Exceptii intentionate: jurnalul si evidenta migrarilor.
    IF EXISTS (
        SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
          AND c.relname NOT IN ('integrity_event_log', 'doctrine_migration_versions')
          AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e')
          AND NOT (has_table_privilege('validez_app', c.oid, 'SELECT') AND has_table_privilege('validez_app', c.oid, 'INSERT')
                   AND has_table_privilege('validez_app', c.oid, 'UPDATE') AND has_table_privilege('validez_app', c.oid, 'DELETE'))
    ) THEN
        RAISE EXCEPTION 'Exista tabele operationale fara DML pentru validez_app.';
    END IF;
END
$verify$;
COMMIT;
