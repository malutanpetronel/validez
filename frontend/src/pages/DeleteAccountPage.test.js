import {fireEvent, render, screen} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import DeleteAccountPage, {DeleteAccountConfirmPage} from './DeleteAccountPage';
import {AuthProvider} from '../auth/AuthContext';
import {getToken, setSession} from '../auth/session';
import {adminJwt, fakeJwt} from '../testUtils/fakeJwt';
import {res, routedFetch} from '../testUtils/subjects';

const ion = () => fakeJwt({username: 'ion@validez.test', displayName: 'Ion', roles: ['ROLE_USER'], id: '01ION'});
const renderAt = (element, entry = '/sterge-cont') => render(<AuthProvider><MemoryRouter initialEntries={[entry]}>{element}</MemoryRouter></AuthProvider>);

beforeEach(() => window.localStorage.clear());

test('utilizator: explicația, apoi linkul trimis pe email', async () => {
    setSession({token: ion()});
    global.fetch = routedFetch([[(u) => u.endsWith('/account/deletion-request'), () => res({status: 'link_sent', expiresInMinutes: 60})]]);
    renderAt(<DeleteAccountPage/>);
    expect(screen.getByText(/nu se poate anula/)).toBeInTheDocument();
    expect(screen.getByText(/autorul apare ca „Cont șters”/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', {name: 'Trimite-mi linkul de ștergere'}));
    expect(await screen.findByText(/Ți-am trimis linkul pe ion@validez.test\. Este valabil 60 de minute/)).toBeInTheDocument();
    expect(global.fetch.mock.calls.filter(([u]) => u.endsWith('/account/deletion-request'))).toHaveLength(1);
});

test('admin: fără buton, doar explicația; vizitator: intră în cont', () => {
    setSession({token: adminJwt()});
    const {unmount} = renderAt(<DeleteAccountPage/>);
    expect(screen.getByText(/se șterg doar la cerere scrisă/)).toBeInTheDocument();
    expect(screen.queryByRole('button', {name: 'Trimite-mi linkul de ștergere'})).not.toBeInTheDocument();
    unmount();
    window.localStorage.clear();
    renderAt(<DeleteAccountPage/>);
    expect(screen.getByRole('link', {name: 'Intră în cont'})).toBeInTheDocument();
});

test('confirmarea: ștergerea doar la apăsare, apoi sesiunea se închide', async () => {
    setSession({token: ion()});
    global.fetch = routedFetch([[(u) => u.endsWith('/account/deletion-confirm'), () => res({status: 'deleted'})]]);
    renderAt(<DeleteAccountConfirmPage/>, '/sterge-cont/confirmare?token=abc123');
    expect(global.fetch).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', {name: 'Șterge definitiv contul'}));
    expect(await screen.findByText(/Contul tău Validez a fost șters/)).toBeInTheDocument();
    const [, opts] = global.fetch.mock.calls[0];
    expect(JSON.parse(opts.body)).toEqual({token: 'abc123'});
    expect(getToken()).toBeFalsy();
});

test('confirmarea: link expirat → mesajul serverului și „Cere un link nou”', async () => {
    global.fetch = routedFetch([[(u) => u.endsWith('/account/deletion-confirm'), () => res({detail: 'Linkul nu mai este valabil.'}, 400)]]);
    renderAt(<DeleteAccountConfirmPage/>, '/sterge-cont/confirmare?token=vechi');
    fireEvent.click(screen.getByRole('button', {name: 'Șterge definitiv contul'}));
    expect(await screen.findByText('Linkul nu mai este valabil.')).toBeInTheDocument();
    expect(screen.getByRole('link', {name: 'Cere un link nou'})).toHaveAttribute('href', '/sterge-cont');
});
