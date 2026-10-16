-- Inspectia exportului ArcGIS (arc_uat, incarcat cu ogr2ogr din paginile Esri JSON) fata de SIRUTA 2026
-- si de fisierul ANCPI 2024 (src_uat). Container temporar validez-geo-inspect. G.1, ADR-0012.
\set ON_ERROR_STOP on
\pset footer off

\echo '=== A. Export: numar, unicitate, versiune'
SELECT count(*) AS entitati, count(DISTINCT nationalcode) AS coduri_distincte, count(*) FILTER (WHERE nationalcode !~ '^[0-9]+$') AS coduri_nenumerice,
       count(DISTINCT left(inspireid_versionid, 8)) AS versiuni, min(left(inspireid_versionid, 8)) AS versiune,
       count(*) FILTER (WHERE endlifespanversion IS NOT NULL) AS cu_sfarsit_de_versiune
FROM arc_uat;
SELECT nationallevelname_1, count(*) FROM arc_uat GROUP BY 1 ORDER BY 1;

\echo '=== B. Coduri: potrivire EXACTA cu SIRUTA 2026 TIP 1-6'
SELECT count(*) FILTER (WHERE s.siruta IS NOT NULL AND a.nationalcode IS NOT NULL) AS comune,
       count(*) FILTER (WHERE s.siruta IS NULL) AS doar_arcgis, count(*) FILTER (WHERE a.nationalcode IS NULL) AS doar_siruta
FROM arc_uat a FULL JOIN (SELECT * FROM siruta WHERE tip BETWEEN 1 AND 6) s ON s.siruta = a.nationalcode::int;
\echo '--- judetul din inspireId_localId (al doilea segment) vs SIRSUP (fara sectoare); doar diferentele'
SELECT a.nationalcode, a.name_1, a.inspireid_localid, s.sirsup FROM arc_uat a JOIN siruta s ON s.siruta = a.nationalcode::int
WHERE s.tip <> 6 AND split_part(a.inspireid_localid, '.', 2)::int <> s.sirsup ORDER BY 1 LIMIT 20;
\echo '--- sectoarele Bucurestiului: cod, nume, localId, upperLevelUnit'
SELECT nationalcode, name_1, inspireid_localid, upperlevelunit FROM arc_uat WHERE nationalcode::int BETWEEN 179141 AND 179196 ORDER BY 1;
\echo '--- denumiri ArcGIS vs fisierul 2024 (cod comun, nume diferit)'
SELECT a.nationalcode, u.name AS fisier_2024, a.name_1 AS arcgis FROM arc_uat a JOIN src_uat u ON u.natcode = a.nationalcode WHERE a.name_1 <> u.name;

\echo '=== C. Validitate (originalul in geom; reparatia separat, in geom_valid)'
SELECT count(*) FILTER (WHERE NOT ST_IsValid(geom)) AS invalide, count(*) FILTER (WHERE ST_IsEmpty(geom)) AS goale, count(*) AS total FROM arc_uat;
SELECT nationalcode, name_1, ST_IsValidReason(geom) FROM arc_uat WHERE NOT ST_IsValid(geom) ORDER BY nationalcode::int LIMIT 25;
ALTER TABLE arc_uat ADD COLUMN geom_valid geometry(MultiPolygon, 3844);
UPDATE arc_uat SET geom_valid = ST_Multi(ST_CollectionExtract(ST_MakeValid(geom), 3));
SELECT nationalcode, name_1, round(ST_Area(geom)::numeric, 1) AS aria_originala_m2, round(ST_Area(geom_valid)::numeric, 1) AS aria_reparata_m2
FROM arc_uat WHERE NOT ST_IsValid(geom) ORDER BY nationalcode::int LIMIT 25;
CREATE INDEX arc_uat_gv ON arc_uat USING gist (geom_valid);

