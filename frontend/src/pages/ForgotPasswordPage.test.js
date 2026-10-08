import {act, fireEvent, render, screen, waitFor} from '@testing-library/react';
import {MemoryRouter, Route, Routes} from 'react-router-dom';
import ForgotPasswordPage from './ForgotPasswordPage';
import LoginPage from './LoginPage';
import {AuthProvider} from '../auth/AuthContext';
import {getToken, setSession} from '../auth/session';
import {userJwt} from '../testUtils/fakeJwt';
import {res, routedFetch} from '../testUtils/subjects';

jest.mock('../components/AltchaVerification', () => ({onVerified}) => <button type="button" onClick={() => onVerified('solutie-altcha')}>Verifică ALTCHA</button>);

const mount = (pathname = '/am-uitat-parola', state) => render(<AuthProvider><MemoryRouter initialEntries={[{pathname, state}]}><Routes>
    <Route path="/am-uitat-parola" element={<ForgotPasswordPage/>}/>
    <Route path="/login" element={<LoginPage/>}/>
</Routes></MemoryRouter></AuthProvider>);
const request = () => {
    fireEvent.change(screen.getByLabelText(/^Email/), {target: {value: 'ion@validez.test'}});
    fireEvent.click(screen.getByRole('button', {name: 'Verifică ALTCHA'}));
    fireEvent.click(screen.getByRole('button', {name: 'Trimite codul de resetare'}));
};
const fillPassword = (again = 'noua-parola-123') => {
    fireEvent.change(screen.getByLabelText(/^Cod de resetare/), {target: {value: '012345'}});
    fireEvent.change(screen.getByLabelText(/^Parola nouă/), {target: {value: 'noua-parola-123'}});
    fireEvent.change(screen.getByLabelText(/^Repetă parola nouă/), {target: {value: again}});
};
beforeEach(() => {
    window.localStorage.clear();
    global.fetch = routedFetch([
        [(url) => url.endsWith('/password-reset/request'), () => res({status: 'code_sent', retryAfter: 60})],
        [(url) => url.endsWith('/password-reset/confirm'), () => res({status: 'password_reset'})],
    ]);
});

test('login duce la resetare cu emailul completat', () => {
    mount('/login');
    fireEvent.change(screen.getByLabelText(/^Email/), {target: {value: 'ion@validez.test'}});
    fireEvent.click(screen.getByRole('link', {name: 'Am uitat parola'}));
    expect(screen.getByRole('heading', {name: 'Am uitat parola'})).toBeInTheDocument();
    expect(screen.getByLabelText(/^Email/)).toHaveValue('ion@validez.test');
});

test('codul și parola nouă resetează contul fără autentificare automată', async () => {
    setSession({token: userJwt()});
    mount();
    expect(screen.getByRole('button', {name: 'Trimite codul de resetare'})).toBeDisabled();
    request();
    await screen.findByLabelText(/^Cod de resetare/);
    expect(screen.getByText(/Dacă există un cont confirmat/)).toBeInTheDocument();
    expect(screen.getByRole('button', {name: /Retrimite codul/})).toBeDisabled();
    fillPassword();
    fireEvent.click(screen.getByRole('button', {name: 'Schimbă parola'}));
    expect(await screen.findByText(/Parola a fost schimbată/)).toBeInTheDocument();
    expect(getToken()).toBeNull();
    expect(global.fetch.mock.calls.map(([, options]) => JSON.parse(options.body))).toEqual([
        {email: 'ion@validez.test', altcha: 'solutie-altcha'},
        {email: 'ion@validez.test', code: '012345', password: 'noua-parola-123'},
    ]);
    fireEvent.click(screen.getByRole('link', {name: 'Intră cu noua parolă'}));
    expect(screen.getByLabelText(/^Email/)).toHaveValue('ion@validez.test');
    expect(screen.getByLabelText(/^Parolă/)).toHaveValue('');
});

test('parolele diferite sau prea lungi împiedică resetarea', async () => {
    mount(); request();
    await screen.findByLabelText(/^Cod de resetare/);
    fillPassword('diferita-123');
    expect(screen.getByText('Parolele nu coincid.')).toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'Schimbă parola'})).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/^Parola nouă/), {target: {value: 'ș'.repeat(37)}});
    expect(screen.getByText(/Parola depășește limita de 72/)).toBeInTheDocument();
});

test('codul invalid afișează eroarea fără a închide sesiunea existentă', async () => {
    global.fetch = routedFetch([
        [(url) => url.endsWith('/request'), () => res({retryAfter: 60})],
        [(url) => url.endsWith('/confirm'), () => res({detail: 'Cod invalid sau expirat.'}, 400)],
    ]);
    setSession({token: userJwt()});
    mount(); request();
    await screen.findByLabelText(/^Cod de resetare/); fillPassword();
    fireEvent.click(screen.getByRole('button', {name: 'Schimbă parola'}));
    expect(await screen.findByText('Cod invalid sau expirat.')).toBeInTheDocument();
    expect(getToken()).toBe(userJwt());
    expect(screen.getByLabelText(/^Cod de resetare/)).toBeInTheDocument();
});

test('o cerere eșuată cere ALTCHA din nou', async () => {
    global.fetch = jest.fn(() => res({detail: 'Verificarea a expirat.'}, 400));
    mount(); request();
    expect(await screen.findByText('Verificarea a expirat.')).toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'Trimite codul de resetare'})).toBeDisabled();
});

test('retrimiterea este disponibilă după cooldown și cere ALTCHA nou', async () => {
    jest.useFakeTimers();
    try {
        mount(); request();
        await screen.findByLabelText(/^Cod de resetare/);
        fireEvent.click(screen.getByRole('button', {name: 'Verifică ALTCHA'}));
        expect(screen.getByRole('button', {name: /Retrimite codul/})).toBeDisabled();
        for (let i = 0; i < 60; i++) act(() => jest.advanceTimersByTime(1000));
        fireEvent.click(screen.getByRole('button', {name: 'Retrimite codul'}));
        await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));
        expect(await screen.findByRole('button', {name: 'Retrimite codul (60s)'})).toBeDisabled();
    } finally { jest.useRealTimers(); }
});
