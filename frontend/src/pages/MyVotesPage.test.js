import {fireEvent, render, screen, within} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import MyVotesPage from './MyVotesPage';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {fakeJwt} from '../testUtils/fakeJwt';
import {res, routedFetch} from '../testUtils/subjects';

const ion = () => fakeJwt({username: 'ion@validez.test', displayName: 'Ion', roles: ['ROLE_USER'], id: '01ION'});
const renderPage = () => render(<AuthProvider><MemoryRouter><MyVotesPage/></MemoryRouter></AuthProvider>);

beforeEach(() => window.localStorage.clear());

test('vizitator: trimis la login', () => {
    global.fetch = routedFetch([]);
    renderPage();
    expect(screen.getByRole('link', {name: 'Intră în cont'})).toHaveAttribute('href', '/login');
});

test('acord, voturi, retragerea acordului cu confirmare', async () => {
    setSession({token: ion()});
    let active = true;
    global.fetch = routedFetch([
        [(u, o) => u.endsWith('/me/vote-consent') && o.method === 'DELETE', () => { active = false; return res({withdrawnVotes: 2, active: false, current: false, noticeVersion: null, currentNoticeVersion: 'v1', votingEnabled: true}); }],
        [(u) => u.endsWith('/me/vote-consent'), () => res({active, current: active, noticeVersion: active ? 'v1' : null, currentNoticeVersion: 'v1', votingEnabled: true})],
        [(u) => u.endsWith('/me/votes'), () => res({format: {}, consent: {}, history: [], votes: active ? [
            {subjectId: '01ABCDEFGHJKMNPQRS', voteType: 'VALIDITY', value: 0, revisionNumber: 1, current: false},
            {subjectId: '01ABCDEFGHJKMNPQRT', voteType: 'IMPORTANCE', value: 4, revisionNumber: 2, current: true},
        ] : []})],
    ]);
    renderPage();
    expect(await screen.findByText(/Ți-ai dat acordul/)).toBeInTheDocument();
    expect(screen.getByText('Validare: Invalidez — nereconfirmat (revizia 1)')).toBeInTheDocument();
    expect(screen.getByText('Importanță: 4')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', {name: 'Retrage acordul'}));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/Istoricul tău privat de vot va fi șters/)).toBeInTheDocument();
    expect(global.fetch.mock.calls.filter(([, o = {}]) => o.method === 'DELETE')).toHaveLength(0);
    fireEvent.click(within(dialog).getByRole('button', {name: 'Retrage acordul'}));
    expect(await screen.findByText(/2 voturi retrase; istoricul tău privat de vot a fost șters/)).toBeInTheDocument();
    expect(await screen.findByText('Nu ai voturi active.')).toBeInTheDocument();
    expect(screen.getByText(/Nu ai un acord activ/)).toBeInTheDocument();
});
