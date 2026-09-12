<p align="center">
  <img src="assets/logo.png" width="120" alt="Validez logo">
</p>

# Validez

Platformă colaborativă pentru validarea și prioritizarea informațiilor, organizate într-un arbore ierarhic de categorii (ex: `Drumuri > Cluj > Calitate > DN1`).

## Concept

Arborele (`TreeNode`) reprezintă exclusiv structura ierarhică. Conținutul votabil/comentabil (`CivicSubject`) e asociat unui nod — un nod poate avea mai multe subiecte (ex. mai multe sesizări sub `Drumuri > Cluj > Calitate`). Un subiect poate fi de tip `ISSUE`, `PROPOSAL`, `PROJECT`, `PROMISE`, `ELECTORAL_EVALUATION`, `PETITION`, `TOPIC_EVALUATION` etc.

Fiecare subiect poate conține imagine, galerie foto, video, documente PDF, linkuri externe, descriere și coordonate GPS. Utilizatorii autentificați pot vota un subiect cu unul sau mai multe tipuri de vot active simultan (configurabile per subiect):

- **Da / Nu**
- **Validez / Invalidez**
- **Importanță** (1-5)

**Prioritatea nu se votează** — e un scor calculat de sistem din importanță votată, număr validări, utilizatori afectați, timp de existență, severitate etc.

Rezultatele agregate (procent validare, importanță medie, evoluție în timp) sunt afișate public per (subiect, tip de vot).

## Domenii de utilizare

Drumuri, Mediu, Educație, Sănătate, Turism, Patrimoniu, Agricultură, Administrație publică, Proiecte locale — orice domeniu poate fi mapat pe aceeași structură de arbore.

## Arhitectură

- **Frontend**: React / React Native
- **Backend**: Symfony, API REST/GraphQL
- **Stocare media**: local sau S3-compatible
- **Autentificare**: JWT/OAuth2 (email/parolă, Google, Facebook, Apple)

### Tree structure: PostgreSQL adjacency list + ltree

Validez folosește `parent_id` ca relație fundamentală între noduri și PostgreSQL `ltree` pentru navigarea rapidă a ierarhiei și interogarea subarborilor (`path <@ '...'`, index GiST).

Nu folosim Nested Set deoarece operațiile de mutare a subarborilor necesită modificarea unui număr mare de valori `lft`/`rgt` și complică operațiile concurente. `parent_id` permite mutări simple și robuste (o singură tranzacție), iar `ltree` oferă indexare și interogare eficientă pentru subarbori. `id` e ULID, nu bazat pe nume — redenumirea unui nod nu afectează `path`-ul.

### Integritate voturi — MVP vs. Faza 2

**MVP**: semnătură pe device (public/private key) + `VoteEvent` append-only cu hash chain simplu (`previous_hash → hash`). Detectează alterarea unui vot, fără infrastructura complexă a unui Merkle Tree.

**Faza 2** (după trafic real): agregare în Merkle Tree, Merkle Root, witness servers, verificare publică (vezi `docs/security.puml`).

**Notă importantă**: semnăturile dovedesc că un vot n-a fost alterat ulterior, dar **nu rezolvă Sybil attacks**. Anti-abuse e o problemă separată de identitate/trust — vezi `docs/design_considered_aspects.md` §3-4.

Detalii tehnice complete în `docs/`:

| Fișier | Conținut |
|---|---|
| `docs/architecture.puml` | Arhitectura tehnică — MVP (hash chain) + Faza 2 (Merkle Tree, witness) |
| `docs/domain.puml` | Entități și relații (User, TreeNode, CivicSubject, Vote, Comment, Flag...) |
| `docs/workflow.puml` | Fluxul utilizatorului: navigare → autentificare → vot → rezultate |
| `docs/security.puml` | Semnături, Merkle Tree, verificare integritate |
| `docs/design_considered_aspects.md` | Deciziile de arhitectură, alternativele respinse și motivul |
| `docs/adr/` | Architecture Decision Records — vezi indexul din `docs/adr/README.md` |

PhpStorm afișează nativ fișierele `.puml` dacă e instalat pluginul PlantUML integration.

## Structură proiect

```
validez/
├── README.md
├── backend/
│   └── config/
│       └── trust_score.yaml
├── frontend/
├── docs/
│   ├── architecture.puml
│   ├── domain.puml
│   ├── workflow.puml
│   ├── security.puml
│   ├── design_considered_aspects.md
│   └── adr/
│       ├── README.md
│       ├── 0001-hierarchy-adjacency-list-ltree.md
│       ├── 0002-node-vs-civic-subject.md
│       ├── 0003-voting-model.md
│       ├── 0004-trust-score-configuration.md
│       └── 0005-project-license.md
└── docker/
```

## Status

Faza de proiectare — arhitectură și modelul de date stabilite (v2). Următorul pas: schema DB (migrations) și structura de foldere Symfony în `backend/`.


Copyright (C) 2026 Petronel Laviniu Malutan