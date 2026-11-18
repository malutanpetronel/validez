# ADR-0014: Subiect de tip Problemă propus de AI din fotografie

**Status:** Accepted
**Date:** 2026-11-18

## Context

O sesizare bună pornește aproape mereu de la o fotografie: gunoi abandonat, groapă, trotuar nefinisat, stâlp înclinat. Scrierea titlului și a descrierii și alegerea categoriei durează, mai ales de pe telefon. Un model AI cu vedere poate propune, din fotografie, tipul problemei, titlul, textul sesizării și categoria, iar omul le verifică și le corectează.

ArtaNFT are deja mecanismul (ArtaNFT ADR-0002, `src/Ai/*`, `SetariAi`): doi furnizori în spatele unei interfețe, cheile doar în env, derivată fără metadate, cote și jurnal. Validez îl preia adaptat, cu nume în engleză, ca restul codului. Extragerea într-un pachet comun se decide după ce codul se stabilizează în ambele proiecte.

Decizii ale lui Petro (18.11.2026): deocamdată doar adminii au acces la modul AI; setările API pentru cei doi furnizori se fac din admin, ca la ArtaNFT, împreună cu un prompt de recunoaștere editabil.

## Decizie

1. **Doar adminii (`ROLE_ADMIN`)** pot folosi modul AI, la crearea unui subiect. Pentru ceilalți utilizatori, butonul nu apare, iar endpointul răspunde 403.
2. **Doi furnizori** în spatele interfeței `App\Ai\ImageAnalyzer::analyze(bytes, mime, prompt, schema, model): AiResult`: `AnthropicAnalyzer` și `OpenAiAnalyzer`, pe `symfony/http-client`, fără SDK-uri. Furnizorul activ implicit este Anthropic.
3. **Cheile API stau doar în env-ul BE de pe server** (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`), nu în DB și nu în repository. Panoul arată doar „configurată ✓ / lipsă”.
4. **Modelele sunt fixe deocamdată**, ca la ArtaNFT: `claude-haiku-5-5` și `gpt-6-luna`. Nu se pot schimba prin API; se schimbă doar din cod, cu migrare.
5. **Setări `AiSettings`** (un singur rând, citit și editat doar de admin): comutatorul global (oprit implicit), furnizorul activ, promptul (cu „resetează la implicit”), limba, prețurile per model în USD / 1M tokeni, limita pe zi per admin (30), plafonul global pe zi (30 de cereri, 1 USD) și plafonul lunar (10 USD). Un model fără preț nu poate fi folosit. În plus, se setează budget limit în consola furnizorilor, ca barieră în afara codului.
6. **Ce se trimite:** varianta de afișare produsă de reprocesarea obișnuită (`ImageProcessor`, ADR-0011) — WebP, latura lungă maximum 1600 px, fără metadate (EXIF/GPS eliminate). Fișierul primit nu pleacă din server și nu se păstrează.
7. **Promptul de recunoaștere** are placeholdere: `{limba}`, `{categorie}` (calea nodului curent), `{categorii}` (căile din subarborele nodului, limitate la 80) și `{data}` (data fotografiei din EXIF, citită în browser, sau „necunoscută”). Promptul implicit cere un ton sobru și factual, fără detalii inventate, transcrierea indiciilor de loc vizibile fără ghicirea adresei, fără identificarea persoanelor și fără transcrierea numerelor de înmatriculare.
8. **Răspuns JSON strict** (toate câmpurile `required`, `additionalProperties: false`): `is_public_problem`, `problem_kind` (listă fixă + `other`), `title` (≤ 90), `description`, `severity` (`LOW`/`MEDIUM`/`HIGH`), `immediate_danger`, `location_clues`, `suggested_category` (un id din `{categorii}` sau gol), `confidence` (0–1), `people_or_plates_visible`. Un răspuns invalid se înregistrează ca eroare și nu ajunge în formular.
9. **Sugestia nu salvează nimic.** `POST /api/ai/subject-suggestion` este sincron, iar adminul așteaptă în formular. Câmpurile goale se completează, cele deja scrise rămân, iar totul rămâne editabil. Subiectul se creează doar la „Salvează”, prin fluxul obișnuit; tipul propus este `ISSUE`.
10. **Locul exact nu vine din AI.** Pinul propus vine din GPS-ul EXIF al fotografiei, citit **în browser** înainte de upload, cu o sursă nouă în `LocationSource`, `PHOTO`. Dacă fotografia nu are GPS, se oferă locația dispozitivului, ca până acum. Adminul confirmă sau mută pinul. Indiciile de loc din răspunsul AI apar ca text lângă hartă. Serverul elimină în continuare metadatele la reprocesare.
11. **Fotografia devine prima fotografie a subiectului** după salvare, prin fluxul media existent (ADR-0011), cu aceleași reguli. Dacă `people_or_plates_visible` este adevărat, formularul afișează un avertisment înainte de salvare.
12. **Jurnal `AiRequest`:** furnizor, model, tokeni de intrare și ieșire, cost estimat USD, durată, status, eroare, admin, hash-ul cererii. Este sursa pentru cote, costuri și depanare. Nu păstrează fotografia și nici textul răspunsului după procesare. Aceeași combinație imagine + prompt + model nu se trimite de două ori: se întoarce rezultatul din cache-ul pe hash, păstrat 24 de ore.
13. **Documentele legale** se actualizează înaintea codului: în `PrivacyPage` apar furnizorul AI ca împuternicit, citirea locală a coordonatelor din fotografie și avertismentul despre persoane terțe.

## Consecințe

- Costul per sugestie este de ordinul a 0,05–0,1 cenți cu Haiku 5.5; plafoanele în USD rămân obligatorii ca protecție la schimbări de preț.
- Pe live e nevoie de cheile în env-ul BE și de comutatorul pornit din admin; pașii sunt în `Deploy.md`.
- Extinderea către utilizatori (cote per utilizator, opt-in, posibil abonament) e o decizie separată, cu ADR propriu și actualizarea documentelor legale.
- Fotografia trimisă la AI poate conține persoane sau numere de înmatriculare; prompturile cer să nu fie identificate, dar trimiterea rămâne o prelucrare și trebuie descrisă în confidențialitate.
- Termenii furnizorilor privind retenția și transferul în afara UE se recitesc la verificarea juridică (Step L.4).

## Alternatives considered

- **Locul stabilit de AI din imagine:** respins — nu poate fi stabilit cu certitudine, iar un pin greșit ar ajunge în revizia subiectului.
- **Citirea GPS-ului pe server:** respinsă — serverul elimină metadatele, iar citirea locală nu trimite coordonatele dacă adminul nu le confirmă.
- **Job asincron, ca la ArtaNFT:** respins pentru Validez — sugestia e cerută în timp ce formularul e deschis.
- **Geocodarea automată a indiciilor de loc (Nominatim):** amânată ca pas opțional (AI.8).

## Security / Transparency implications

Cheile nu ies niciodată din env și nu apar în răspunsuri sau în loguri. Erorile furnizorilor sunt scurtate și nu conțin antetele cererii. Endpointul are limita pe admin și plafonul global, verificate înaintea apelului.

## References

- [ADR-0011](0011-subject-media-links-and-location.md) — media și localizare
- [ADR-0012](0012-administrative-geography-as-separate-dimension.md) — pinul și aria
- ArtaNFT ADR-0002 — descriere AI din fotografia lucrării
