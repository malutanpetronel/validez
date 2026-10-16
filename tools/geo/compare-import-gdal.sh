#!/bin/bash
# G.1c-b: compară geometriile importate de app:geo:import-boundaries (geom_source, construite în PHP din inelele Esri)
# cu cele produse de GDAL (ogr2ogr, driver ESRIJSON) din aceleași pagini. Rulare, din rădăcina repository-ului:
#   bash tools/geo/compare-import-gdal.sh backend/var/geo/arcgis-20261016
# Baza de dev e doar citită (\copy spre fișier); comparația rulează într-un container PostGIS temporar, șters la final.
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
EXPORT=$1
GEO=$PWD/backend/var/geo
NET=validez-geo-net; DB=validez-geo-compare; IMG=postgis/postgis:16-3.5-alpine; GDAL=ghcr.io/osgeo/gdal:alpine-small-latest
SHA=$(sha256sum "$EXPORT/manifest.json" | cut -d' ' -f1)
cleanup(){ docker rm -f $DB >/dev/null 2>&1 || true; docker network rm $NET >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup

echo "== geometriile importate (set cu manifestul $SHA), din baza de dev, doar citire"
(cd backend && docker compose exec -T db psql -X -q -U validez -d validez -v ON_ERROR_STOP=1 -c "\copy (SELECT u.code, ST_AsEWKB(b.geom_source) FROM administrative_boundary b JOIN administrative_unit u ON u.id = b.administrative_unit_id JOIN boundary_set s ON s.id = b.boundary_set_id WHERE s.artifact_checksum = '$SHA') TO STDOUT WITH (FORMAT csv)") > "$GEO/import-geom.csv"
wc -l < "$GEO/import-geom.csv"

docker network create $NET >/dev/null
docker run -d --name $DB --network $NET -e POSTGRES_PASSWORD=compare -e POSTGRES_USER=geo -e POSTGRES_DB=geo -v "$GEO":/geo:ro $IMG >/dev/null
for i in $(seq 1 60); do docker exec $DB psql -U geo -d geo -Atc "select 1 from pg_extension where extname='postgis'" 2>/dev/null | grep -q 1 && break; sleep 2; done
sleep 3
first=1
for f in "$EXPORT"/page-*.json; do
    mode=$([ $first = 1 ] && echo "-lco GEOMETRY_NAME=geom -lco FID=gid" || echo "-append")
    docker run --rm --network $NET -v "$GEO":/geo:ro $GDAL ogr2ogr -f PostgreSQL "PG:host=$DB user=geo password=compare dbname=geo" \
        "/geo/${f#backend/var/geo/}" -nln gdal_uat -nlt MULTIPOLYGON -a_srs EPSG:3844 $mode
    first=0
done
docker exec -i $DB psql -X -U geo -d geo -v ON_ERROR_STOP=1 <<'SQL'
\pset footer off
CREATE TABLE imp (code text PRIMARY KEY, ewkb bytea);
\copy imp FROM '/geo/import-geom.csv' WITH (FORMAT csv)
ALTER TABLE imp ADD COLUMN geom geometry;
UPDATE imp SET geom = ST_GeomFromEWKB(ewkb);
\echo '=== import PHP vs GDAL, pe cod'
SELECT count(*) AS perechi,
       count(*) FILTER (WHERE ST_OrderingEquals(i.geom, g.geom)) AS identice_inclusiv_ordinea_punctelor,
       count(*) FILTER (WHERE ST_Equals(i.geom, g.geom)) AS egale_topologic,
       count(*) FILTER (WHERE ST_NumGeometries(i.geom) <> ST_NumGeometries(g.geom) OR ST_NPoints(i.geom) <> ST_NPoints(g.geom)) AS structura_diferita,
       (SELECT count(*) FROM imp WHERE code NOT IN (SELECT nationalcode FROM gdal_uat)) AS doar_import,
       (SELECT count(*) FROM gdal_uat WHERE nationalcode NOT IN (SELECT code FROM imp)) AS doar_gdal
FROM imp i JOIN gdal_uat g ON g.nationalcode = i.code;
\echo '--- primele diferente (daca exista)'
SELECT i.code, ST_NumGeometries(i.geom) AS poligoane_php, ST_NumGeometries(g.geom) AS poligoane_gdal, ST_NPoints(i.geom) AS puncte_php, ST_NPoints(g.geom) AS puncte_gdal,
       round(ST_Area(ST_SymDifference(i.geom, g.geom))::numeric, 6) AS symdiff_m2
FROM imp i JOIN gdal_uat g ON g.nationalcode = i.code WHERE NOT ST_OrderingEquals(i.geom, g.geom) LIMIT 15;
SQL
