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
    expect(within(screen.getByRole('listbox')).getAllByRole('option')).toHaveLength(7);
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
