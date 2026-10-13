import {act, fireEvent, render, screen, waitFor, within} from '@testing-library/react';
import {createMemoryRouter, RouterProvider} from 'react-router-dom';
import {AuthProvider} from '../auth/AuthContext';
import {routes} from '../router';
import {res, routedFetch, subject} from '../testUtils/subjects';

// ADR-0008: arborele e pagina pentru `/`, `/categorie/{cale}` și `/categorie/{cale}/subiect/{slug}`.
const node = (id, name, slugPath, parentId, hasChildren) => ({id, name, slugPath, parentId, hasChildren, subjectCount: 0});
const DRU = node('01DRU', 'Drumuri', 'drumuri', null, true);
const CLU = node('01CLU', 'Cluj', 'drumuri/cluj', '01DRU', true);
const CAL = node('01CAL', 'Calitate', 'drumuri/cluj/calitate', '01CLU', false);
const crumbs = [DRU, CLU, CAL].map(({id, name, slugPath}) => ({id, name, slugPath}));
const canonicalPath = '/categorie/drumuri/cluj/calitate/subiect/gropi-pe-dn1';
const gropi = subject({nodeId: '01CAL', slug: 'gropi-pe-dn1', canonicalPath});
const pathParam = (u) => new URL(u, 'http://x').searchParams.get('path');

beforeEach(() => {
    window.localStorage.clear();
    global.fetch = routedFetch([
        [(u) => u.endsWith('/tree_nodes'), () => res({member: [DRU]})],
        [(u) => u.includes('/tree_nodes?parent=01DRU'), () => res({member: [CLU]})],
        [(u) => u.includes('/tree_nodes?parent=01CLU'), () => res({member: [CAL]})],
        [(u) => u.includes('/tree_nodes/by-path') && ['drumuri/cluj/calitate', 'drumuri/cluj-vechi/calitate'].includes(pathParam(u)),
            () => res({...CAL, breadcrumbs: crumbs})],
        [(u) => u.includes('/tree_nodes/by-path'), () => res({detail: 'Not Found'}, 404)],
        [(u) => u.includes('/civic_subjects?') && u.includes('node=01CAL'), () => res({member: [gropi], totalItems: 1})],
        [(u) => /\/by-slug\/(gropi-pe-dn1|gropi-vechi)$/.test(u), () => res(gropi)],
        [(u) => u.includes('/by-slug/'), () => res({detail: 'Not Found'}, 404)],
        [(u) => u.endsWith('/civic_subjects/01SUBJ'), () => res(gropi)],
    ]);
});

afterEach(() => {
    document.head.querySelector('link[rel="canonical"]')?.remove();
    document.head.querySelector('meta[name="robots"]')?.remove();
});

const renderAt = (path) => {
    const router = createMemoryRouter(routes, {initialEntries: [path]});
    render(<AuthProvider><RouterProvider router={router}/></AuthProvider>);
    return router;
};
const treeItem = (name) => screen.getAllByText(name).map((el) => el.closest('.rc-tree-node-content-wrapper')).find(Boolean);
const isSelected = (name) => treeItem(name)?.classList.contains('rc-tree-node-selected');
const fetched = (pred) => global.fetch.mock.calls.filter(([u]) => pred(u)).length;

test('adresa unei categorii deschide arborele expandat pana la ea, cu panoul categoriei', async () => {
    renderAt('/categorie/drumuri/cluj/calitate');
    await waitFor(() => expect(isSelected('Calitate')).toBe(true));
    const nav = screen.getByRole('navigation', {name: 'Categorii'});
    expect(within(nav).getByRole('link', {name: 'Drumuri'})).toHaveAttribute('href', '/categorie/drumuri');
    expect(within(nav).getByRole('link', {name: 'Cluj'})).toHaveAttribute('href', '/categorie/drumuri/cluj');
    expect(await screen.findByRole('link', {name: /Gropi pe DN1/})).toHaveAttribute('href', canonicalPath);
    await waitFor(() => expect(document.title).toBe('Calitate · Cluj — VALIDEZ'));
    expect(document.head.querySelector('link[rel="canonical"]')).toHaveAttribute('href', 'https://validez.webnou.ro/categorie/drumuri/cluj/calitate');
});

