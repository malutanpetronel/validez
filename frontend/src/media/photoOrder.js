/**
 * Ordinea fotografiilor după o mutare: elementul de la `from` ajunge la `to`, restul își păstrează ordinea.
 * Fără mutare (indici egali sau în afara listei) întoarce null - nu se trimite nimic la server.
 */
export function movePhoto(ids, from, to) {
    if (from === to || from < 0 || to < 0 || from >= ids.length || to >= ids.length) return null;
    const next = ids.slice();
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    return next;
}