\echo '=== D. Relatii intre UAT-uri vecine: frontiera comuna vs intersectie cu suprafata pozitiva'
CREATE TABLE arc_pairs AS
SELECT a.nationalcode AS a_code, a.name_1 AS a_name, b.nationalcode AS b_code, b.name_1 AS b_name, ST_Relate(a.geom_valid, b.geom_valid) AS de9im
FROM arc_uat a JOIN arc_uat b ON a.nationalcode < b.nationalcode AND a.geom_valid && b.geom_valid AND ST_Intersects(a.geom_valid, b.geom_valid);
ALTER TABLE arc_pairs ADD COLUMN overlap_m2 double precision;
UPDATE arc_pairs p SET overlap_m2 = ST_Area(ST_Intersection(a.geom_valid, b.geom_valid))
FROM arc_uat a, arc_uat b WHERE a.nationalcode = p.a_code AND b.nationalcode = p.b_code AND left(p.de9im, 1) = '2';
SELECT CASE WHEN left(de9im,1) = '2' THEN 'intersectie cu suprafata (interioare comune)'
            WHEN substr(de9im,5,1) = '1' THEN 'frontiera comuna (linie)'
            WHEN substr(de9im,5,1) = '0' THEN 'atingere intr-un punct' ELSE 'alt tip: '||de9im END AS relatie,
       count(*), round(sum(overlap_m2)::numeric, 3) AS suprafata_totala_m2, round(max(overlap_m2)::numeric, 3) AS max_m2
FROM arc_pairs GROUP BY 1 ORDER BY 2 DESC;
SELECT CASE WHEN overlap_m2 < 1 THEN '< 1 m2' WHEN overlap_m2 < 100 THEN '1-100 m2' WHEN overlap_m2 < 10000 THEN '100 m2 - 1 ha' ELSE '>= 1 ha' END AS clasa, count(*)
FROM arc_pairs WHERE overlap_m2 > 0 GROUP BY 1 ORDER BY min(overlap_m2);
SELECT a_code, a_name, b_code, b_name, round(overlap_m2::numeric, 3) AS m2 FROM arc_pairs WHERE overlap_m2 > 0 ORDER BY overlap_m2 DESC LIMIT 15;

\echo '=== E. Acoperire: uniunea UAT-urilor ArcGIS fata de tara si judetele din fisierul 2024'
CREATE TABLE arc_county AS
SELECT split_part(inspireid_localid, '.', 2) AS jud, ST_Union(geom_valid) AS g FROM arc_uat GROUP BY 1;
SELECT round(ST_Area(ST_Difference(t.geom, x.g))::numeric, 1) AS tara_minus_uat_m2, round(ST_Area(ST_Difference(x.g, t.geom))::numeric, 1) AS uat_minus_tara_m2
FROM src_tara t, (SELECT ST_Union(g) AS g FROM arc_county) x;
SELECT c.jud, j.name, round(ST_Area(ST_Difference(j.geom_valid, c.g))::numeric, 1) AS judet_2024_minus_uat_m2, round(ST_Area(ST_Difference(c.g, j.geom_valid))::numeric, 1) AS uat_minus_judet_2024_m2
FROM arc_county c JOIN src_judet j ON j.natcode = c.jud
WHERE ST_Area(ST_SymDifference(j.geom_valid, c.g)) >= 1 ORDER BY ST_Area(ST_SymDifference(j.geom_valid, c.g)) DESC;

\echo '=== F. Geometrie ArcGIS 2025 vs fisier 2024, in aceeasi proiectie (Stereo70)'
CREATE TABLE cmp AS
-- Fara ST_HausdorffDistance/ST_Equals pe toate perechile (prea lente pe poligoane detaliate). Deplasarea medie
-- a frontierei se estimeaza ca diferenta simetrica / perimetru.
SELECT a.nationalcode, a.name_1,
       ST_Area(ST_SymDifference(a.geom_valid, u.geom_valid)) AS symdiff_m2,
       ST_Perimeter(u.geom_valid) AS perimetru_m,
       ST_NPoints(a.geom) AS puncte_arcgis, ST_NPoints(u.geom) AS puncte_2024,
       ST_Area(a.geom_valid) - ST_Area(u.geom_valid) AS dif_arie_m2
FROM arc_uat a JOIN src_uat u ON u.natcode = a.nationalcode;
SELECT CASE WHEN symdiff_m2 = 0 THEN 'a) diferenta simetrica 0' WHEN symdiff_m2 < 1 THEN 'b) diferenta simetrica < 1 m2'
            WHEN symdiff_m2 < 100 THEN 'c) 1-100 m2' WHEN symdiff_m2 < 10000 THEN 'd) 100 m2 - 1 ha' ELSE 'e) >= 1 ha' END AS clasa,
       count(*), round(avg(symdiff_m2 / perimetru_m)::numeric, 3) AS deplasare_medie_m, round(max(symdiff_m2 / perimetru_m)::numeric, 3) AS deplasare_max_m,
       sum(puncte_arcgis) - sum(puncte_2024) AS dif_puncte
