-- Inspectie izolata a surselor (G.1, ADR-0012). Ruleaza doar in containerul temporar validez-geo-inspect.
\set ON_ERROR_STOP on
\pset footer off

-- SIRUTA S1 2026 (BOM scos inainte, in /tmp/siruta.csv)
DROP TABLE IF EXISTS siruta;
CREATE TABLE siruta (siruta int PRIMARY KEY, denloc text, codp text, jud int, sirsup int, tip int, niv int, med int,
                     regiune int, fsj int, nuts text, lau text);
\copy siruta FROM '/tmp/siruta.csv' WITH (FORMAT csv, DELIMITER ';', HEADER true)

\echo '=== 1. SIRUTA: inregistrari pe TIP (40 judet/Bucuresti, 1-5 UAT, 6 sector)'
SELECT tip, niv, count(*) FROM siruta WHERE tip IN (40,1,2,3,4,5,6) GROUP BY tip, niv ORDER BY tip;
\echo '--- coduri SIRSUP ale judetelor (parintele lor) si existenta unei inregistrari pentru tara'
SELECT sirsup, count(*) FROM siruta WHERE tip = 40 GROUP BY sirsup;
SELECT count(*) AS inregistrare_cu_siruta_1 FROM siruta WHERE siruta = 1;
\echo '--- Bucuresti: judetul, municipiul si sectoarele (parinte SIRSUP)'
SELECT siruta, denloc, tip, niv, sirsup, jud FROM siruta WHERE jud = (SELECT jud FROM siruta WHERE tip = 6 LIMIT 1) AND tip IN (40,1,6) ORDER BY tip DESC, siruta;

\echo '=== 2. ANCPI 2024: niveluri, unicitate natcode, versiuni'
SELECT natlevel, natlevname, count(*) FROM (SELECT natlevel, natlevname FROM src_tara UNION ALL SELECT natlevel, natlevname FROM src_judet UNION ALL SELECT natlevel, natlevname FROM src_uat) x GROUP BY 1,2 ORDER BY 1,2;
SELECT 'uat natcode duplicate' AS verificare, count(*) FROM (SELECT natcode FROM src_uat GROUP BY natcode HAVING count(*) > 1) d
UNION ALL SELECT 'judet natcode duplicate', count(*) FROM (SELECT natcode FROM src_judet GROUP BY natcode HAVING count(*) > 1) d
UNION ALL SELECT 'uat natcode non-numeric', count(*) FROM src_uat WHERE natcode !~ '^[0-9]+$';
SELECT min(beginvers), max(beginvers), count(*) FILTER (WHERE endversion IS NOT NULL) AS cu_endversion FROM src_uat;

\echo '=== 3. Potrivire EXACTA de cod: judete ANCPI vs SIRUTA TIP 40'
SELECT 'doar ANCPI' AS parte, j.natcode, j.name, NULL::text AS denloc FROM src_judet j WHERE NOT EXISTS (SELECT 1 FROM siruta s WHERE s.siruta = j.natcode::int AND s.tip = 40)
UNION ALL SELECT 'doar SIRUTA', s.siruta::text, NULL, s.denloc FROM siruta s WHERE s.tip = 40 AND NOT EXISTS (SELECT 1 FROM src_judet j WHERE j.natcode::int = s.siruta);

\echo '=== 4. Potrivire EXACTA de cod: UAT ANCPI vs SIRUTA TIP 1-6'
SELECT count(*) FILTER (WHERE s.siruta IS NOT NULL AND u.natcode IS NOT NULL) AS comune_ambelor,
       count(*) FILTER (WHERE s.siruta IS NULL) AS doar_ancpi, count(*) FILTER (WHERE u.natcode IS NULL) AS doar_siruta
FROM src_uat u FULL JOIN (SELECT * FROM siruta WHERE tip BETWEEN 1 AND 6) s ON s.siruta = u.natcode::int;
\echo '--- doar in ANCPI 2024 (cod absent din SIRUTA 2026 ca UAT)'
SELECT u.natcode, u.name, u.natlevname, s2.tip AS tip_in_siruta, s2.denloc AS denumire_in_siruta
FROM src_uat u LEFT JOIN siruta s2 ON s2.siruta = u.natcode::int
WHERE NOT EXISTS (SELECT 1 FROM siruta s WHERE s.siruta = u.natcode::int AND s.tip BETWEEN 1 AND 6) ORDER BY u.natcode::int;
\echo '--- doar in SIRUTA 2026 (UAT fara poligon ANCPI 2024)'
SELECT s.siruta, s.denloc, s.tip, s.sirsup FROM siruta s WHERE s.tip BETWEEN 1 AND 6
  AND NOT EXISTS (SELECT 1 FROM src_uat u WHERE u.natcode::int = s.siruta) ORDER BY s.siruta;
