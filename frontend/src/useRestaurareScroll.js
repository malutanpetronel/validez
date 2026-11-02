import {useEffect, useLayoutEffect, useRef} from 'react';
import {useLocation, useNavigationType} from 'react-router-dom';

// Poziția de scroll per intrare din istoric (portat din ArtaNFT, pasul 38/47). Listele își încarcă datele asincron,
// deci la „Înapoi” pagina e încă scurtă când se schimbă adresa: poziția se reface abia când pagina e destul de
// înaltă, cu o limită de timp; orice gest al utilizatorului (atingere, rotiță, tastă) oprește încercarea.
// Diferență față de ArtaNFT: arborele (/, /categorie/…) e o singură pagină (ADR-0008) - o selecție în arbore nu
// urcă la început (SubjectPage își aduce singur titlul în vedere), iar adresele canonice (replace) nu mișcă nimic.
const CHEIE = 'validez:scroll';
const LIMITA_MS = 4000;

const citeste = () => {
    try { return JSON.parse(sessionStorage.getItem(CHEIE)) || {}; } catch { return {}; }
};
const scrie = (pozitii) => {
    try { sessionStorage.setItem(CHEIE, JSON.stringify(pozitii)); } catch { /* fără sessionStorage: doar în memorie */ }
};
const inArbore = (path) => path === '/' || path.startsWith('/categorie/');

export default function useRestaurareScroll() {
    const location = useLocation();
    const tip = useNavigationType();
    const pozitii = useRef(citeste());
    // Cheia intrării curente: ascultătorul unic scrie mereu sub ea. Se schimbă în layout effect,
    // înainte de scrollTo-ul paginii noi, ca acel scroll să nu suprascrie poziția paginii vechi.
    const cheieCurenta = useRef(location.key);
    const caleAnterioara = useRef(location.pathname);

    useEffect(() => {
        const vechi = window.history.scrollRestoration;
        if ('scrollRestoration' in window.history) window.history.scrollRestoration = 'manual';
        return () => { if (vechi) window.history.scrollRestoration = vechi; };
    }, []);

    useEffect(() => {
        let cadru = null;
        const noteaza = () => {
            if (cadru) return;
            cadru = requestAnimationFrame(() => {
                cadru = null;
                pozitii.current[cheieCurenta.current] = window.scrollY;
            });
        };
        const salveaza = () => scrie(pozitii.current);
        window.addEventListener('scroll', noteaza, {passive: true});
        window.addEventListener('pagehide', salveaza);
        return () => {
            window.removeEventListener('scroll', noteaza);
            window.removeEventListener('pagehide', salveaza);
            if (cadru) cancelAnimationFrame(cadru);
            salveaza();
        };
    }, []);

    useLayoutEffect(() => {
        scrie(pozitii.current);
        cheieCurenta.current = location.key;
        const prinArbore = inArbore(caleAnterioara.current) && inArbore(location.pathname);
        caleAnterioara.current = location.pathname;
        const tinta = tip === 'POP' ? pozitii.current[location.key] : undefined;
        if (tinta === undefined) {
            // O pagină nouă pornește de sus; nu și paginarea din aceeași pagină (state.pastreazaScroll - lista
            // derulează singură la titlul ei după ce vin datele), selecțiile din arbore sau adresele canonice.
            if (tip === 'PUSH' && !prinArbore && !location.hash && !location.state?.pastreazaScroll) window.scrollTo(0, 0);
            return undefined;
        }
        let activ = true;
        let cadru = null;
        const start = Date.now();
        const opreste = () => { activ = false; if (cadru) cancelAnimationFrame(cadru); };
        const gesturi = ['wheel', 'touchstart', 'keydown', 'mousedown'];
        gesturi.forEach((g) => window.addEventListener(g, opreste, {passive: true, once: true}));
        const incearca = () => {
            if (!activ) return;
            const maxim = document.documentElement.scrollHeight - window.innerHeight;
            if (maxim >= tinta || Date.now() - start > LIMITA_MS) {
                window.scrollTo(0, Math.min(tinta, Math.max(maxim, 0)));
                opreste();
                return;
            }
            cadru = requestAnimationFrame(incearca);
        };
        incearca();
        return () => {
            opreste();
            gesturi.forEach((g) => window.removeEventListener(g, opreste));
        };
    }, [location.key, location.pathname, location.hash, location.state, tip]);
}
