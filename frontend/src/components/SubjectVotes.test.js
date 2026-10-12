import {fireEvent, render, screen, waitFor, within} from '@testing-library/react';
import {MemoryRouter, Route, Routes, useLocation} from 'react-router-dom';
import SubjectVotes from './SubjectVotes';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {fakeJwt} from '../testUtils/fakeJwt';
import {res, routedFetch} from '../testUtils/subjects';

const ion = () => fakeJwt({username: 'ion@validez.test', displayName: 'Ion', roles: ['ROLE_USER'], id: '01ION'});
const consent = (over = {}) => ({active: true, current: true, noticeVersion: 'v1', currentNoticeVersion: 'v1', ...over});
const type = (code, over = {}) => ({
    code, minValue: code === 'IMPORTANCE' ? 1 : 0, maxValue: code === 'IMPORTANCE' ? 5 : 1, active: true,
    result: {counts: {}, total: 0, staleTotal: 0}, mine: null, ...over,
});
const summary = (over = {}) => ({
    subjectId: '01SUBJ', revisionNumber: 1, frozen: false, votingEnabled: true, votingOpen: true,
    types: [type('IMPORTANCE'), type('VALIDITY')], consent: null, ...over,
});
const isVotes = (u, o) => u.endsWith('/civic_subjects/01SUBJ/votes') && (o.method ?? 'GET') === 'GET';
const calls = (pred) => global.fetch.mock.calls.filter(([u, o = {}]) => pred(u, o));
const renderVotes = () => render(<AuthProvider><MemoryRouter><SubjectVotes subjectId="01SUBJ"/></MemoryRouter></AuthProvider>);

beforeEach(() => window.localStorage.clear());

test('vizitator: rezultate agregate și nereconfirmate, fără butoane de vot', async () => {
    global.fetch = routedFetch([[isVotes, () => res(summary({types: [
        type('VALIDITY', {result: {counts: {1: 2, 0: 1}, total: 3, staleTotal: 2}}),
        type('IMPORTANCE', {result: {counts: {4: 1, 5: 1}, total: 2, staleTotal: 0}}),
    ]}))]]);
    renderVotes();
    expect(await screen.findByText(/Validez: 2 \(67%\) · Invalidez: 1 \(33%\) · 3 voturi/)).toBeInTheDocument();
    expect(screen.getByText(/Media 4,5 din 5 · 2 voturi/)).toBeInTheDocument();
    expect(screen.getByText(/2 voturi date pe versiuni anterioare, nereconfirmate/)).toBeInTheDocument();
    expect(screen.queryByRole('button', {name: 'Validez'})).not.toBeInTheDocument();
});

test('votul cu acord curent: PUT cu valoarea, starea vine din răspuns', async () => {
    setSession({token: ion()});
    global.fetch = routedFetch([
        [isVotes, () => res(summary({consent: consent()}))],
        [(u, o) => o.method === 'PUT' && u.endsWith('/votes/VALIDITY'), () => res({action: 'VOTE', ...summary({consent: consent(), types: [
            type('IMPORTANCE'), type('VALIDITY', {result: {counts: {1: 1}, total: 1, staleTotal: 0}, mine: {value: 1, revisionNumber: 1, current: true}}),
        ]})})],
    ]);
    renderVotes();
    fireEvent.click(await screen.findByRole('button', {name: 'Validez'}));
    expect(await screen.findByText('Votul tău:', {exact: false})).toBeInTheDocument();
    const [[, opts]] = calls((u, o) => o.method === 'PUT');
    expect(JSON.parse(opts.body)).toEqual({value: 1});
    expect(screen.getByRole('button', {name: 'Validez'})).toHaveAttribute('aria-pressed', 'true');
});

test('fără acord: dialog, bifa e obligatorie, apoi votul se trimite o singură dată', async () => {
    setSession({token: ion()});
    global.fetch = routedFetch([
        [isVotes, () => res(summary({consent: consent({active: false, current: false, noticeVersion: null})}))],
        [(u, o) => o.method === 'PUT' && u.endsWith('/me/vote-consent'), () => res(consent())],
        [(u, o) => o.method === 'PUT' && u.endsWith('/votes/VALIDITY'), () => res({action: 'VOTE', ...summary({consent: consent()})})],
    ]);
    renderVotes();
    fireEvent.click(await screen.findByRole('button', {name: 'Invalidez'}));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Înainte de primul vot')).toBeInTheDocument();
    expect(within(dialog).getByRole('link', {name: /Voturi/})).toHaveAttribute('href', '/privacy?section=voturi');
    const accept = within(dialog).getByRole('button', {name: 'Sunt de acord și votez'});
    expect(accept).toBeDisabled();
    expect(calls((u, o) => o.method === 'PUT')).toHaveLength(0);
    fireEvent.click(within(dialog).getByRole('checkbox'));
    fireEvent.click(accept);
    await waitFor(() => expect(calls((u, o) => o.method === 'PUT' && u.endsWith('/votes/VALIDITY'))).toHaveLength(1));
    expect(JSON.parse(calls((u, o) => o.method === 'PUT' && u.endsWith('/me/vote-consent'))[0][1].body)).toEqual({noticeVersion: 'v1'});
    expect(JSON.parse(calls((u, o) => o.method === 'PUT' && u.endsWith('/votes/VALIDITY'))[0][1].body)).toEqual({value: 0});
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
});

