import {fireEvent, render, screen, waitFor, within} from '@testing-library/react';
import SubjectForm from './SubjectForm';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {adminJwt, fakeJwt} from '../testUtils/fakeJwt';
import {res, routedFetch, subject} from '../testUtils/subjects';

const userJwt = () => fakeJwt({username: 'ion@validez.test', displayName: 'Ion', roles: ['ROLE_USER'], id: '01ION'});
const openTypeMenu = () => fireEvent.mouseDown(within(screen.getByRole('dialog')).getAllByRole('combobox')[0]);

beforeEach(() => {
    window.localStorage.clear();
    global.fetch = routedFetch([[(u, o) => o.method === 'PATCH', (u, o) => res(subject(JSON.parse(o.body)))]]);
});

test('utilizator: doar tipurile Problema/Propunere/Petitie; fara stadiu si vizibilitate', () => {
    setSession({token: userJwt()});
    render(<AuthProvider><SubjectForm subject={subject()} onClose={() => {}} onSaved={() => {}}/></AuthProvider>);
    expect(screen.queryByLabelText('Stadiu')).not.toBeInTheDocument();
    openTypeMenu();
    const optiuni = within(screen.getByRole('listbox')).getAllByRole('option').map((o) => o.textContent);
    expect(optiuni).toEqual(['Problemă', 'Propunere', 'Petiție']);
});

test('admin: toate tipurile si stadiul/vizibilitatea la editare', () => {
    setSession({token: adminJwt()});
    render(<AuthProvider><SubjectForm subject={subject()} onClose={() => {}} onSaved={() => {}}/></AuthProvider>);
    expect(within(screen.getByRole('dialog')).getAllByRole('combobox')).toHaveLength(3);
    openTypeMenu();
    expect(within(screen.getByRole('listbox')).getAllByRole('option')).toHaveLength(8);
});

test('editare: trimite doar campurile schimbate (merge-patch)', async () => {
    setSession({token: userJwt()});
    const onSaved = jest.fn();
    render(<AuthProvider><SubjectForm subject={subject()} onClose={() => {}} onSaved={onSaved}/></AuthProvider>);
    fireEvent.change(screen.getByLabelText(/Titlu/), {target: {value: 'Gropi mari pe DN1'}});
    fireEvent.click(screen.getByRole('button', {name: 'Salvează'}));

    await waitFor(() => expect(onSaved).toHaveBeenCalled());
    const patch = global.fetch.mock.calls.find(([, o]) => o?.method === 'PATCH');
    expect(patch[1].headers['Content-Type']).toBe('application/merge-patch+json');
    expect(JSON.parse(patch[1].body)).toEqual({title: 'Gropi mari pe DN1'});
});

test('titlu prea scurt: Salveaza dezactivat', () => {
    setSession({token: userJwt()});
    render(<AuthProvider><SubjectForm node={{id: '01NODE', name: 'Cluj'}} onClose={() => {}} onSaved={() => {}}/></AuthProvider>);
    fireEvent.change(screen.getByLabelText(/Titlu/), {target: {value: 'ab'}});
    fireEvent.change(screen.getByLabelText(/Descriere/), {target: {value: 'ceva'}});
    expect(screen.getByRole('button', {name: 'Salvează'})).toBeDisabled();
});

test('după primul vot, tipul nu se mai poate schimba (și adminul vede de ce)', () => {
    setSession({token: adminJwt()});
    render(<AuthProvider><SubjectForm subject={subject({typeLocked: true})} onClose={() => {}} onSaved={() => {}}/></AuthProvider>);
    const [typeSelect] = within(screen.getByRole('dialog')).getAllByRole('combobox');
    expect(typeSelect).toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByText(/Tipul nu se mai poate schimba după primul vot/)).toBeInTheDocument();
});

test('G.3: subiectul nou cere aria; județul se caută și se trimite ca cod SIRUTA', async () => {
    setSession({token: userJwt()});
    global.fetch = routedFetch([
        [(u) => u.includes('/administrative_units'), () => res({items: [{code: '127', name: 'JUDEȚUL CLUJ', level: 'COUNTY', county: null}]})],
        [(u, o) => o.method === 'POST', (u, o) => res(subject({id: '01NEW', ...JSON.parse(o.body)}), 201)],
    ]);
    const onSaved = jest.fn();
    render(<AuthProvider><SubjectForm node={{id: '01NODE', name: 'Cluj'}} onClose={() => {}} onSaved={onSaved}/></AuthProvider>);
    fireEvent.change(screen.getByLabelText(/Titlu/), {target: {value: 'Drum județean'}});
    fireEvent.change(screen.getByLabelText(/Descriere/), {target: {value: 'Gropi'}});
    expect(screen.getByRole('status')).toHaveTextContent('Alege aria subiectului');
    fireEvent.click(screen.getByRole('radio', {name: 'Județean'}));
    expect(screen.getByRole('status')).toHaveTextContent('Alege cel puțin un județ');
    expect(screen.getByRole('button', {name: 'Salvează'})).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Județe'), {target: {value: 'clu'}});
    fireEvent.click(await screen.findByRole('option', {name: 'Județul Cluj'}));
    fireEvent.click(screen.getByRole('button', {name: 'Salvează'}));
    await waitFor(() => expect(onSaved).toHaveBeenCalled());
    const post = global.fetch.mock.calls.find(([, o]) => o?.method === 'POST');
    expect(JSON.parse(post[1].body)).toMatchObject({scope: 'COUNTY', areaUnits: ['127']});
    expect(global.fetch.mock.calls.find(([u]) => u.includes('/administrative_units'))[0]).toContain('level=COUNTY&q=clu');
});

test('G.3: subiectul vechi fără arie se editează fără ea; completarea ariei trimite scope și unitățile', async () => {
    setSession({token: userJwt()});
    const onSaved = jest.fn();
    render(<AuthProvider><SubjectForm subject={subject()} onClose={() => {}} onSaved={onSaved}/></AuthProvider>);
    expect(screen.getByText(/Arie nespecificată/)).toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'Salvează'})).toBeEnabled();
    fireEvent.click(screen.getByRole('radio', {name: 'Național'}));
    fireEvent.click(screen.getByRole('button', {name: 'Salvează'}));
    await waitFor(() => expect(onSaved).toHaveBeenCalled());
    const patch = global.fetch.mock.calls.find(([, o]) => o?.method === 'PATCH');
    expect(JSON.parse(patch[1].body)).toEqual({scope: 'NATIONAL', areaUnits: []});
});

test('G.3: aria locală arată localitatea din pin și nu cere alta', () => {
    setSession({token: userJwt()});
    const s = subject({area: {scope: 'LOCAL', units: [], effective: [{code: '55008', name: 'MUNICIPIUL DEJ', level: 'UAT', fromPin: true}]},
        location: {lat: 47.14, lng: 23.87, source: 'MANUAL', administrative: {state: 'RESOLVED', unit: {code: '55008', name: 'MUNICIPIUL DEJ'}, candidates: []}}});
    render(<AuthProvider><SubjectForm subject={s} onClose={() => {}} onSaved={() => {}}/></AuthProvider>);
    expect(screen.getByText('Municipiul Dej', {selector: 'strong'})).toBeInTheDocument();
    expect(screen.queryByText(/Arie nespecificată/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'Salvează'})).toBeEnabled();
});
