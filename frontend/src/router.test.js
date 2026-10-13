import {fireEvent, render, screen} from '@testing-library/react';
import {createMemoryRouter, RouterProvider} from 'react-router-dom';
import {AuthProvider} from './auth/AuthContext';
import {routes} from './router';

beforeEach(() => {
    window.localStorage.clear();
    global.fetch = jest.fn((url) => Promise.resolve({
        ok: true, status: 200,
        json: () => Promise.resolve({member: url.includes('/tree_nodes')
            ? [{id: 'category', name: 'Drumuri', hasChildren: false}] : [], totalItems: 0}),
    }));
});

test.each(['/subiecte', '/categorii/category/subiecte?name=Drumuri', '/subiecte/subject'])(
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