FROM cmp GROUP BY 1 ORDER BY 1;
\echo '--- numar de varfuri: ArcGIS vs 2024'
SELECT sum(puncte_arcgis) AS varfuri_arcgis, sum(puncte_2024) AS varfuri_2024, count(*) FILTER (WHERE puncte_arcgis <> puncte_2024) AS uat_cu_numar_diferit FROM cmp;
\echo '--- UAT-uri cu diferenta simetrica >= 1 m2 (modificari de geometrie)'
SELECT nationalcode, name_1, round(symdiff_m2::numeric, 1) AS symdiff_m2, round(dif_arie_m2::numeric, 1) AS dif_arie_m2,
       round((symdiff_m2 / perimetru_m)::numeric, 2) AS deplasare_medie_m, puncte_arcgis, puncte_2024
FROM cmp WHERE symdiff_m2 >= 10000 ORDER BY symdiff_m2 DESC LIMIT 40;
\echo '--- control: ariile calculate local vs Shape__Area din serviciu'
SELECT round(max(abs(ST_Area(geom) - shape__area))::numeric, 3) AS dif_max_m2 FROM arc_uat;

\echo '=== G. Deplasare sistematica? Diferenta dintre centroizi (UAT cu acelasi numar de varfuri)'
-- Primul varf al inelului nu e acelasi punct in cele doua surse; centroidul unei forme neschimbate se deplaseaza exact cu translatia.
CREATE TABLE shift AS
SELECT c.nationalcode, ST_X(ST_Centroid(a.geom)) - ST_X(ST_Centroid(u.geom)) AS dx, ST_Y(ST_Centroid(a.geom)) - ST_Y(ST_Centroid(u.geom)) AS dy,
       ST_X(ST_Centroid(u.geom)) AS x, ST_Y(ST_Centroid(u.geom)) AS y
FROM cmp c JOIN arc_uat a ON a.nationalcode = c.nationalcode JOIN src_uat u ON u.natcode = c.nationalcode
WHERE c.puncte_arcgis = c.puncte_2024;
SELECT count(*) AS uat, round(avg(dx)::numeric, 3) AS dx_mediu_m, round(stddev(dx)::numeric, 3) AS dx_abatere, round(avg(dy)::numeric, 3) AS dy_mediu_m,
       round(stddev(dy)::numeric, 3) AS dy_abatere, round(min(sqrt(dx*dx+dy*dy))::numeric, 3) AS d_min_m, round(max(sqrt(dx*dx+dy*dy))::numeric, 3) AS d_max_m
FROM shift;
\echo '--- pe zone (vest/centru/est x nord/sud, dupa centroid): variatia deplasarii in teritoriu'
SELECT CASE WHEN x < 400000 THEN 'vest' WHEN x < 600000 THEN 'centru' ELSE 'est' END AS zona_x, CASE WHEN y < 450000 THEN 'sud' ELSE 'nord' END AS zona_y,
       count(*), round(avg(dx)::numeric, 3) AS dx_m, round(avg(dy)::numeric, 3) AS dy_m, round(stddev(dx)::numeric, 3) AS dx_abatere, round(stddev(dy)::numeric, 3) AS dy_abatere
FROM shift GROUP BY 1, 2 ORDER BY 1, 2;
\echo '--- dupa translatia proprie (dx, dy al fiecarui UAT): ramane o diferenta de forma?'
SELECT count(*) AS uat,
       round(avg(ST_Area(ST_SymDifference(ST_Translate(u.geom_valid, s.dx, s.dy), a.geom_valid)) / ST_Perimeter(u.geom_valid))::numeric, 4) AS deplasare_reziduala_medie_m,
       round(max(ST_Area(ST_SymDifference(ST_Translate(u.geom_valid, s.dx, s.dy), a.geom_valid)) / ST_Perimeter(u.geom_valid))::numeric, 4) AS deplasare_reziduala_max_m
FROM shift s JOIN arc_uat a ON a.nationalcode = s.nationalcode JOIN src_uat u ON u.natcode = s.nationalcode;
