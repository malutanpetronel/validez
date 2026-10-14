# ADR-0009: Registrul ștergerilor pentru restaurare

**Status:** Accepted
**Date:** 2026-10-14

## Context

[ADR-0007](0007-votes-revisions-and-privacy.md) cere ca o restaurare din backup să nu reactiveze un consimțământ retras: „un registru protejat al cererilor de ștergere, cu date minime și retenție proprie, în afara setului restaurat”, reaplicat înainte de redeschiderea traficului (punctul deschis 2).

Backupul bazei este un `pg_dump` zilnic, păstrat cel mult 30 de zile (`Deploy.md`, secțiunea E; pagina de confidențialitate, „Copii de siguranță”). Fără registru, restaurarea unei copii de acum N zile readuce acordurile retrase și voturile șterse în ultimele N zile.

Alternativa analizată — o procedură manuală după restaurare, pe baza cererilor primite — nu acoperă retragerile făcute direct din aplicație („Voturile mele”), care nu lasă nicio urmă în afara bazei.

## Decision

1. **Registru într-un fișier, în afara bazei.** `App\Vote\ErasureRegistry` scrie în `backend/var/erasure-registry/registry.jsonl`; pe server, `/var/www/validez-be/shared/backend/var/erasure-registry/` (persistent între release-uri, neinclus în `pg_dump`). Calea se poate schimba cu `ERASURE_REGISTRY_DIR`.
2. **Date minime.** O linie JSON per retragere: versiunea formatului, id-ul utilizatorului (ULID), acțiunea (`consent_withdrawn`) și momentul, în UTC, cu microsecunde. Fără email, subiecte, tipuri sau valori de vot.
3. **Retenție proprie: 45 de zile** (30 de zile cât trăiesc copiile bazei, plus marjă). Intrările mai vechi se elimină la fiecare scriere. Fișierul are drepturi `0640`; scrierea folosește `flock` și `fsync`.
4. **Notare după commit.** `VoteService::withdrawConsent` notează retragerea numai după commitul tranzacției, cu momentul retragerii acordului. O intrare scrisă înainte de commit ar putea corespunde unei tranzacții anulate și ar retrage, la o restaurare, un acord încă valabil. Dacă notarea eșuează, retragerea rămâne valabilă, iar eroarea se loghează ca `critical`.
5. **Reaplicare: `app:votes:reapply-erasures [--dry-run]`.** Parcurge registrul în ordine cronologică. Pentru o retragere de la momentul T, retrage acordul activ numai dacă a fost dat înainte de T; un acord dat din nou după T (prezent deja în copia restaurată) rămâne neatins. Fără acord activ, elimină voturile și istoricul privat rămase. Reaplicarea folosește aceeași cale ca retragerea obișnuită (evenimente fără referință la persoană, agregate recalculate), nu adaugă intrări noi și e idempotentă. Utilizatorii absenți din copia restaurată sunt ignorați. Comanda afișează doar totaluri.
6. **Ordinea la restaurare** (`Deploy.md`, secțiunea E2): nginx oprit → baza restaurată → poarta de baze de date (`bin/deploy-db.sh`: migrări, roluri, verificare runtime) → `app:votes:reapply-erasures` → `app:votes:verify` → nginx pornit.
7. **Ștergerea contului**, când va exista, notează și ea operația în registru, după commit (o acțiune nouă în același format).

## Consequences

- O restaurare a bazei nu reactivează consimțăminte retrase după realizarea copiei, nici voturile asociate lor.
- Momentul din bază are secunde întregi (Doctrine `datetimetz`), cel din registru microsecunde. Comparația e conservatoare: un acord dat din nou în aceeași secundă cu retragerea poate fi retras la reaplicare (pierdere de date în favoarea confidențialității, niciodată invers).
- Registrul conține identificatori de utilizator, deci este el însuși o prelucrare de date personale, limitată la scopul de mai sus și la 45 de zile.
- **Limită:** registrul supraviețuiește restaurării bazei, nu restaurării întregului server dintr-un snapshot, care l-ar readuce la starea din momentul snapshotului. Dacă se folosesc snapshoturi de server, retenția lor trebuie să fie de cel mult 30 de zile, iar o restaurare completă se tratează separat.
- O retragere notată eșuat (doar `critical` în log) nu va fi reaplicată la o restaurare ulterioară; logurile `critical` se urmăresc.
- Retragerea unui vot individual (fără retragerea acordului) nu se notează: după o restaurare, votul poate reapărea, ca orice altă modificare ulterioară copiei. Utilizatorul îl poate retrage din nou; notarea lui ar lega persoana de subiect în afara bazei.

## Alternatives Considered

- **Procedură manuală după restaurare** — respinsă: retragerile din aplicație nu lasă urme în afara bazei.
- **Tabelă în aceeași bază** — respinsă: ar fi restaurată împreună cu datele pe care trebuie să le corecteze.
- **Bază separată** — respinsă deocamdată: aceleași garanții, cu o componentă în plus de administrat și de exclus din backup.
- **Crypto-shredding** — amânat (ADR-0007): cere chei separate, imposibil de recuperat din backupuri.

## References

- [ADR-0007](0007-votes-revisions-and-privacy.md) — voturi, date private, backupuri (punctul deschis 2)
- `backend/src/Vote/ErasureRegistry.php`, `backend/src/Command/ReapplyErasuresCommand.php`
- `backend/tests/Vote/ErasureRegistryTest.php`, `backend/tests/Vote/ReapplyErasuresTest.php`
