import {render, screen, waitFor, within} from '@testing-library/react';
import {createMemoryRouter, RouterProvider} from 'react-router-dom';
import {AuthProvider} from '../auth/AuthContext';
import {routes} from '../router';
import {res, routedFetch, subject} from '../testUtils/subjects';

const crumbs = [{name: 'Drumuri', slugPath: 'drumuri'}, {name: 'Cluj', slugPath: 'drumuri/cluj'}, {name: 'Calitate', slugPath: 'drumuri/cluj/calitate'}];
const calitate = {id: '01CAL', name: 'Calitate', slugPath: 'drumuri/cluj/calitate', hasChildren: true, breadcrumbs: crumbs};
const canonicalPath = '/categorie/drumuri/cluj/calitate/subiect/gropi-pe-dn1';

const byPath = (path) => (u) => u.includes('/tree_nodes/by-path') && new URL(u, 'http://x').searchParams.get('path') === path;

const renderAt = (path) => {
    const router = createMemoryRouter(routes, {initialEntries: [path]});
    render(<AuthProvider><RouterProvider router={router}/></AuthProvider>);
    return router;
};

beforeEach(() => {
    window.localStorage.clear();
    global.fetch = routedFetch([
        [byPath('drumuri/cluj/calitate'), () => res(calitate)],
        // cale veche (categorie redenumita): API-ul intoarce nodul cu calea curenta
        [byPath('drumuri/cluj-vechi/calitate'), () => res(calitate)],
        [byPath('drumuri/necunoscut'), () => res({detail: 'Not Found'}, 404)],
        [(u) => u.includes('/tree_nodes?parent=01CAL'), () => res({member: [{id: '01DN1', name: 'DN1', slugPath: 'drumuri/cluj/calitate/dn1'}]})],
        [(u) => u.includes('/civic_subjects?') , () => res({member: [subject({canonicalPath, slug: 'gropi-pe-dn1'})], totalItems: 1})],
        [(u) => u.includes('/civic_subjects/by-slug/gropi-pe-dn1'), () => res(subject({canonicalPath, slug: 'gropi-pe-dn1'}))],
        [(u) => u.includes('/civic_subjects/by-slug/gropi-vechi'), () => res(subject({canonicalPath, slug: 'gropi-pe-dn1'}))],
        [(u) => u.includes('/civic_subjects/by-slug/'), () => res({detail: 'Not Found'}, 404)],
        [(u) => u.endsWith('/civic_subjects/01SUBJ'), () => res(subject({canonicalPath, slug: 'gropi-pe-dn1'}))],
    ]);
});

afterEach(() => {
    document.head.querySelector('link[rel="canonical"]')?.remove();
    document.head.querySelector('meta[name="robots"]')?.remove();
});

test('pagina categoriei: breadcrumbs, subcategorii si subiecte ca linkuri reale, titlu si canonical', async () => {
    renderAt('/categorie/drumuri/cluj/calitate');
    expect(await screen.findByRole('heading', {name: 'Calitate', level: 1})).toBeInTheDocument();
    const nav = screen.getByRole('navigation', {name: 'Categorii'});
    expect(within(nav).getByRole('link', {name: 'Drumuri'})).toHaveAttribute('href', '/categorie/drumuri');
    expect(within(nav).getByRole('link', {name: 'Cluj'})).toHaveAttribute('href', '/categorie/drumuri/cluj');
    expect(within(nav).queryByRole('link', {name: 'Calitate'})).not.toBeInTheDocument();
    expect(await screen.findByRole('link', {name: 'DN1'})).toHaveAttribute('href', '/categorie/drumuri/cluj/calitate/dn1');
    expect(await screen.findByRole('link', {name: /Gropi pe DN1/})).toHaveAttribute('href', canonicalPath);
    await waitFor(() => expect(document.title).toBe('Calitate · Cluj — VALIDEZ'));
    expect(document.head.querySelector('link[rel="canonical"]')).toHaveAttribute('href', 'https://validez.webnou.ro/categorie/drumuri/cluj/calitate');
    expect(document.head.querySelector('meta[name="robots"]')).toBeNull();
});

test('o cale veche de categorie e inlocuita cu cea canonica', async () => {
    const router = renderAt('/categorie/drumuri/cluj-vechi/calitate');
    expect(await screen.findByRole('heading', {name: 'Calitate', level: 1})).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/categorie/drumuri/cluj/calitate');
    expect(router.state.historyAction).toBe('REPLACE');
});

test('categorie inexistenta: mesaj si noindex', async () => {
    renderAt('/categorie/drumuri/necunoscut');
    expect(await screen.findByText('Categoria nu există.')).toBeInTheDocument();
    await waitFor(() => expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute('content', 'noindex'));
    expect(document.head.querySelector('link[rel="canonical"]')).toBeNull();
});

test('subiect dupa slug: titlu, breadcrumbs ale categoriei, canonical', async () => {
    renderAt(canonicalPath);
    expect(await screen.findByRole('heading', {name: 'Gropi pe DN1'})).toBeInTheDocument();
    const nav = await screen.findByRole('navigation', {name: 'Categorii'});
    expect(within(nav).getByRole('link', {name: 'Calitate'})).toHaveAttribute('href', '/categorie/drumuri/cluj/calitate');
    await waitFor(() => expect(document.title).toBe('Gropi pe DN1 — VALIDEZ'));
    expect(document.head.querySelector('meta[name="description"]')).toHaveAttribute('content', 'Multe gropi.');
    expect(document.head.querySelector('link[rel="canonical"]')).toHaveAttribute('href', `https://validez.webnou.ro${canonicalPath}`);
});

test('slug vechi sau cale de categorie veche: URL-ul devine cel canonic', async () => {
    const router = renderAt('/categorie/drumuri/cluj-vechi/calitate/subiect/gropi-vechi');
    expect(await screen.findByRole('heading', {name: 'Gropi pe DN1'})).toBeInTheDocument();
    await waitFor(() => expect(router.state.location.pathname).toBe(canonicalPath));
});

test('subiect nepublicat: noindex', async () => {
    global.fetch = routedFetch([
        [(u) => u.includes('/by-slug/gropi-pe-dn1') || u.endsWith('/civic_subjects/01SUBJ'), () => res(subject({canonicalPath, visibility: 'PENDING'}))],
        [(u) => u.includes('/tree_nodes/by-path'), () => res(calitate)],
    ]);
    renderAt(canonicalPath);
    expect(await screen.findByText('În așteptarea aprobării')).toBeInTheDocument();
    await waitFor(() => expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute('content', 'noindex'));
});

test('subiect inexistent: mesaj si noindex', async () => {
    renderAt('/categorie/drumuri/subiect/nu-exista');
    expect(await screen.findByText('Subiectul nu există sau nu e vizibil.')).toBeInTheDocument();
    await waitFor(() => expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute('content', 'noindex'));
});

test('cai incomplete: /categorie/a/subiect duce la categorie, /categorie la pagina principala', async () => {
    let router = renderAt('/categorie/drumuri/cluj/calitate/subiect');
    await waitFor(() => expect(router.state.location.pathname).toBe('/categorie/drumuri/cluj/calitate'));
    router = renderAt('/categorie');
    await waitFor(() => expect(router.state.location.pathname).toBe('/'));
});