\echo '--- parinte: judetul din localid ANCPI (al doilea segment) vs SIRSUP; doar diferentele'
SELECT u.natcode, u.name, u.localid, s.sirsup FROM src_uat u JOIN siruta s ON s.siruta = u.natcode::int
WHERE split_part(u.localid, '.', 2)::int <> s.sirsup AND s.tip <> 6 ORDER BY 1 LIMIT 20;
\echo '--- denumiri: cod comun, nume diferit (comparatie exacta dupa scoaterea prefixului MUNICIPIUL/ORAS si a majusculelor; doar raportare)'
WITH p AS (
  SELECT u.natcode, u.name AS ancpi, s.denloc AS siruta,
         upper(u.name) AS a, upper(regexp_replace(s.denloc, '^(MUNICIPIUL|ORAŞ|ORAS|ORAȘ) ', '')) AS b
  FROM src_uat u JOIN siruta s ON s.siruta = u.natcode::int AND s.tip BETWEEN 1 AND 6)
SELECT count(*) FILTER (WHERE a <> b) AS nume_diferite,
       count(*) FILTER (WHERE a <> b AND translate(a,'ŞŢşţ','ȘȚșț') = translate(b,'ŞŢşţ','ȘȚșț')) AS doar_sedila_vs_virgula
FROM p;
WITH p AS (
  SELECT u.natcode, u.name AS ancpi, s.denloc AS siruta,
         translate(upper(u.name),'ŞŢ','ȘȚ') AS a, translate(upper(regexp_replace(s.denloc, '^(MUNICIPIUL|ORAŞ|ORAS|ORAȘ) ', '')),'ŞŢ','ȘȚ') AS b
  FROM src_uat u JOIN siruta s ON s.siruta = u.natcode::int AND s.tip BETWEEN 1 AND 6)
SELECT natcode, ancpi, siruta FROM p WHERE a <> b ORDER BY natcode::int LIMIT 40;

\echo '=== 5. Validitatea geometriilor (originalul ramane in geom; reparatia in geom_valid, doar pentru diagnostic)'
ALTER TABLE src_uat ADD COLUMN IF NOT EXISTS geom_valid geometry(MultiPolygon, 3844);
ALTER TABLE src_judet ADD COLUMN IF NOT EXISTS geom_valid geometry(MultiPolygon, 3844);
SELECT 'uat' AS nivel, count(*) FILTER (WHERE NOT ST_IsValid(geom)) AS invalide, count(*) AS total FROM src_uat
UNION ALL SELECT 'judet', count(*) FILTER (WHERE NOT ST_IsValid(geom)), count(*) FROM src_judet
UNION ALL SELECT 'tara', count(*) FILTER (WHERE NOT ST_IsValid(geom)), count(*) FROM src_tara;
SELECT natcode, name, ST_IsValidReason(geom) FROM src_uat WHERE NOT ST_IsValid(geom) ORDER BY natcode::int LIMIT 25;
UPDATE src_uat SET geom_valid = ST_Multi(ST_CollectionExtract(ST_MakeValid(geom), 3));
UPDATE src_judet SET geom_valid = ST_Multi(ST_CollectionExtract(ST_MakeValid(geom), 3));
SELECT natcode, name, round(ST_Area(geom)::numeric, 1) AS aria_originala_m2, round(ST_Area(geom_valid)::numeric, 1) AS aria_reparata_m2
FROM src_uat WHERE NOT ST_IsValid(geom) ORDER BY natcode::int LIMIT 25;
CREATE INDEX IF NOT EXISTS src_uat_gv ON src_uat USING gist (geom_valid);

