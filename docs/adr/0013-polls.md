# ADR-0013: Sondaje cu variante de răspuns definite pe subiect

**Status:** Proposed
**Date:** 2026-11-03

## Context

Tipurile de vot existente ([ADR-0003](0003-voting-model.md), [ADR-0007](0007-votes-revisions-and-privacy.md)) au răspunsuri fixe, cu etichetele în FE (`frontend/src/subjects/labels.js`): Validez/Invalidez, Susțin/Nu susțin, Corect/Greșit, Importanță 1–5. Pentru sondaje (de exemplu un nod „Sondaje” sau o întrebare sub „Drumuri > Cluj”) e nevoie de 3–5 sau mai multe variante de răspuns, formulate pentru fiecare subiect.

Modelul actual ajută: un vot este `(subiect, tip, revizie, valoare întreagă)`, iar `VoteResult.currentCounts` numără deja pe valori. Un sondaj cu N variante înseamnă valori `0…N-1` plus o etichetă pentru fiecare.

Decizii ale lui Petro (03.11.2026): sondajele le creează doar adminii; un singur răspuns per utilizator; rezultatele se văd după vot; sondajul are dată de închidere.

## Decizie

1. **Tip de subiect nou `POLL` (Sondaj)**, creat doar de admini (ca `PROJECT`/`PROMISE`). `DefaultVoteTypes::forSubjectType(POLL)` = `[CHOICE]`. Tipul `POLL` nu e legat de un nod anume: nodul „Sondaje” e o categorie obișnuită, iar un sondaj poate sta oriunde în arbore.
2. **Variantele de răspuns** se definesc pe subiect: între 2 și 20, fiecare cu o etichetă de cel mult 120 de caractere, unice în sondaj (fără diferențe de majuscule sau spații). Ordinea lor e cea stabilită de admin; valoarea votului este poziția variantei (`0…N-1`). Se țin ca listă JSON pe subiect și în instantaneul reviziei (`poll_options`), ca titlul și descrierea.
3. **Tip de vot nou `CHOICE`**, un rând în `vote_type` cu intervalul `0–19` (maximul global). `VoteService` verifică în plus că valoarea e mai mică decât numărul de variante ale subiectului. Un singur vot per utilizator, ca la celelalte tipuri.
4. **Întrebarea și variantele se blochează la primul vot.** Cât timp sondajul nu are voturi, adminul poate modifica titlul, descrierea și variantele. După primul vot, acestea nu se mai pot schimba (răspuns 409): o variantă redenumită ar schimba sensul votului deja dat, iar reconfirmarea din ADR-0007 nu are sens la un sondaj. Un sondaj greșit se închide și se creează altul.
5. **Data de închidere (`closesAt`) este obligatorie** la `POLL` și o stabilește adminul. Se poate muta (prelungire sau scurtare) cât sondajul e deschis. Mutarea nu creează revizie, pentru că nu schimbă sensul votului, dar apare în istoricul public. După `closesAt`, sau dacă adminul trece sondajul în stadiul `CLOSED`, serverul refuză votul și schimbarea votului; ora se compară pe server, nu pe dispozitiv.
6. **Rezultatele se văd după vot.** Pentru `POLL` deschis, API-ul (detaliul subiectului, lista, shell-ul SEO, `og:description`) trimite numărătoarea pe variante doar utilizatorului care a votat pe sondaj. Ceilalți, inclusiv vizitatorii, văd doar numărul total de participanți și data de închidere. După închidere, rezultatele sunt publice pentru toți. Administratorii văd rezultatele ca toți ceilalți (după vot sau la închidere).
7. **Schimbarea votului** e permisă până la închidere; **retragerea** rămâne permisă oricând, ca în ADR-0007 §5 (date personale). Cine a votat și a văzut rezultatele își poate schimba votul; e un compromis acceptat, la fel ca la sondajele obișnuite.
8. **Jurnalul de integritate** (ADR-0007) nu se schimbă: commitmenturile randomizate nu dezvăluie valoarea. Orice verificare publică a agregatelor unui `POLL` se publică doar după închidere.
9. **Interfață:** variantele apar ca listă verticală de opțiuni (una sub alta, pe toată lățimea, fără depășire orizontală pe telefon), cu butonul „Votează”. După vot: bare cu procent și număr pe fiecare variantă, votul propriu marcat. Pe subiect se afișează „Se închide pe …” / „Închis pe …”. Formularul adminului are lista de variante (adăugare, ștergere, sus/jos) și data de închidere.
10. **Sesizarea către autorități** ([authority-notifications.md](../authority-notifications.md)) nu se aplică sondajelor.

## Rationale

- Refolosește tabela `vote`, agregatul și jurnalul de integritate; nu apare un al doilea sistem de vot.
- Etichetele variantelor stau în baza de date, pe subiect, pentru că diferă de la un sondaj la altul (spre deosebire de tipurile fixe, cu etichetele în FE).
- Blocarea la primul vot e mai simplă și mai onestă decât revizii cu reconfirmare pentru o întrebare care trebuie să rămână fixă.
- Ascunderea rezultatelor până la vot reduce efectul de turmă (oamenii votează ce pare să câștige).

## Consecințe

### Pozitive
- Sondaje cu oricâte variante între 2 și 20, fără schimbarea modelului de vot.
- Rezultatele se pot verifica la fel ca celelalte voturi.

### Negative / Trade-offs
- Serializarea subiectului depinde de utilizator (rezultate ascunse/vizibile); răspunsurile pentru `POLL` deschis nu se pot pune în cache public.
- Un sondaj formulat greșit nu se mai poate corecta după primul vot, doar închide și înlocui.
- Cine votează doar ca să vadă rezultatele își poate retrage apoi votul.

## Alternatives considered

- **Mai multe răspunsuri per utilizator** („bifează tot ce se aplică”): respins deocamdată; schimbă constrângerea de unicitate și numărătoarea.
- **Variante ca revizie, cu reconfirmare** (ca titlul la ADR-0007): respins; la sondaj reconfirmarea ar goli rezultatele la orice corectură.
- **Câte un `VoteType` per sondaj**: respins; tabela `vote_type` ar crește cu fiecare sondaj, iar intervalul global e suficient cu verificarea din punctul 3.
- **Rezultate mereu vizibile**: respins de Petro.

## Security / Transparency implications

- Rezultatele unui sondaj deschis nu trebuie să apară în niciun răspuns public (API, shell SEO, `og:*`, sitemap, loguri). Testele BE verifică explicit vizitatorul și utilizatorul care nu a votat.
- Votul individual rămâne privat și ștergibil (ADR-0007, ADR-0009).

## References

- [ADR-0003](0003-voting-model.md), [ADR-0007](0007-votes-revisions-and-privacy.md), [ADR-0008](0008-public-urls-slugs-and-indexing.md), [ADR-0009](0009-erasure-registry-for-restores.md)
