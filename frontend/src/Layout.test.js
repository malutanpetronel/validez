import {fireEvent, render, screen} from '@testing-library/react';
import {MemoryRouter, Route, Routes} from 'react-router-dom';
import Layout from './Layout';
import CheckApiPage from './pages/CheckApiPage';
import {AuthProvider} from './auth/AuthContext';
import {setSession} from './auth/session';
import {adminJwt, userJwt} from './testUtils/fakeJwt';

const renderLayout = (path = '/') => render(
    <AuthProvider><MemoryRouter initialEntries={[path]}><Routes>
        <Route element={<Layout/>}>
            <Route path="/" element={<div>Pagina principală</div>}/>
            <Route path="/check-api" element={<CheckApiPage/>}/>
        </Route>
    </Routes></MemoryRouter></AuthProvider>,
);

beforeEach(() => {
    window.localStorage.clear();
    global.fetch = jest.fn(() => Promise.resolve({ok: true, status: 200}));
});

test('meniul utilizatorului începe cu numele și permite ieșirea fără Check API', () => {
    setSession({token: userJwt()});
    renderLayout();
    expect(screen.queryByRole('link', {name: 'Arbore'})).not.toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'Meniu utilizator'})).toHaveTextContent('Logged in');
    expect(screen.queryByText('Ion')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', {name: 'Meniu utilizator'}));
    expect(screen.getAllByRole('menuitem')[0]).toHaveTextContent('Ion');
    expect(screen.getByRole('menuitem', {name: 'Help'})).toHaveAttribute('href', '/help');
    expect(screen.getByRole('menuitem', {name: 'Propune o categorie'})).toBeInTheDocument();
    expect(screen.queryByRole('menuitem', {name: 'Check API'})).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('menuitem', {name: 'Ieși'}));
    expect(screen.getByRole('link', {name: 'Intră'})).toBeInTheDocument();
});

test('administratorul deschide verificarea API din meniu', async () => {
    setSession({token: adminJwt()});
    renderLayout();
    fireEvent.click(screen.getByRole('button', {name: 'Meniu utilizator'}));
    expect(screen.getAllByRole('menuitem')[0]).toHaveTextContent('Admină');
    expect(screen.queryByRole('menuitem', {name: 'Help'})).not.toBeInTheDocument();
    expect(screen.getByRole('menuitem', {name: 'Moderare subiecte'})).toBeInTheDocument();
    fireEvent.click(screen.getByRole('menuitem', {name: 'Check API'}));
    expect(await screen.findByText('API disponibil (200).')).toBeInTheDocument();
});

test.each([null, userJwt()])('Check API respinge accesul direct fără rol admin (%s)', (token) => {
    if (token) setSession({token});
    renderLayout('/check-api');
    expect(screen.getByText('Pagina principală')).toBeInTheDocument();
    expect(global.fetch).not.toHaveBeenCalled();
});
