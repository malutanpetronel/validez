# ADR-0004: Trust score and anti-Sybil configuration is version-controlled

**Status:** Accepted
**Date:** 2026-09-12

## Context

Validez urmează să fie publicat cu cod sursă public (GitHub), coerent cu premisa arhitecturală a proiectului: verificare publică, Merkle Tree, witness servers — oricine trebuie să poată verifica, nu doar avea încredere. Întrebarea: dacă pragurile/ponderile anti-Sybil (`User.trustScore` și euristicile asociate) sunt publice în cod, nu oferă asta o hartă exactă unui atacator pentru a ocoli detecția?

## Decision

**Trust score configuration is code, not mutable runtime state.**

1. Formula, ponderile și pragurile generale anti-Sybil sunt publice, versionate în `backend/config/trust_score.yaml`, și intră în producție exclusiv prin deploy (commit/PR → CI → deploy) — nu editabile dintr-un admin panel conectat direct la DB.
2. Separare explicită: configurația publică (politica) vs. semnalele operaționale sensibile (telemetria live). Datele de apărare curente — IP-uri suspecte temporare, throttling activ sub atac, indicatori de compromitere, fingerprint-uri individuale — nu sunt publice și nu stau în acest repo.
3. `TrustScore` e o valoare calculată (`TrustScoreCalculation`), legată explicit de `trust_score_version` + `config_commit` + `calculated_at` — nu un număr static persistat și mutabil arbitrar.
4. `TrustConfigSnapshot` înregistrează, la fiecare deploy, exact ce configurație a intrat în producție (`git_commit`, `config_hash`, `algorithm_version`, `deployed_at`, `deployed_by`, `environment`) — afișabil public.
5. Orice override runtime e explicit, temporar, auditat prin `SecurityOverride` (`old_value`/`new_value`/`reason`/`created_by`/`expires_at`/`approved_by`), niciodată o modificare tăcută.

## Rationale

- Security-through-obscurity nu e argumentul: ascunderea pragurilor nu oprește un atacator Sybil patient, care poate reconstrui logica empiric prin probing, indiferent dacă pragurile stau în cod sau în DB.
- Problema reală era alta: dacă pragurile sunt editabile din DB fără urmă publică, nu există garanția că ce se vede pe GitHub e ce rulează efectiv în producție. `TrustConfigSnapshot` + `SecurityOverride` rezolvă asta prin auditabilitate, nu prin secret.
- Semnalele de device/network au greutate deliberat redusă, nu eliminate — fingerprinting-ul ca pilon central are implicații de privacy și multe false positive-uri legitime (familii, birouri, școli, VPN-uri, telefoane comune).
- Rezistența reală la Sybil vine din costul de a simula coerent un utilizator legitim pe termen lung (istoric consistent, comportament temporal natural, participare validă repetată), nu din secretul unui prag static.

## Consequences

### Positive
- Audit trail public complet pentru configurația anti-Sybil.
- Imposibil ca producția să difere tăcut de ce e pe GitHub.
- Separare curată între ce e transparent (politica) și ce rămâne protejat (telemetria operațională live).

### Negative / Trade-offs
- Orice ajustare de prag necesită un deploy, nu un click — friction intenționată, dar și reacție mai lentă la un atac activ (de aici `SecurityOverride` ca supapă explicit auditată).
- De implementat: job de deploy care calculează `config_hash` din `trust_score.yaml` și scrie snapshot-ul; endpoint public care afișează snapshot-ul activ.

## Alternatives considered

- **Praguri hardcodate direct în logica de business** — respins. Greu de auditat, schimbări necontrolate, imposibil de versionat curat separat de restul codului.
- **Praguri configurabile live din DB/admin panel** — respins. Permite modificare silențioasă, fără urmă publică — exact ce trebuie evitat într-un proiect civic de transparență.
- **Ascunderea completă a formulei (repo privat pentru acest modul)** — respins. Contrazice premisa proiectului; nu oferă protecție reală împotriva unui atacator care poate aproxima modelul prin probing.

## Security / Transparency implications

Aceasta e explicit o decizie de transparență/securitate, nu doar tehnică — vezi secțiunea Rationale. Ordinea de prioritate a semnalelor (vezi `trust_score.yaml`):

```
Trust / anti-Sybil
├── vechime cont
├── verificare email / telefon
├── istoric participare
├── comportament temporal
├── pattern-uri de vot
├── rate limits
├── relații între conturi
├── semnale device/network cu greutate redusă
└── review manual pentru cazuri importante
```

## References

- `backend/config/trust_score.yaml`
- [[docs/domain.puml]] — `User`, `TrustScoreCalculation`, `TrustConfigSnapshot`, `SecurityOverride`
- [[docs/design_considered_aspects.md]] §6
