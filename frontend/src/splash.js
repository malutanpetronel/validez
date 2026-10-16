/**
 * Ecranul de încărcare din public/index.html folosește logo-splash.svg, cu arce și puncte animate
 * încă dinaintea pornirii React (animația respectă prefers-reduced-motion).
 * Se ascunde când pagina deschisă are datele, ca să nu se vadă
 * rezumatul SEO nestilizat și elementele care se așază pe rând (link deschis din Facebook etc.):
 * - arborele ("/", "/categorie/..."): TreePage, după încărcarea arborelui;
 * - un subiect (".../subiect/{slug}", "/subiecte/{id}"): SubjectPage, după subiect și lista de fotografii;
 * - restul paginilor: Layout, imediat.
 * Limită de siguranță în index.js: nu rămâne afișat dacă API-ul nu răspunde. Idempotent.
 */
export const SPLASH_TIMEOUT_MS = 5000;

export function hideSplash() {
    const el = document.getElementById('splash');
    if (!el || el.classList.contains('splash-hide')) return;
    el.classList.add('splash-hide');
    window.setTimeout(() => el.remove(), 250);
}

/** Paginile care ascund singure ecranul, după date (vezi mai sus). */
export const waitsForData = (pathname) => pathname === '/' || /^\/categorie(\/|$)/.test(pathname) || /^\/subiecte\/[^/]+\/?$/.test(pathname);

export const isSubjectPath = (pathname) => /\/subiect\/[^/]+\/?$/.test(pathname) || /^\/subiecte\/[^/]+\/?$/.test(pathname);
