import {useCallback, useEffect, useState} from 'react';

// Subiectele strânse cu ✕ în liste (decizie 20.11.2026): doar în browser, doar id-urile, fără server și fără cont.
// Menționat în Politica de confidențialitate („Cookie-uri și stocare locală”) și în CookieNotice.
const KEY = 'validez.collapsedSubjects.v1';
const MAX = 300;
const EVENT = 'validez:collapsed-subjects';

function read() {
    try {
        const ids = JSON.parse(localStorage.getItem(KEY) ?? '[]');
        return Array.isArray(ids) ? ids.filter((id) => typeof id === 'string') : [];
    } catch {
        return [];
    }
}

function write(ids) {
    try { localStorage.setItem(KEY, JSON.stringify(ids.slice(-MAX))); } catch { /* Fără stocare, strângerea ține doar până la reîncărcare. */ }
    window.dispatchEvent(new Event(EVENT));
}

/** {isCollapsed(id), toggle(id), expand(ids)}; listele deschise simultan (și alte taburi) rămân sincronizate. */
export default function useCollapsedSubjects() {
    const [ids, setIds] = useState(read);
    useEffect(() => {
        const sync = () => setIds(read());
        window.addEventListener(EVENT, sync);
        window.addEventListener('storage', sync);
        return () => { window.removeEventListener(EVENT, sync); window.removeEventListener('storage', sync); };
    }, []);
    const toggle = useCallback((id) => {
        const current = read();
        const next = current.includes(id) ? current.filter((x) => x !== id) : [...current, id];
        setIds(next);
        write(next);
    }, []);
    const isCollapsed = useCallback((id) => ids.includes(id), [ids]);
    /** „Arată-le pe toate”: redeschide doar subiectele date (cele de pe pagina curentă). */
    const expand = useCallback((list) => {
        const next = read().filter((x) => !list.includes(x));
        setIds(next);
        write(next);
    }, []);

    return {isCollapsed, toggle, expand};
}
