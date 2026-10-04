import {fireEvent, render, screen, waitFor, within} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import SubjectList from './SubjectList';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {fakeJwt} from '../testUtils/fakeJwt';
import {res, routedFetch, subject} from '../testUtils/subjects';

const userJwt = () => fakeJwt({username: 'ion@validez.test', displayName: 'Ion', roles: ['ROLE_USER'], id: '01ION'});
const renderList = (node) => render(<AuthProvider><MemoryRouter><SubjectList node={node}/></MemoryRouter></AuthProvider>);
const listUrls = () => global.fetch.mock.calls.map(([u]) => u).filter((u) => u.includes('/civic_subjects') && !u.endsWith('/civic_subjects/'));

beforeEach(() => {
    window.localStorage.clear();
    global.fetch = routedFetch([
        [(u, o) => (o.method ?? 'GET') === 'GET' && u.includes('/civic_subjects'), () => res({
            member: [subject(), subject({id: '01S2', title: 'Ascuns de admin', visibility: 'HIDDEN', stage: 'RESOLVED', nodeName: 'DN1'})],
            totalItems: 45,
        })],
        [(u, o) => o.method === 'POST', (u, o) => res(subject({id: '01NEW', ...JSON.parse(o.body)}), 201)],
    ]);
});

test('vizitator: vede subiectele (cu nodul fiecaruia), fara buton de creare', async () => {
    renderList({id: '01NODE', name: 'Drumuri'});
    expect(await screen.findByText('Gropi pe DN1')).toBeInTheDocument();
    expect(screen.getByText(/în DN1 · Ion/)).toBeInTheDocument();
    expect(screen.getByText('Ascuns')).toBeInTheDocument();
    expect(screen.queryByRole('button', {name: /Subiect nou/})).not.toBeInTheDocument();
    expect(screen.getByRole('link', {name: /Intră în cont/})).toBeInTheDocument();
    expect(listUrls()[0]).toMatch(/\/civic_subjects\?node=01NODE$/);
});

test('fara nod selectat: subiectele recente, fara filtru de nod', async () => {
    renderList(null);
    await screen.findByText('Gropi pe DN1');
    expect(screen.getByText('Subiecte recente')).toBeInTheDocument();
    expect(listUrls()[0]).toMatch(/\/civic_subjects$/);
});

test('paginare: 45 subiecte -> 3 pagini; pagina 2 cere ?page=2', async () => {
    renderList({id: '01NODE', name: 'Drumuri'});
    await screen.findByText('Gropi pe DN1');
    fireEvent.click(screen.getByRole('button', {name: 'Go to page 2'}));
    await waitFor(() => expect(listUrls().at(-1)).toMatch(/node=01NODE&page=2$/));
    expect(screen.getByRole('button', {name: 'Go to page 3'})).toBeInTheDocument();
});

test('utilizatorul creeaza un subiect in nodul selectat; lista se reincarca', async () => {
    setSession({token: userJwt()});
    renderList({id: '01NODE', name: 'Drumuri'});
    await screen.findByText('Gropi pe DN1');
    fireEvent.click(screen.getByRole('button', {name: /Subiect nou/}));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Drumuri')).toBeInTheDocument();
    fireEvent.change(within(dialog).getByLabelText(/Titlu/), {target: {value: 'Trotuar spart'}});
    fireEvent.change(within(dialog).getByLabelText(/Descriere/), {target: {value: 'Lângă școală.'}});
    const inainte = listUrls().length;
    fireEvent.click(within(dialog).getByRole('button', {name: 'Salvează'}));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    const post = global.fetch.mock.calls.find(([, o]) => o?.method === 'POST');
    expect(JSON.parse(post[1].body)).toEqual({node: '01NODE', type: 'ISSUE', title: 'Trotuar spart', description: 'Lângă școală.'});
    await waitFor(() => expect(listUrls().length).toBeGreaterThan(inainte));
});