\echo '=== 6. Relatii intre UAT-uri vecine (pe geometria reparata): frontiera comuna vs intersectie cu suprafata pozitiva'
DROP TABLE IF EXISTS pairs;
CREATE TABLE pairs AS
SELECT a.natcode AS a_code, a.name AS a_name, b.natcode AS b_code, b.name AS b_name,
       ST_Relate(a.geom_valid, b.geom_valid) AS de9im
FROM src_uat a JOIN src_uat b ON a.gid < b.gid AND a.geom_valid && b.geom_valid AND ST_Intersects(a.geom_valid, b.geom_valid);
ALTER TABLE pairs ADD COLUMN overlap_m2 double precision;
UPDATE pairs p SET overlap_m2 = ST_Area(ST_Intersection(a.geom_valid, b.geom_valid))
FROM src_uat a, src_uat b WHERE a.natcode = p.a_code AND b.natcode = p.b_code AND left(p.de9im, 1) = '2';
SELECT CASE WHEN left(de9im,1) = '2' THEN 'intersectie cu suprafata (interioare comune)'
            WHEN substr(de9im,5,1) = '1' THEN 'frontiera comuna (linie)'
            WHEN substr(de9im,5,1) = '0' THEN 'atingere intr-un punct'
            ELSE 'alt tip: '||de9im END AS relatie,
       count(*), round(sum(overlap_m2)::numeric, 1) AS suprafata_totala_m2, round(max(overlap_m2)::numeric, 3) AS max_m2
FROM pairs GROUP BY 1 ORDER BY 2 DESC;
\echo '--- distributia suprapunerilor cu suprafata pozitiva'
SELECT CASE WHEN overlap_m2 < 1 THEN '< 1 m2' WHEN overlap_m2 < 100 THEN '1-100 m2' WHEN overlap_m2 < 10000 THEN '100 m2 - 1 ha' ELSE '>= 1 ha' END AS clasa, count(*)
FROM pairs WHERE overlap_m2 > 0 GROUP BY 1 ORDER BY min(overlap_m2);
SELECT a_code, a_name, b_code, b_name, round(overlap_m2::numeric, 1) AS m2 FROM pairs WHERE overlap_m2 > 0 ORDER BY overlap_m2 DESC LIMIT 15;

\echo '=== 7. Goluri si depasiri: UAT-uri vs judetul lor, judete vs tara'
DROP TABLE IF EXISTS county_cmp;
CREATE TABLE county_cmp AS
SELECT j.natcode, j.name, ST_Area(j.geom_valid) AS judet_m2, ST_Union(u.geom_valid) AS uats
FROM src_judet j JOIN src_uat u ON split_part(u.localid, '.', 2) = j.natcode GROUP BY j.natcode, j.name, j.geom_valid;
SELECT count(*) AS judete_comparate,
       round(sum(ST_Area(ST_Difference(j.geom_valid, c.uats)))::numeric, 1) AS gol_total_m2,
       round(max(ST_Area(ST_Difference(j.geom_valid, c.uats)))::numeric, 1) AS gol_max_m2,
       round(sum(ST_Area(ST_Difference(c.uats, j.geom_valid)))::numeric, 1) AS depasire_total_m2
FROM county_cmp c JOIN src_judet j ON j.natcode = c.natcode;
SELECT c.natcode, c.name, round(ST_Area(ST_Difference(j.geom_valid, c.uats))::numeric, 1) AS gol_m2, round(ST_Area(ST_Difference(c.uats, j.geom_valid))::numeric, 1) AS depasire_m2
FROM county_cmp c JOIN src_judet j ON j.natcode = c.natcode ORDER BY 3 DESC LIMIT 8;
SELECT round(ST_Area(ST_Difference(t.geom, (SELECT ST_Union(geom_valid) FROM src_judet)))::numeric, 1) AS tara_minus_judete_m2,
       round(ST_Area(ST_Difference((SELECT ST_Union(geom_valid) FROM src_judet), t.geom))::numeric, 1) AS judete_minus_tara_m2
FROM src_tara t;

\echo '=== 8. Reproiectare: centroidul Cluj-Napoca in WGS84 (doar control grosier; datumul se verifica fata de ArcGIS)'
SELECT natcode, name, ST_AsText(ST_Transform(ST_PointOnSurface(geom_valid), 4326)) FROM src_uat WHERE natcode IN ('54975','179141');
SELECT proj4text FROM spatial_ref_sys WHERE srid = 3844;
