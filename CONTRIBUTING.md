# Contribuție la Validez

Mulțumim pentru interesul de a contribui la Validez — un proiect civic open-source pentru validarea și prioritizarea informațiilor de interes public.

## Licență

Validez este licențiat sub **GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later)**. Orice contribuție trimisă către acest repository este acceptată sub aceeași licență. Detalii complete: `LICENSE`, [ADR-0005](docs/adr/0005-project-license.md).

Validez nu adoptă în prezent dual licensing sau o strategie de relicențiere proprietară. O eventuală schimbare viitoare în această direcție ar constitui o schimbare majoră de guvernanță și ar necesita o decizie separată, documentată explicit.

## Developer Certificate of Origin (DCO)

Toate contribuțiile externe trebuie să fie **semnate** conform [Developer Certificate of Origin](https://developercertificate.org/) (DCO). DCO e o declarație simplă a contributorului că are dreptul de a trimite codul respectiv în proiect — nu o cesiune de drepturi și nu conferă proiectului dreptul de a relicenția contribuțiile sub o licență proprietară.

### Cum semnezi un commit

Adaugă `-s` (sau `--signoff`) la commit:

```bash
git commit -s -m "mesajul commit-ului"
```

Asta adaugă automat la finalul mesajului de commit o linie de forma:

```
Signed-off-by: Nume Prenume <email@exemplu.com>
```

Numele și emailul trebuie să corespundă configurării tale git (`git config user.name` / `git config user.email`) — nu pseudonime sau identități anonime.

### Ce confirmi prin semnătura DCO

Prin adăugarea liniei `Signed-off-by`, confirmi textul integral al [Developer Certificate of Origin](https://developercertificate.org/), în esență că:

- ai creat contribuția și ai dreptul de a o trimite sub licența proiectului, **sau**
- contribuția se bazează pe o lucrare anterioară compatibilă ca licență, pe care ai dreptul să o trimiți, **sau**
- contribuția ți-a fost oferită de altcineva care a certificat cele de mai sus, și n-ai modificat-o.

### Pull request-uri fără DCO

Un pull request cu commit-uri nesemnate nu poate fi acceptat până când toate commit-urile din el au `Signed-off-by`. Dacă ai uitat semnătura, poți corecta ulterior istoricul local (ex. `git commit --amend -s` pentru ultimul commit, sau `git rebase --signoff` pentru mai multe) și forța push pe branch-ul tău înainte de merge.

## Cum contribui

1. Deschide un issue înainte de o contribuție mare (schimbare de model de date, decizie de arhitectură) — pentru schimbări arhitecturale, vezi mai întâi `docs/adr/` ca să nu contrazici o decizie deja luată; propune-o ca ADR nou dacă vrei să o reconsideri.
2. Fork + branch dedicat pentru fiecare contribuție.
3. Commit-uri mici, mesaje clare, toate semnate cu `-s`.
4. Deschide un pull request — descrie ce rezolvă și de ce.

## Cod de conduită

Validez e un proiect civic — discuțiile, issue-urile și pull request-urile ar trebui să reflecte asta: respect, bună-credință, argumente pe fond. Comportament abuziv sau hărțuire nu sunt tolerate.
