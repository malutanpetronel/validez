-- Rulare cu psql, ca superuser, pe baza Validez, INAINTEA migrarilor (bin/deploy-db.sh, pasul de extensii).
-- ADR-0012 p.3: extensiile care nu sunt "trusted" (postgis) nu le poate crea migratorul (validez_owner).
-- Reluabil. ltree e trusted si ramane in migrarea initiala; aici doar ce cere superuser.
\set ON_ERROR_STOP on
CREATE EXTENSION IF NOT EXISTS postgis;
