import {fireEvent, render, screen, waitFor} from '@testing-library/react';
import {createMemoryRouter, RouterProvider} from 'react-router-dom';
import {AuthProvider} from './auth/AuthContext';
import {migrateLegacyHash, routes} from './router';
import {res, routedFetch, subject} from './testUtils/subjects';

beforeEach(() => {
    window.localStorage.clear();
    global.fetch = jest.fn((url) => Promise.resolve({
        ok: true, status: 200,
        json: () => Promise.resolve({member: url.includes('/tree_nodes')
            ? [{id: 'category', name: 'Drumuri', hasChildren: false}] : [], totalItems: 0}),
    }));
});

test.each(['/subiecte', '/subiecte/subject'])(
    'înapoi la arbore din %s revine la ruta principală', async (path) => {
        const router = createMemoryRouter(routes, {initialEntries: [path]});
        render(<AuthProvider><RouterProvider router={router}/></AuthProvider>);
        const back = screen.getByRole('link', {name: 'Înapoi la arbore'});
        expect(back).toHaveAttribute('href', '/');
        fireEvent.click(back);
        expect(await screen.findByText('Drumuri')).toBeInTheDocument();
        expect(router.state.location.pathname).toBe('/');
    },
);

const renderAt = (path) => {
    const router = createMemoryRouter(routes, {initialEntries: [path]});
    render(<AuthProvider><RouterProvider router={router}/></AuthProvider>);
    return router;
};

test('adresa necunoscuta: pagina „nu exista” cu noindex, in aplicatie', async () => {
    renderAt('/abc/def');
    expect(await screen.findByRole('heading', {name: 'Pagina nu există'})).toBeInTheDocument();
    expect(screen.getByRole('link', {name: 'Înapoi la tematici'})).toHaveAttribute('href', '/');
    expect(screen.getByRole('link', {name: 'VALIDEZ — pagina principală'})).toBeInTheDocument();
    await waitFor(() => expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute('content', 'noindex'));
    expect(document.title).toBe('Pagină negăsită — VALIDEZ');
});

test('eroare neprevazuta intr-o pagina: mesaj normal, nu ecranul tehnic', async () => {
    const Boom = () => { throw new Error('boom'); };
    const spy = jest.spyOn(console, 'error').mockImplementation(() => {});
    const router = createMemoryRouter([{...routes[0], children: [{index: true, element: <Boom/>}]}], {initialEntries: ['/']});
    render(<AuthProvider><RouterProvider router={router}/></AuthProvider>);
    expect(await screen.findByRole('heading', {name: 'A apărut o eroare'})).toBeInTheDocument();
    expect(screen.queryByText(/Unexpected Application Error/)).not.toBeInTheDocument();
    spy.mockRestore();
});

test('linkurile vechi cu hash devin cai reale, cu query', () => {
    const history = {state: {x: 1}, replaceState: jest.fn()};
    migrateLegacyHash({hash: '#/subiecte/01SUBJ?a=1'}, history);
    expect(history.replaceState).toHaveBeenCalledWith({x: 1}, '', '/subiecte/01SUBJ?a=1');
    migrateLegacyHash({hash: ''}, history);
    migrateLegacyHash({hash: '#sectiune'}, history);
    expect(history.replaceState).toHaveBeenCalledTimes(1);
});

test('vechiul /categorii/{id}/subiecte duce la /categorie/{cale}', async () => {
    global.fetch = routedFetch([
        [(u) => u.endsWith('/tree_nodes/01NODE'), () => res({id: '01NODE', name: 'Cluj', slugPath: 'drumuri/cluj'})],
        [(u) => u.includes('/tree_nodes/by-path'), () => res({id: '01NODE', name: 'Cluj', slugPath: 'drumuri/cluj', hasChildren: false,
            breadcrumbs: [{name: 'Drumuri', slugPath: 'drumuri'}, {name: 'Cluj', slugPath: 'drumuri/cluj'}]})],
    ]);
    const router = renderAt('/categorii/01NODE/subiecte?name=Cluj');
    await waitFor(() => expect(router.state.location.pathname).toBe('/categorie/drumuri/cluj'));
});

test('vechiul /subiecte/{id} duce la URL-ul canonic al subiectului', async () => {
    const canonicalPath = '/categorie/drumuri/subiect/gropi-pe-dn1';
    global.fetch = routedFetch([
        [(u) => u.endsWith('/civic_subjects/01SUBJ'), () => res(subject({canonicalPath, slug: 'gropi-pe-dn1'}))],
        [(u) => u.includes('/civic_subjects/by-slug/gropi-pe-dn1'), () => res(subject({canonicalPath, slug: 'gropi-pe-dn1'}))],
    ]);
    const router = renderAt('/subiecte/01SUBJ');
    expect(await screen.findByRole('heading', {name: 'Gropi pe DN1'})).toBeInTheDocument();
    await waitFor(() => expect(router.state.location.pathname).toBe(canonicalPath));
});
