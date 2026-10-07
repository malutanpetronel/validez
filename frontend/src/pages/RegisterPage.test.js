import {fireEvent, render, screen, waitFor} from '@testing-library/react';
import {MemoryRouter, Route, Routes} from 'react-router-dom';
import RegisterPage from './RegisterPage';
import LoginPage from './LoginPage';
import {AuthProvider} from '../auth/AuthContext';
import {getRefreshToken, getToken} from '../auth/session';
import {userJwt} from '../testUtils/fakeJwt';
import {res, routedFetch} from '../testUtils/subjects';

jest.mock('../components/AltchaVerification', () => ({onVerified}) => <button type="button" onClick={() => onVerified('solutie-altcha')}>Verifică ALTCHA</button>);
const renderPage = (state) => render(<AuthProvider><MemoryRouter initialEntries={[{pathname: '/inregistrare', state}]}><Routes>
    <Route path="/inregistrare" element={<RegisterPage/>}/>
    <Route path="/login" element={<LoginPage/>}/>
    <Route path="/arbore" element={<p>Arbore după confirmare</p>}/>
</Routes></MemoryRouter></AuthProvider>);
const fill = () => {
    fireEvent.change(screen.getByLabelText(/Nume afișat/), {target: {value: 'Petru'}});
    fireEvent.change(screen.getByLabelText(/^Email/), {target: {value: 'petru@validez.test'}});
    fireEvent.change(screen.getByLabelText(/^Parolă/), {target: {value: 'parola-tare-123'}});
    fireEvent.change(screen.getByLabelText(/^Repetă parola/), {target: {value: 'parola-tare-123'}});
};
beforeEach(() => {
    window.localStorage.clear();
    global.fetch = routedFetch([
        [(url) => url.endsWith('/register/confirm'), () => res({token: userJwt(), refresh_token: 'refresh-confirmat'})],
        [() => true, () => res({status: 'code_sent', retryAfter: 60})],
    ]);
});

test('înregistrarea cere ALTCHA; confirmarea salvează sesiunea și revine la arbore', async () => {
    renderPage(); fill();
    expect(screen.getByRole('button', {name: 'Creează contul'})).toBeDisabled();
    fireEvent.click(screen.getByRole('button', {name: 'Verifică ALTCHA'}));
    fireEvent.click(screen.getByRole('button', {name: 'Creează contul'}));
    await screen.findByLabelText(/Cod de confirmare/);
    expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toEqual({email: 'petru@validez.test', displayName: 'Petru', password: 'parola-tare-123', altcha: 'solutie-altcha'});
    expect(screen.getByRole('button', {name: /Retrimite codul/})).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/Cod de confirmare/), {target: {value: '012345'}});
    fireEvent.click(screen.getByRole('button', {name: 'Confirmă contul'}));
    expect(await screen.findByText('Arbore după confirmare')).toBeInTheDocument();
    expect(getToken()).toBe(userJwt());
    expect(getRefreshToken()).toBe('refresh-confirmat');
    expect(JSON.parse(global.fetch.mock.calls[1][1].body)).toEqual({email: 'petru@validez.test', code: '012345'});
});

test('parolele diferite sunt explicate și împiedică înregistrarea', () => {
    renderPage(); fill();
    fireEvent.change(screen.getByLabelText(/^Repetă parola/), {target: {value: 'alta-parola-123'}});
    fireEvent.click(screen.getByRole('button', {name: 'Verifică ALTCHA'}));
    expect(screen.getByText('Parolele nu coincid.')).toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'Creează contul'})).toBeDisabled();
});

test('eroarea la înregistrare invalidează soluția ALTCHA pentru următoarea încercare', async () => {
    global.fetch = jest.fn(() => res({detail: 'Adresă invalidă'}, 422));
    renderPage(); fill();
    fireEvent.click(screen.getByRole('button', {name: 'Verifică ALTCHA'}));
    fireEvent.click(screen.getByRole('button', {name: 'Creează contul'}));
    expect(await screen.findByText('Adresă invalidă')).toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'Creează contul'})).toBeDisabled();
});

test('venirea din login păstrează emailul și cere verificare pentru retrimitere', async () => {
    renderPage({confirmEmail: 'petru@validez.test'});
    expect(screen.getByLabelText(/Cod de confirmare/)).toBeInTheDocument();
    expect(global.fetch).not.toHaveBeenCalled();
    expect(screen.getByRole('button', {name: 'Retrimite codul'})).toBeDisabled();
    fireEvent.click(screen.getByRole('button', {name: 'Verifică ALTCHA'}));
    fireEvent.click(screen.getByRole('button', {name: 'Retrimite codul'}));
    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
    expect(global.fetch.mock.calls[0][0]).toMatch(/\/register\/resend$/);
    expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toEqual({email: 'petru@validez.test', altcha: 'solutie-altcha'});
    expect(await screen.findByRole('button', {name: 'Retrimite codul (60s)'})).toBeDisabled();
});

test('codul invalid păstrează utilizatorul în confirmare fără sesiune', async () => {
    global.fetch = jest.fn(() => res({detail: 'Cod invalid sau expirat.'}, 400));
    renderPage({confirmEmail: 'petru@validez.test'});
    fireEvent.change(screen.getByLabelText(/Cod de confirmare/), {target: {value: '123456'}});
    fireEvent.click(screen.getByRole('button', {name: 'Confirmă contul'}));
    expect(await screen.findByText('Cod invalid sau expirat.')).toBeInTheDocument();
    expect(getToken()).toBeNull();
    expect(screen.getByLabelText(/Cod de confirmare/)).toHaveValue('123456');
});