test('click in arbore schimba adresa; Back revine la selectia anterioara; arborele nu se reconstruieste', async () => {
    const router = renderAt('/');
    fireEvent.click(await screen.findByText('Drumuri'));
    await waitFor(() => expect(router.state.location.pathname).toBe('/categorie/drumuri'));
    fireEvent.click(await waitFor(() => { const item = treeItem('Cluj'); expect(item).toBeTruthy(); return item; }));
    await waitFor(() => expect(router.state.location.pathname).toBe('/categorie/drumuri/cluj'));
    expect(router.state.historyAction).toBe('PUSH');
    // Subcategoriile ca linkuri reale in panou.
    expect(await screen.findByRole('link', {name: 'Calitate'})).toHaveAttribute('href', '/categorie/drumuri/cluj/calitate');

    await act(() => router.navigate(-1));
    await waitFor(() => expect(isSelected('Drumuri')).toBe(true));
    expect(router.state.location.pathname).toBe('/categorie/drumuri');
    await act(() => router.navigate(-1));
    expect(await screen.findByText('Explorează subiectele')).toBeInTheDocument();
    expect(isSelected('Drumuri')).toBe(false);

    expect(fetched((u) => u.endsWith('/tree_nodes'))).toBe(1);
    expect(fetched((u) => u.includes('/tree_nodes/by-path'))).toBe(0);
});

test('click pe un subiect din arbore duce la URL-ul lui; adresa subiectului il deschide in panou', async () => {
    const router = renderAt('/categorie/drumuri/cluj/calitate');
    await waitFor(() => expect(isSelected('Calitate')).toBe(true));
    const inTree = await waitFor(() => {
        const item = treeItem('Gropi pe DN1');
        expect(item).toBeTruthy();
        return item;
    });
    fireEvent.click(inTree);
    await waitFor(() => expect(router.state.location.pathname).toBe(canonicalPath));
    expect(await screen.findByRole('heading', {name: 'Gropi pe DN1'})).toBeInTheDocument();
    await waitFor(() => expect(document.title).toBe('Gropi pe DN1 — VALIDEZ'));
    expect(document.head.querySelector('link[rel="canonical"]')).toHaveAttribute('href', `https://validez.webnou.ro${canonicalPath}`);
});

test('adresa directa a unui subiect: arbore expandat, subiect selectat, breadcrumbs', async () => {
    renderAt(canonicalPath);
    expect(await screen.findByRole('heading', {name: 'Gropi pe DN1'})).toBeInTheDocument();
    await waitFor(() => expect(isSelected('Gropi pe DN1')).toBe(true));
    expect(within(screen.getByRole('navigation', {name: 'Categorii'})).getByRole('link', {name: 'Calitate'}))
        .toHaveAttribute('href', '/categorie/drumuri/cluj/calitate');
});

test('cale veche de categorie sau slug vechi: adresa devine cea canonica (replace)', async () => {
    let router = renderAt('/categorie/drumuri/cluj-vechi/calitate');
    await waitFor(() => expect(router.state.location.pathname).toBe('/categorie/drumuri/cluj/calitate'));
    expect(router.state.historyAction).toBe('REPLACE');
    router = renderAt('/categorie/drumuri/subiect/gropi-vechi');
    await waitFor(() => expect(router.state.location.pathname).toBe(canonicalPath));
});

test('categorie sau subiect inexistent: mesaj si noindex', async () => {
    renderAt('/categorie/drumuri/necunoscut');
    expect(await screen.findByText('Categoria nu există.')).toBeInTheDocument();
    await waitFor(() => expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute('content', 'noindex'));
    expect(document.head.querySelector('link[rel="canonical"]')).toBeNull();
});

test('API indisponibil (nu 404): mesaj, dar fara noindex - pagina nu iese din index la o pana', async () => {
    global.fetch = routedFetch([
        [(u) => u.endsWith('/tree_nodes'), () => res({member: [DRU]})],
        [(u) => u.includes('/tree_nodes/by-path'), () => res({detail: 'Service Unavailable'}, 503)],
        [(u) => u.includes('/by-slug/'), () => res({detail: 'Service Unavailable'}, 503)],
    ]);
    renderAt('/categorie/drumuri/cluj/calitate');
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    await waitFor(() => expect(document.title).toBe('VALIDEZ — Vezi. Verifică. Validează.'));
    expect(document.head.querySelector('meta[name="robots"]')).toBeNull();
});

test('subiect inexistent: mesaj', async () => {
    renderAt('/categorie/drumuri/subiect/nu-exista');
    expect(await screen.findByText('Subiectul nu există sau nu e vizibil.')).toBeInTheDocument();
});

test('cai incomplete: /categorie/a/subiect duce la categorie, /categorie la pagina principala', async () => {
    let router = renderAt('/categorie/drumuri/cluj/calitate/subiect');
    await waitFor(() => expect(router.state.location.pathname).toBe('/categorie/drumuri/cluj/calitate'));
    router = renderAt('/categorie');
    await waitFor(() => expect(router.state.location.pathname).toBe('/'));
});
