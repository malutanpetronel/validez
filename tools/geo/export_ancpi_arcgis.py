#!/usr/bin/env python3
"""Export local al layer-ului ANCPI "AU Unități administrative" (ArcGIS FeatureServer) — G.1, ADR-0012.

Rulează pe laptop; importul aplicației folosește DOAR artefactul produs aici (paginile Esri JSON + manifest.json),
fără dependență de serviciul live.

Geometria vine în proiecția nativă a serviciului (Stereo70, același WKT ca fișierul ANCPI), fără outSR,
fără generalizare (maxAllowableOffset) și fără cuantizare.

Completitudine, verificată în manifest:
  - lista completă de OBJECTID (returnIdsOnly) luată înainte și după export trebuie să fie identică;
  - paginile, unite, conțin fiecare OBJECTID exact o dată, iar nationalCode e unic;
  - dataLastEditDate al layer-ului și versionId-urile datelor nu se schimbă între început și sfârșit.
Orice abatere -> cod de ieșire 1, iar manifestul notează problema.

Utilizare: python3 tools/geo/export_ancpi_arcgis.py backend/var/geo/arcgis-AAAAMMZZ
"""
import datetime
import hashlib
import json
import os
import sys
import time
import urllib.parse
import urllib.request

LAYER = ("https://services-eu1.arcgis.com/tt6hwS9xmcvnRjQC/arcgis/rest/services/"
         "AU_Unit%C4%83%C8%9Bi_administrative/FeatureServer/1")
PAGE = 200
FIELDS = ["OBJECTID", "nationalCode", "name_1", "nationalLevelName_1", "upperLevelUnit",
          "beginLifespanVersion", "endLifespanVersion", "inspireId_localId", "inspireId_versionId", "Shape__Area"]


def get(url, params=None, tries=4):
    if params:
        url = url + "?" + urllib.parse.urlencode(params)
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": "validez-geo-export/1"})
            with urllib.request.urlopen(req, timeout=180) as r:
                body = r.read()
            data = json.loads(body)
            if "error" in data:
                raise RuntimeError(f"eroare ArcGIS: {data['error']}")
            return body, data
        except Exception as e:  # rețea / 5xx: reîncercare cu pauză
            if i == tries - 1:
                raise
            print(f"  reîncerc ({e})", file=sys.stderr)
            time.sleep(5 * (i + 1))


def layer_state():
    _, info = get(LAYER, {"f": "json"})
    _, ids = get(LAYER + "/query", {"where": "1=1", "returnIdsOnly": "true", "f": "json"})
    return info.get("editingInfo") or {}, sorted(ids["objectIds"]), info.get("extent", {}).get("spatialReference")


def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def main(out):
    os.makedirs(out, exist_ok=True)
    started = datetime.datetime.now(datetime.UTC).isoformat()
    edit_before, ids_before, sr = layer_state()
    print(f"OBJECTID-uri: {len(ids_before)}; dataLastEditDate: {edit_before.get('dataLastEditDate')}")

    pages, seen, problems, versions, codes = [], {}, [], set(), {}
    for n, start in enumerate(range(0, len(ids_before), PAGE)):
        chunk = ids_before[start:start + PAGE]
        params = {"objectIds": ",".join(map(str, chunk)), "outFields": ",".join(FIELDS), "returnGeometry": "true",
                  "returnZ": "false", "returnM": "false", "f": "json"}
        body, data = get(LAYER + "/query", params)
        name = f"page-{n:03d}.json"
        with open(os.path.join(out, name), "wb") as f:
            f.write(body)
        got = [ft["attributes"]["OBJECTID"] for ft in data["features"]]
        if data.get("exceededTransferLimit"):
            problems.append(f"{name}: exceededTransferLimit")
        if sorted(got) != chunk:
            problems.append(f"{name}: OBJECTID cerute {len(chunk)}, primite {len(got)}")
        for ft in data["features"]:
            a = ft["attributes"]
            if a["OBJECTID"] in seen:
                problems.append(f"OBJECTID {a['OBJECTID']} duplicat ({seen[a['OBJECTID']]}, {name})")
            seen[a["OBJECTID"]] = name
            codes.setdefault(a["nationalCode"], []).append(a["OBJECTID"])
            versions.add((a["inspireId_versionId"] or "")[:8])
            if not ft.get("geometry") or not ft["geometry"].get("rings"):
                problems.append(f"OBJECTID {a['OBJECTID']} fără geometrie")
        pages.append({"file": name, "objectIds": [chunk[0], chunk[-1]], "features": len(got),
                      "bytes": len(body), "sha256": hashlib.sha256(body).hexdigest(),
                      "versionIds": sorted({(ft['attributes']['inspireId_versionId'] or '')[:8] for ft in data["features"]})})
        print(f"  {name}: {len(got)} entități")

    edit_after, ids_after, _ = layer_state()
    if ids_after != ids_before:
        problems.append("lista de OBJECTID s-a schimbat în timpul exportului")
    if edit_after.get("dataLastEditDate") != edit_before.get("dataLastEditDate"):
        problems.append("dataLastEditDate s-a schimbat în timpul exportului")
    if set(seen) != set(ids_before):
        problems.append(f"lipsesc {len(set(ids_before) - set(seen))} OBJECTID")
    dup_codes = {c: o for c, o in codes.items() if len(o) > 1}
    if dup_codes:
        problems.append(f"nationalCode duplicat: {dup_codes}")
    if len(versions) != 1:
        problems.append(f"versiuni de date diferite între entități: {sorted(versions)}")

    manifest = {
        "source": "ANCPI — AU Unități administrative (ArcGIS FeatureServer, layer 1)",
        "license": "CC-BY-4.0",
        "layer_url": LAYER,
        "export_started_utc": started,
        "export_finished_utc": datetime.datetime.now(datetime.UTC).isoformat(),
        "request": {"returnGeometry": True, "outSR": None, "maxAllowableOffset": None, "quantization": None,
                    "page_size": PAGE, "paging": "objectIds din returnIdsOnly", "fields": FIELDS},
        "spatial_reference": sr,
        "editingInfo_before": edit_before, "editingInfo_after": edit_after,
        "feature_count": len(seen), "data_version_ids": sorted(versions),
        "pages": pages, "problems": problems,
    }
    with open(os.path.join(out, "manifest.json"), "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)
    with open(os.path.join(out, "SHA256SUMS"), "w") as f:
        for name in sorted(os.listdir(out)):
            if name != "SHA256SUMS":
                f.write(f"{sha256(os.path.join(out, name))}  {name}\n")
    print(f"Entități: {len(seen)}; versiuni: {sorted(versions)}; probleme: {len(problems)}")
    for p in problems:
        print("  PROBLEMĂ:", p)
    return 1 if problems else 0


if __name__ == "__main__":
    if len(sys.argv) != 2:
        print(__doc__)
        sys.exit(2)
    sys.exit(main(sys.argv[1]))
