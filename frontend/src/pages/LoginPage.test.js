import {fireEvent, render, screen} from '@testing-library/react';
import {MemoryRouter, Route, Routes} from 'react-router-dom';
import LoginPage from './LoginPage';
import {AuthProvider} from '../auth/AuthContext';
import {getToken} from '../auth/session';
import {adminJwt} from '../testUtils/fakeJwt';

const renderLogin = () => render(
    <AuthProvider>
        <MemoryRouter initialEntries={['/login']}>
            <Routes>
                <Route path="/login" element={<LoginPage/>}/>
                <Route path="/arbore" element={<p>pagina arbore</p>}/>
            </Routes>
        </MemoryRouter>
    </AuthProvider>,
);

const fill = () => {
    fireEvent.change(screen.getByLabelText(/Email/), {target: {value: 'admin@validez.test'}});
    fireEvent.change(screen.getByLabelText(/Parolă/), {target: {value: 'parola'}});
    fireEvent.click(screen.getByRole('button', {name: 'Intră'}));
};

beforeEach(() => window.localStorage.clear());

test('login reusit salveaza sesiunea si duce la arbore', async () => {
    global.fetch = jest.fn(() => Promise.resolve({ok: true, status: 200, json: () => Promise.resolve({token: adminJwt(), refresh_token: 'r'})}));
    renderLogin();
    fill();
    expect(await screen.findByText('pagina arbore')).toBeInTheDocument();
    expect(getToken()).toBe(adminJwt());
});

test('login gresit afiseaza eroarea si ramane pe pagina', async () => {
    global.fetch = jest.fn(() => Promise.resolve({ok: false, status: 401, json: () => Promise.resolve({message: 'Invalid credentials.'})}));
    renderLogin();
    fill();
    expect(await screen.findByText('Email sau parolă incorecte.')).toBeInTheDocument();
    expect(getToken()).toBeNull();
});
