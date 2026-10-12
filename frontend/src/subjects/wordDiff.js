// Diferența pe cuvinte între două texte (LCS), pentru reviziile subiectului (ADR-0007 §4).
// Spațiile sunt păstrate ca token-uri, ca textul să se refacă exact. Peste praguri nu calculăm
// (tabelul LCS e O(n·m) în timp și memorie): apelantul afișează cele două versiuni separat.

export const MAX_TOKENS = 4000;
export const MAX_CELLS = 2_000_000;

const tokenize = (text) => (text ?? '').split(/(\s+)/).filter((t) => t !== '');

/**
 * @returns {Array<{type: 'same'|'added'|'removed', text: string}>|null} segmente, sau null peste praguri
 */
export function diffWords(before, after) {
    const a = tokenize(before);
    const b = tokenize(after);
    const n = a.length;
    const m = b.length;
    if (n > MAX_TOKENS || m > MAX_TOKENS || (n + 1) * (m + 1) > MAX_CELLS) return null;

    // lcs[i][j] = lungimea LCS pentru a[i..], b[j..]; valorile ≤ MAX_TOKENS încap în Uint16.
    const width = m + 1;
    const lcs = new Uint16Array((n + 1) * width);
    for (let i = n - 1; i >= 0; i -= 1) {
        for (let j = m - 1; j >= 0; j -= 1) {
            lcs[i * width + j] = a[i] === b[j]
                ? lcs[(i + 1) * width + j + 1] + 1
                : Math.max(lcs[(i + 1) * width + j], lcs[i * width + j + 1]);
        }
    }

    const segments = [];
    const push = (type, text) => {
        const last = segments[segments.length - 1];
        if (last && last.type === type) last.text += text;
        else segments.push({type, text});
    };
    let i = 0;
    let j = 0;
    while (i < n && j < m) {
        if (a[i] === b[j]) { push('same', a[i]); i += 1; j += 1; }
        else if (lcs[(i + 1) * width + j] >= lcs[i * width + j + 1]) { push('removed', a[i]); i += 1; }
        else { push('added', b[j]); j += 1; }
    }
    while (i < n) { push('removed', a[i]); i += 1; }
    while (j < m) { push('added', b[j]); j += 1; }

    return segments;
}

/** Doar spațiile diferă? Atunci arătăm textul ca neschimbat. */
export const onlyWhitespaceChanged = (segments) => segments.every((s) => s.type === 'same' || s.text.trim() === '');
