# ADR-0005: Project license (AGPL-3.0-or-later)

**Status:** Accepted
**Date:** 2026-09-12

## Context

Validez urmează să fie publicat public (GitHub) — cod sursă vizibil, coerent cu premisa de verificare publică a proiectului (Merkle Tree, witness servers, `TrustConfigSnapshot` — vezi ADR-0004). Pentru un proiect civic de validare/vot, licența nu e formalitate: trebuie să prevină scenariul în care cineva ia codul, rulează o instanță publică (SaaS) cu modificări proprii, și nu e obligat să publice acele modificări — practic un fork proprietar închis al unui proiect civic deschis.

Stack-ul relevant pentru evaluare: **frontend React**, **backend Symfony (PHP)**, eventuale **integrări MCP/API externe** (ex. servicii AI, servicii de verificare telefon/email terțe), și posibile **integrări proprietare** viitoare (ex. API-uri guvernamentale, servicii de plată pentru funcționalități premium).

## Decision

**AGPL-3.0-or-later pentru tot stack-ul** — backend Symfony și frontend React, sub aceeași licență, fără dual licensing planificat. Contribuțiile externe se fac sub Developer Certificate of Origin (DCO), nu CLA.

## Rationale

**De ce AGPL-3.0 e relevant aici, nu MIT/Apache-2.0:** clauza de „network use" a AGPL-3.0 (spre deosebire de GPL simplu) extinde obligația de publicare a sursei și la cazul în care programul modificat rulează ca serviciu accesat prin rețea (SaaS), nu doar când e distribuit ca binar. Exact scenariul de evitat: cineva ia Validez, îl modifică, rulează o instanță publică fără să publice modificările.

**De ce tot stack-ul, nu doar backend-ul:** pentru miza lui Validez (integritate + transparență a *întregului* sistem de vot, nu doar a datelor), un split backend-AGPL/frontend-MIT ar lăsa o portiță — cineva ar putea lua frontendul MIT, îl pune peste un backend proprietar reimplementat, și tehnic n-ar încălca nimic. Precedent direct comparabil: Mastodon (backend Ruby + frontend React-ish) folosește AGPL-3.0 pe tot stack-ul, ca proiect de infrastructură socială/civică.

**MCP-uri și integrări externe — de regulă neafectate, de verificat per caz:** apelarea unui serviciu extern prin API (ex. un MCP server terț, un serviciu de verificare telefon) nu creează de obicei o „operă derivată" în sensul AGPL — serviciul extern rămâne un program separat, nu e „linked" în codul Validez. Excepție de verificat: dacă un SDK/librărie proprietară e inclusă direct în codebase-ul Validez (nu apelată ca serviciu extern), licența acelui SDK trebuie verificată individual pentru compatibilitate cu AGPL.

**„100% civic" ≠ „fără venituri":** AGPL nu interzice modelul de business — interzice doar vinderea dreptului de a ascunde codul derivat. Organizația care operează Validez (ex. WebNou) poate factura legal hosting administrat, instalare pentru primării, suport/SLA, dezvoltări la comandă, training/consultanță — fără ca asta să transforme proiectul într-unul proprietar. Diferența față de un model closed-source e că nimeni nu plătește pentru dreptul de a ascunde modificările.

## Contributor licensing

Validez este un proiect civic open-source și nu adoptă în prezent dual licensing sau o strategie de relicențiere proprietară. Contribuțiile externe vor fi acceptate sub aceeași licență AGPL-3.0-or-later.

Proiectul va utiliza **Developer Certificate of Origin (DCO)** pentru contribuțiile externe — mecanismul `Signed-off-by:` folosit de multe proiecte open-source. Fiecare contributor confirmă astfel că are dreptul de a transmite contribuția în proiect.

DCO nu este utilizat pentru a obține drepturi generale de relicențiere proprietară asupra contribuțiilor — nu e echivalent cu un CLA de cesiune de drepturi. O eventuală schimbare viitoare către dual licensing sau către un model comercial de relicențiere ar constitui o schimbare majoră de guvernanță și licențiere și ar necesita o decizie separată (ADR nou).

## Consequences

### Positive
- Previne exact scenariul de risc: fork proprietar SaaS necontribuit înapoi, pe întregul stack (inclusiv UI).
- Coerent cu restul arhitecturii (verificare publică, transparență radicală).
- Model de venituri (hosting, suport, implementări la comandă) rămâne posibil fără a compromite deschiderea codului.
- Fricțiune minimă pentru contribuitori — DCO (o linie `Signed-off-by:`) e mult mai ușor de acceptat decât un CLA de cesiune.

### Negative / Trade-offs
- Poate descuraja adopția de către entități comerciale sau instituții publice cu politici stricte anti-copyleft, care ar prefera MIT/Apache-2.0.
- Fără CLA, orice relicențiere viitoare (inclusiv un eventual dual-licensing) va necesita fie acordul explicit al fiecărui contribuitor pentru codul lui, fie rescrierea porțiunilor respective — de acceptat ca limitare permanentă, nu de rezolvat ulterior.
- Necesită vigilență continuă la fiecare dependință/integrare nouă (verificare compatibilitate licență SDK-uri proprietare), nu doar o decizie unică la început.

## Alternatives considered

- **MIT / Apache-2.0** — respins ca opțiune implicită; nu protejează scenariul de risc identificat (fork SaaS proprietar necontribuit).
- **AGPL doar pe backend, MIT pe frontend** — respins; lasă o portiță pentru fork-uri de UI peste backend-uri proprietare.
- **Licență proprietară / cod închis** — respins din start, contrazice premisa proiectului.
- **Dual licensing (AGPL + licență comercială) cu CLA** — respins pentru moment; fricțiunea unui CLA pentru contribuitori nu se justifică fără un plan comercial concret. Rămâne o opțiune de reconsiderat explicit, printr-un ADR nou, dacă apare o nevoie reală.

## Security / Transparency implications

Licența e ea însăși un mecanism de transparență: garantează că orice instanță publică derivată din Validez rămâne auditabilă de comunitate, coerent cu `TrustConfigSnapshot` (ADR-0004) — „ce rulează public trebuie să rămână verificabil public", extins de la cod la orice fork rulat ca serviciu.

**Notă separată de licența de cod:** protejarea numelui/mărcii „Validez" (trademark, nu copyright) rămâne un subiect deschis, de tratat eventual într-un ADR propriu — un fork rău-intenționat care se prezintă public tot ca „Validez" (dar cu logică de vot alterată) e un risc de încredere publică distinct de licența codului sursă.

## References

- [[docs/adr/0004-trust-score-configuration.md]]
- [[docs/design_considered_aspects.md]]
- `LICENSE` (text complet AGPL-3.0)
- `CONTRIBUTING.md` (DCO)