test('subiectul s-a modificat cât timp se citea informarea: nu se trimite votul', async () => {
    setSession({token: ion()});
    let revision = 1;
    global.fetch = routedFetch([
        [isVotes, () => res(summary({revisionNumber: revision, consent: consent({current: false, noticeVersion: 'v0'})}))],
        [(u, o) => o.method === 'PUT' && u.endsWith('/me/vote-consent'), () => { revision = 2; return res(consent()); }],
    ]);
    renderVotes();
    fireEvent.click(await screen.findByRole('button', {name: 'Validez'}));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Informarea despre voturi s-a actualizat')).toBeInTheDocument();
    fireEvent.click(within(dialog).getByRole('checkbox'));
    fireEvent.click(within(dialog).getByRole('button', {name: 'Sunt de acord și votez'}));
    expect(await screen.findByText(/Subiectul s-a modificat între timp/)).toBeInTheDocument();
    expect(calls((u, o) => o.method === 'PUT' && u.includes('/votes/'))).toHaveLength(0);
});

test('consent_required de la server deschide dialogul; după acord, o singură reîncercare', async () => {
    setSession({token: ion()});
    global.fetch = routedFetch([
        [isVotes, () => res(summary({consent: consent()}))],
        [(u, o) => o.method === 'PUT' && u.endsWith('/me/vote-consent'), () => res(consent())],
        [(u, o) => o.method === 'PUT' && u.endsWith('/votes/VALIDITY'), () => res({reason: 'consent_required', message: 'Votul necesită consimțământul tău explicit.'}, 403)],
    ]);
    renderVotes();
    fireEvent.click(await screen.findByRole('button', {name: 'Validez'}));
    const dialog = await screen.findByRole('dialog');
    fireEvent.click(within(dialog).getByRole('checkbox'));
    fireEvent.click(within(dialog).getByRole('button', {name: 'Sunt de acord și votez'}));
    expect(await screen.findByText('Votul necesită consimțământul tău explicit.')).toBeInTheDocument();
    expect(calls((u, o) => o.method === 'PUT' && u.endsWith('/votes/VALIDITY'))).toHaveLength(2);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

test('vot pe revizie veche: avertisment, diferența și reconfirmarea', async () => {
    setSession({token: ion()});
    global.fetch = routedFetch([
        [isVotes, () => res(summary({revisionNumber: 2, consent: consent(), types: [
            type('VALIDITY', {result: {counts: {}, total: 0, staleTotal: 1}, mine: {value: 1, revisionNumber: 1, current: false}}),
        ]}))],
        [(u) => u.endsWith('/revisions'), () => res({currentRevision: 2, revisions: [
            {number: 1, type: 'ISSUE', title: 'Groapă', description: 'Pe DN1 la km 12', estimate: null, byAuthor: true, createdAt: '2026-10-12T10:00:00+00:00'},
            {number: 2, type: 'ISSUE', title: 'Groapă', description: 'Pe DN1 la km 14', estimate: null, byAuthor: false, createdAt: '2026-10-12T11:00:00+00:00'},
        ]})],
        [(u, o) => o.method === 'PUT', () => res({action: 'RECONFIRM', ...summary({revisionNumber: 2, consent: consent()})})],
    ]);
    const {container} = renderVotes();
    expect(await screen.findByText(/Subiectul s-a modificat de la votul tău/)).toBeInTheDocument();
    expect(screen.getByText('Votul tău: nereconfirmat')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', {name: 'Vezi ce s-a schimbat'}));
    const dialog = await screen.findByRole('dialog');
    expect(await within(dialog).findByText('14', {selector: 'ins'})).toBeInTheDocument();
    expect(within(dialog).getByText('12', {selector: 'del'})).toBeInTheDocument();
    expect(within(dialog).getByText(/modificată de un administrator/)).toBeInTheDocument();
    fireEvent.click(within(dialog).getByRole('button', {name: 'Închide'}));

    fireEvent.click(screen.getByRole('button', {name: /Reconfirmă „Validez”/}));
    await waitFor(() => expect(calls((u, o) => o.method === 'PUT')).toHaveLength(1));
    expect(JSON.parse(calls((u, o) => o.method === 'PUT')[0][1].body)).toEqual({value: 1});
    expect(container).toBeTruthy();
});

test('subiect închis: opțiunile dezactivate, retragerea permisă', async () => {
    setSession({token: ion()});
    let withdrawn = false;
    global.fetch = routedFetch([
        [isVotes, () => res(summary({frozen: true, votingOpen: false, consent: consent(), types: [
            type('VALIDITY', withdrawn ? {} : {result: {counts: {1: 1}, total: 1, staleTotal: 0}, mine: {value: 1, revisionNumber: 1, current: true}}),
        ]}))],
        [(u, o) => o.method === 'DELETE', () => { withdrawn = true; return res({withdrawn: true}); }],
    ]);
    renderVotes();
    expect(await screen.findByText(/votarea s-a încheiat/)).toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'Invalidez'})).toBeDisabled();
    fireEvent.click(screen.getByRole('button', {name: 'Retrage votul'}));
    await waitFor(() => expect(screen.queryByRole('button', {name: 'Retrage votul'})).not.toBeInTheDocument());
    expect(calls((u, o) => o.method === 'DELETE')).toHaveLength(1);
});

