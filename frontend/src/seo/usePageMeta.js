import {useEffect} from 'react';

// ADR-0008: titlul, descrierea, URL-ul canonic și noindex, actualizate la navigarea în SPA.
// La prima încărcare a unei pagini publice, aceleași valori vin din shell-ul HTML servit de Symfony (S.3).
export const SITE = 'VALIDEZ';
export const SITE_URL = 'https://validez.webnou.ro';
export const DEFAULT_TITLE = 'VALIDEZ — Vezi. Verifică. Validează.';
const DEFAULT_DESCRIPTION = 'VALIDEZ — Vezi. Verifică. Validează.';

/** Subiectul/categoria întâi, brandul la final (titlul se taie în rezultate la ~60 de caractere). */
export const pageTitle = (title) => (title ? `${title} — ${SITE}` : DEFAULT_TITLE);

/** Text simplu pe un rând, tăiat la cuvânt (~160 de caractere), pentru meta description. */
export const excerpt = (text, max = 160) => {
    const flat = String(text ?? '').replace(/\s+/g, ' ').trim();
    if (flat.length <= max) return flat;
    const cut = flat.slice(0, max - 1);
    return `${cut.slice(0, cut.lastIndexOf(' ') > 80 ? cut.lastIndexOf(' ') : cut.length)}…`;
};

const headTag = (selector, tag, attrs) => {
    let el = document.head.querySelector(selector);
    if (!el) {
        el = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
        document.head.appendChild(el);
    }
    return el;
};

/**
 * title fără brand; path = calea canonică (doar pagini publice); noindex pentru paginile private.
 * enabled=false: nu atinge nimic (ex. SubjectPage încorporată în arbore). La demontare revine la valorile implicite.
 */
export default function usePageMeta({title = null, description = null, path = null, noindex = false} = {}, enabled = true) {
    useEffect(() => {
        if (!enabled) return undefined;
        document.title = pageTitle(title);
        headTag('meta[name="description"]', 'meta', {name: 'description'}).setAttribute('content', description || DEFAULT_DESCRIPTION);
        if (path && !noindex) headTag('link[rel="canonical"]', 'link', {rel: 'canonical'}).setAttribute('href', SITE_URL + path);
        else document.head.querySelector('link[rel="canonical"]')?.remove();
        if (noindex) headTag('meta[name="robots"]', 'meta', {name: 'robots'}).setAttribute('content', 'noindex');
        else document.head.querySelector('meta[name="robots"]')?.remove();
        return () => {
            document.title = DEFAULT_TITLE;
            document.head.querySelector('meta[name="description"]')?.setAttribute('content', DEFAULT_DESCRIPTION);
            document.head.querySelector('link[rel="canonical"]')?.remove();
            document.head.querySelector('meta[name="robots"]')?.remove();
        };
    }, [title, description, path, noindex, enabled]);
}
