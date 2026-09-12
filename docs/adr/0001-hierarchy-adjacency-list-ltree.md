# ADR-0001: Hierarchy — adjacency list + PostgreSQL ltree

**Status:** Accepted
**Date:** 2026-09-12

## Context

Validez organizează informația într-un arbore de categorii (ex. `Drumuri > Cluj > Calitate > DN1`), cu operații frecvente de: interogare subarbore, interogare strămoși, și mutare de noduri (reorganizare admin). Trebuie ales modelul de date pentru `TreeNode`.

## Decision

`TreeNode` folosește `parent_id` ca relație fundamentală, plus o coloană `path` de tip PostgreSQL `ltree` (indexată GiST) pentru interogări rapide de subarbore. `id` este ULID, nu bazat pe nume.

## Rationale

- Interogare subarbore: `WHERE path <@ '1.10.25.73'`. Interogare strămoși: `WHERE path @> '1.10.25.73.104'`. Ambele rapide cu index GiST.
- Mutarea unui subarbore se face atomic, într-o singură tranzacție: verificare că noul părinte nu e descendent → schimbare `parent_id` → recalculare `path` pe subarbore → commit/rollback.
- `id` stabil (ULID) + `path` bazat pe identificatori interni, nu pe nume — redenumirea unui nod (`Calitate` → `Starea drumurilor`) nu afectează `path`-ul niciunui descendent.

## Consequences

### Positive
- Interogări de subarbore și strămoși rapide și simple, cu un singur index.
- Mutare de noduri robustă, atomică, fără renumerotare fragilă.
- Redenumirea unui nod e ieftină (nu propagă).

### Negative / Trade-offs
- Mutarea unui subarbore tot necesită actualizarea `path`-ului tuturor descendenților — nu e O(1) magic, doar atomic și simplu față de alternativa respinsă.
- Dependență de PostgreSQL (extensia `ltree`) — nu portabil trivial pe alt RDBMS fără echivalent.

## Alternatives considered

- **Nested Set (`lft`/`rgt`)** — respins. Mutarea unui subarbore necesită renumerotarea unui număr mare de rânduri, fragil sub concurență (un request eșuat la mijloc poate corupe structura).
- **Adjacency list simplu, fără `path` materializat** — respins ca unică soluție. Fără `ltree`/`path`, interogarea unui subarbore întreg necesită recursive CTE la fiecare citire, mai costisitor la scară.

## Security / Transparency implications

N/A pentru acest ADR.

## References

- [[docs/domain.puml]] — `TreeNode`
- [[docs/design_considered_aspects.md]] §1
