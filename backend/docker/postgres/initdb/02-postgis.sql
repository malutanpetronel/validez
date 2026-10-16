-- Ruleaza o singura data, la initializarea volumului PostgreSQL (docker-entrypoint-initdb.d), ca superuser.
-- ADR-0012 p.3: PostGIS nu e extensie "trusted", deci nu o poate crea migratorul. Pe volume existente,
-- acelasi lucru il face docker/postgres/admin/extensions.sql (bin/deploy-db.sh pe productie, Deploy.md pe dev).
-- Doar postgis: fara postgis_topology / postgis_tiger_geocoder din scriptul initdb al imaginii.
CREATE EXTENSION IF NOT EXISTS postgis;
-- Alegere explicita: si in template1, ca o baza creata ulterior (doctrine:database:create --env=test)
-- sa treaca de verificarea din migrare fara pas manual. template_postgis din imagine nu se creeaza.
\connect template1
CREATE EXTENSION IF NOT EXISTS postgis;
