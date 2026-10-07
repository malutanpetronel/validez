import {render, screen} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import TreePage from './TreePage';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {adminJwt, userJwt} from '../testUtils/fakeJwt';

const renderPage = () => render(
    <AuthProvider>
        <MemoryRouter><TreePage/></MemoryRouter>
    </AuthProvider>,
);

beforeEach(() => {
    window.localStorage.clear();
    global.fetch = jest.fn(() => Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({member: [
            {id: '01AAAAAAAAAAAAAAAAAAAAAAAA', name: 'Drumuri', hasChildren: true, parentId: null},
            {id: '01BBBBBBBBBBBBBBBBBBBBBBBB', name: 'Sănătate', hasChildren: false, parentId: null},
        ]}),
    }));
});

test('vizitatorul vede arborele, fara actiuni de modificare', async () => {
    renderPage();
    expect(await screen.findByText('Drumuri')).toBeInTheDocument();
    expect(screen.getByText('Sănătate')).toBeInTheDocument();
    expect(screen.queryByRole('button', {name: /Rădăcină nouă/})).not.toBeInTheDocument();
    expect(screen.getByRole('link', {name: /intră în cont/})).toBeInTheDocument();
    expect(global.fetch.mock.calls[0][1].headers.Authorization).toBeUndefined();
});

test('utilizatorul fara ROLE_ADMIN e tratat ca vizitator', async () => {
    setSession({token: userJwt()});
    renderPage();
    await screen.findByText('Drumuri');
    expect(screen.queryByRole('button', {name: /Rădăcină nouă/})).not.toBeInTheDocument();
});

test('administratorul vede actiunile; cele pe nod sunt dezactivate pana la selectie', async () => {
    setSession({token: adminJwt()});
    renderPage();
    await screen.findByText('Drumuri');
    expect(screen.getByRole('button', {name: /Rădăcină nouă/})).toBeEnabled();
    expect(screen.queryByRole('button', {name: /Adaugă copil/})).not.toBeInTheDocument();
    expect(global.fetch.mock.calls[0][1].headers.Authorization).toMatch(/^Bearer /);
});
