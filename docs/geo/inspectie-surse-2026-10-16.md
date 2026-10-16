# Inspecția surselor geografice — 16.10.2026 (G.1, ADR-0012)

Inspecție izolată, într-un container PostGIS temporar (`postgis/postgis:16-3.5-alpine`, șters la final), fără atingerea bazei de dev. Fișierele descărcate stau în `backend/var/geo/` (ignorat de git), împreună cu `SHA256SUMS` și `descarcari.log`. Interogările: [inspectie-surse.sql](inspectie-surse.sql); ieșirea lor completă: [inspectie-surse-2026-10-16.txt](inspectie-surse-2026-10-16.txt).

## Fișiere

| Fișier | Sursă | Licență | Descărcat (UTC) | SHA-256 |
|---|---|---|---|---|
| `siruta_s1_2026.csv` | INS, data.gov.ro „SIRUTA_s1 2026” ([resursă](https://data.gov.ro/dataset/721c9059-5f87-4c79-9854-a1d5c18f58d5/resource/dac903f0-32b5-489a-89e7-2c80d96cf68d/download/siruta_s1_2026.csv)) | CC-BY-4.0 | 2026-10-16T16:46:27Z | `f443e49af2f9c9dce8ea515ae53090a18fb476547466b6cd804051424eaf72c6` |
| `legenda-siruta.xml` | INS, același set ([resursă](https://data.gov.ro/dataset/06950cb3-41b7-48b8-91bb-4a329786d178/resource/8d5d8a77-d8bf-425d-a9f9-2514d751e84e/download/legenda-siruta.xml)) | CC-BY-4.0 | 2026-10-16T16:46:28Z | `6a246fddfd8ce881ff37af5e667550e9d9e7f6c914cd008d0bd8c4fab4a5cfad` |
| `unitate_administrativa_uat.zip` | ANCPI, data.gov.ro „UAT 07.03.2024”, versiunea 187 ([resursă](https://data.gov.ro/dataset/37639a5a-64d0-4682-b8ab-f7188a28ae8e/resource/7169c5ce-6a88-4fdf-8b78-03069bc09510/download/unitate_administrativa_uat.zip)) | CC-BY-4.0 | 2026-10-16T16:46:49Z | `7aa770013e1b1962c50824f1b3dfb5178e4e9160dab6183655469b7cbe63fb83` |
| `unitate_administrativa_judet.zip` | ANCPI, același set | CC-BY-4.0 | 2026-10-16T16:46:51Z | `bde384bbb5e967ed1695fc2d4bf592f6c94ba79748164caef1ce07de9ab177d0` |
| `unitate_administrativa_tara.zip` | ANCPI, același set | CC-BY-4.0 | 2026-10-16T16:46:52Z | `e7b2c82a0d51fce0e625bb84e6bc4bb959f2f6b3ff931861b88106a00265293b` |
| `descriereclaseobiectecampurishp.xls` | ANCPI, același set (descrierea câmpurilor) | CC-BY-4.0 | 2026-10-16T16:46:53Z | `b6b7152f84211cc2fdba904aa7be58affe3ae14c29fea689e1bc169967a1b0ab` |
| `arcgis_attrs_0.json`, `arcgis_attrs_2000.json` | ANCPI, serviciul ArcGIS „AU Unități administrative” ([layer](https://services-eu1.arcgis.com/tt6hwS9xmcvnRjQC/arcgis/rest/services/AU_Unit%C4%83%C8%9Bi_administrative/FeatureServer/1)) — doar atribute, fără geometrie | CC-BY-4.0 | 2026-10-16T16:50:02Z | `af7c8efd…ba76f18`, `2124a736…2582273` (complet în `SHA256SUMS`) |

## Concluzii

1. **Codurile se potrivesc exact, în ambele sensuri.** Cele 3186 de UAT-uri ANCPI 2024 corespund exact celor 3186 de înregistrări SIRUTA 2026 cu `TIP` 1–6 (40 municipii reședință, 62 municipii, 216 orașe, 2862 comune, 6 sectoare). Cele 42 de județe ANCPI corespund exact celor 42 de înregistrări `TIP` 40. Județul fiecărui UAT (al doilea segment din `localid` ANCPI) coincide cu `SIRSUP` din SIRUTA. Nicio potrivire aproximativă nu a fost folosită.
2. **Denumiri: 26 de diferențe la coduri comune**, doar raportate, neacceptate automat: diacritice lipsă într-una din surse (`CUZA VODA` / `CUZA VODĂ`, `SARMAŞU` / `ORAŞ SĂRMAŞU`), litere diferite (`ŞISEŞTI` / `ŞIŞEŞTI`, `FÂRŢĂŢEŞTI` / `FÂRTĂŢEŞTI`), spații duble în SIRUTA (`MUNICIPIUL  MARGHITA`), cratime (`POPEŞTI LEORDENI` / `POPEŞTI-LEORDENI`). Ambele surse folosesc sedilă (`Ş`, `Ţ`), nu virgulă. Lista completă: secțiunea 4 din raport.
3. **Țara nu are înregistrare în SIRUTA.** Nu există `SIRUTA = 1`; județele au toate `SIRSUP = 1`, iar ANCPI dă țării `natcode = 1`. Codul 1 apare deci ca referință în ambele surse, nu ca înregistrare SIRUTA.
4. **București are ierarhii diferite în cele două surse.** SIRUTA: 403 `MUNICIPIUL BUCUREŞTI` (`TIP` 40, nivel județ) → 179132 `MUNICIPIUL BUCUREŞTI` (`TIP` 9, `NIV` 2) → sectoarele 179141–179196 (`TIP` 6, `NIV` 3, `SIRSUP` 179132). ANCPI: sectoarele au poligoane proprii, cu părintele direct 403; pentru 179132 nu există poligon.
5. **Geometriile ANCPI 2024 sunt curate.** Toate valide (`ST_IsValid`) la toate nivelurile, deci nicio reparare. Coloana `geom_valid` a fost totuși calculată separat, cu originalul păstrat în `geom`. Între UAT-urile vecine: 9295 de frontiere comune (linie), 69 de atingeri într-un punct, **0 intersecții cu suprafață pozitivă**. Goluri UAT față de județ: 2,7 m² în total (maxim 2,6 m², Mehedinți); depășiri: 2,8 m². Județe față de țară: 0. Proiecția e Stereo70 (EPSG:3844).
6. **Serviciul ArcGIS are date mai noi decât fișierul din 2024.** Toate cele 3186 de înregistrări au `beginLifespanVersion = 2025-03-24` și `inspireId_versionId = 20250324…`, adică o dată explicită a datelor, la nivel de înregistrare, nu doar a metadatelor. `dataLastEditDate` al layer-ului e 2026-01-19. Codurile și denumirile coincid cu fișierul 2024.
   - Ariile diferă la toate UAT-urile. La 3167 diferența e sub 1 ha (sub ±190 ppm), probabil din calculul ariei în serviciu (`Shape__Area`) sau din precizia coordonatelor; nu dovedește singură o schimbare de geometrie.
   - **La 9 perechi de UAT-uri vecine diferențele sunt de 1 ha – 219 ha și se compensează între vecini** (de exemplu Bogdana +219 ha / Bogdănița −219 ha, Ion Creangă / Poienari ±72 ha, Budești / Băiuț ±46 ha). Asta indică frontiere modificate între martie 2024 și martie 2025.
7. **Datum.** PostGIS transformă EPSG:3844 cu parametrii `+towgs84=2.329,-147.042,-92.08,0.309,-0.325,-0.497,5.69`. Un punct de control (Cluj-Napoca: 23.6012 E, 46.7761 N) e plauzibil, dar asta nu e o verificare sub 10 m; comparația cu serviciul ArcGIS ar avea sens doar cu o transformare de datum explicită în cerere.
8. **`shp2pgsql` din imaginea PostGIS Alpine nu pornește** (lipsește `libintl.so.8`). Pentru inspecție a fost instalat `libintl` în containerul temporar. Importul din G.1c nu se poate baza pe `shp2pgsql` din containerul DB de producție.

## De decis pentru G.1b–G.1c

- **Sursa poligoanelor:** fișierul ANCPI 2024 (descărcare simplă, sumă de control stabilă, verificat mai sus) sau exportul serviciului ArcGIS (date 2025-03-24, cu frontiere actualizate, dar export prin interogări paginate, de inspectat la fel înainte de folosire).
- **București:** sectoarele direct sub județul 403 (ca la ANCPI), sau cu 179132 ca nivel intermediar fără poligon propriu.
- **Țara:** folosirea codului 1 (referit de ambele surse) ca identificator, sau un identificator propriu, separat de SIRUTA.
- **Denumirile afișate:** din SIRUTA (sursa oficială a nomenclatorului) sau din ANCPI; transformarea sedilei în virgulă (`Ş` → `Ș`) și curățarea spațiilor duble sunt transformări de afișare, de decis explicit.