test('votarea oprită pe server: fără controale de exprimare, retragerea rămâne', async () => {
    setSession({token: ion()});
    global.fetch = routedFetch([[isVotes, () => res(summary({votingEnabled: false, votingOpen: false, consent: consent(), types: [
        type('VALIDITY', {result: {counts: {1: 1}, total: 1, staleTotal: 0}, mine: {value: 1, revisionNumber: 1, current: true}}),
    ]}))]]);
    renderVotes();
    expect(await screen.findByText(/Votarea nu este încă activă/)).toBeInTheDocument();
    expect(screen.queryByRole('button', {name: 'Validez'})).not.toBeInTheDocument();
    expect(screen.queryByRole('group', {name: 'Validare'})).not.toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'Retrage votul'})).toBeEnabled();
});

test('limita de scriere: mesaj cu secundele primite de la server', async () => {
    setSession({token: ion()});
    global.fetch = routedFetch([
        [isVotes, () => res(summary({consent: consent()}))],
        [(u, o) => o.method === 'PUT', () => res({reason: 'rate_limited', message: 'Prea multe', retryAfter: 42}, 429)],
    ]);
    renderVotes();
    fireEvent.click(await screen.findByRole('button', {name: '3'}));
    expect(await screen.findByText('Prea multe operații de vot. Reîncearcă în 42 de secunde.')).toBeInTheDocument();
});

test('fără tipuri de vot (sau răspuns neașteptat): nu afișează nimic', async () => {
    global.fetch = routedFetch([[isVotes, () => res(summary({types: []}))]]);
    const {container} = renderVotes();
    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
});

function ReturnProbe() {
    const location = useLocation();
    return <p>{`${location.pathname} ← ${location.state?.from}`}</p>;
}
const renderAt = (entry) => render(<AuthProvider><MemoryRouter initialEntries={[entry]}><Routes>
    <Route path="/subiecte/:id" element={<SubjectVotes subjectId="01SUBJ"/>}/>
    <Route path="/arbore" element={<SubjectVotes subjectId="01SUBJ"/>}/>
    <Route path="/inregistrare" element={<ReturnProbe/>}/>
    <Route path="/login" element={<ReturnProbe/>}/>
</Routes></MemoryRouter></AuthProvider>);

test('call to action pentru vizitator: înregistrare cu revenire la subiect', async () => {
    global.fetch = routedFetch([[isVotes, () => res(summary())]]);
    renderAt('/subiecte/01SUBJ');
    expect(await screen.findByText('Înregistrează-te pentru a vota și tu.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('link', {name: 'Înregistrează-te'}));
    expect(screen.getByText('/inregistrare ← /subiecte/01SUBJ')).toBeInTheDocument();
});

test('call to action din panoul arborelui: login cu revenire la aceeași vedere', async () => {
    global.fetch = routedFetch([[isVotes, () => res(summary())]]);
    renderAt('/arbore?node=01NODE&subject=01SUBJ');
    fireEvent.click(await screen.findByRole('link', {name: 'Intră'}));
    expect(screen.getByText('/login ← /arbore?node=01NODE&subject=01SUBJ')).toBeInTheDocument();
});

test('fără call to action când votarea nu e deschisă sau utilizatorul e autentificat', async () => {
    for (const over of [{votingEnabled: false, votingOpen: false}, {frozen: true, votingOpen: false}, {votingOpen: false}]) {
        global.fetch = routedFetch([[isVotes, () => res(summary(over))]]);
        const {unmount} = renderAt('/subiecte/01SUBJ');
        await screen.findByRole('heading', {name: 'Voturi'});
        expect(screen.queryByText('Înregistrează-te pentru a vota și tu.')).not.toBeInTheDocument();
        unmount();
    }
    setSession({token: ion()});
    global.fetch = routedFetch([[isVotes, () => res(summary({consent: consent()}))]]);
    renderAt('/subiecte/01SUBJ');
    await screen.findByRole('button', {name: 'Validez'});
    expect(screen.queryByText('Înregistrează-te pentru a vota și tu.')).not.toBeInTheDocument();
});
