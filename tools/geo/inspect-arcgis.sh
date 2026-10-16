#!/bin/bash
# Inspecție izolată a exportului ArcGIS (G.1, ADR-0012). Rulare, din rădăcina repository-ului:
#   bash tools/geo/inspect-arcgis.sh backend/var/geo/arcgis-20261016 > backend/var/geo/raport-arcgis.txt 2>&1
# Container PostGIS și rețea temporare, șterse la final; baza de dev nu e atinsă.
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
EXPORT=$1
GEO=$PWD/backend/var/geo
NET=validez-geo-net; DB=validez-geo-inspect; IMG=postgis/postgis:16-3.5-alpine; GDAL=ghcr.io/osgeo/gdal:alpine-small-latest
cleanup(){ docker rm -f $DB >/dev/null 2>&1 || true; docker network rm $NET >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create $NET >/dev/null
docker run -d --name $DB --network $NET -e POSTGRES_PASSWORD=inspect -e POSTGRES_USER=geo -e POSTGRES_DB=geo -v "$GEO":/geo:ro $IMG >/dev/null
for i in $(seq 1 60); do docker exec $DB psql -U geo -d geo -Atc "select 1 from pg_extension where extname='postgis'" 2>/dev/null | grep -q 1 && break; sleep 2; done
sleep 3
# shp2pgsql din imaginea Alpine are nevoie de libintl (lipsește din imagine; doar în acest container temporar).
docker exec $DB apk add --no-cache -q libintl
docker exec $DB sh -c 'set -e; for t in tara judet UAT; do l=$(echo $t | tr A-Z a-z); shp2pgsql -s 3844 -W UTF-8 -D -I -c /geo/unitate_administrativa_$l/Unitate_administrativa_$t.shp public.src_$l 2>/dev/null | psql -q -U geo -d geo >/dev/null; done'
docker exec $DB sh -c "sed '1s/^\xEF\xBB\xBF//' /geo/siruta_s1_2026.csv > /tmp/siruta.csv"
docker exec -i $DB psql -q -X -v ON_ERROR_STOP=1 -U geo -d geo <<'SQL'
CREATE TABLE siruta (siruta int PRIMARY KEY, denloc text, codp text, jud int, sirsup int, tip int, niv int, med int, regiune int, fsj int, nuts text, lau text);
\copy siruta FROM '/tmp/siruta.csv' WITH (FORMAT csv, DELIMITER ';', HEADER true)
ALTER TABLE src_uat ADD COLUMN geom_valid geometry(MultiPolygon, 3844);
UPDATE src_uat SET geom_valid = ST_Multi(ST_CollectionExtract(ST_MakeValid(geom), 3));
ALTER TABLE src_judet ADD COLUMN geom_valid geometry(MultiPolygon, 3844);
UPDATE src_judet SET geom_valid = ST_Multi(ST_CollectionExtract(ST_MakeValid(geom), 3));
SQL
echo "== încărcarea exportului ArcGIS cu ogr2ogr (driver ESRIJSON), fără reparare, SRS EPSG:3844 ca fișierul 2024"
first=1
for f in "$EXPORT"/page-*.json; do
    mode=$([ $first = 1 ] && echo "-lco GEOMETRY_NAME=geom -lco FID=gid" || echo "-append")
    docker run --rm --network $NET -v "$GEO":/geo:ro $GDAL ogr2ogr -f PostgreSQL "PG:host=$DB user=geo password=inspect dbname=geo" \
        "/geo/${f#backend/var/geo/}" -nln arc_uat -nlt MULTIPOLYGON -a_srs EPSG:3844 $mode
    first=0
done
docker exec $DB psql -U geo -d geo -Atc "select 'arc_uat incarcate: '||count(*) from arc_uat"
docker exec -i $DB psql -X -U geo -d geo < docs/geo/inspectie-arcgis.sql
