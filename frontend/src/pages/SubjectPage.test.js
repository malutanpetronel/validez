import {render, screen} from '@testing-library/react';
import {MemoryRouter, Route, Routes} from 'react-router-dom';
import SubjectPage from './SubjectPage';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {fakeJwt} from '../testUtils/fakeJwt';
import {res, routedFetch, subject} from '../testUtils/subjects';

const jwt = (id, roles = ['ROLE_USER']) => fakeJwt({username: `${id}@validez.test`, displayName: id, roles, id});
const renderPage = () => render(
    <AuthProvider>
        <MemoryRouter initialEntries={['/subiecte/01SUBJ']}>
            <Routes><Route path="/subiecte/:id" element={<SubjectPage/>}/></Routes>
        </MemoryRouter>
    </AuthProvider>,
);

beforeEach(() => {
    window.localStorage.clear();
    global.fetch = routedFetch([[(u) => u.endsWith('/civic_subjects/01SUBJ'), () => res(subject())]]);
});

test('detaliu public: titlu, nod, autor, descriere; fara Editeaza pentru vizitator', async () => {
    renderPage();
    expect(await screen.findByRole('heading', {name: 'Gropi pe DN1'})).toBeInTheDocument();
    expect(screen.getByText('Calitate')).toBeInTheDocument();
    expect(screen.getByText('Multe gropi.')).toBeInTheDocument();
    expect(screen.queryByRole('button', {name: /Editează/})).not.toBeInTheDocument();
});

test('autorul vede Editeaza, alt utilizator nu', async () => {
    setSession({token: jwt('01ION')});
    const {unmount} = renderPage();
    expect(await screen.findByRole('button', {name: /Editează/})).toBeInTheDocument();
    unmount();

    setSession({token: jwt('01MARIA')});
    renderPage();
    await screen.findByRole('heading', {name: 'Gropi pe DN1'});
    expect(screen.queryByRole('button', {name: /Editează/})).not.toBeInTheDocument();
});

test('subiect ascuns sau inexistent: mesaj clar', async () => {
    global.fetch = routedFetch([[() => true, () => res({detail: 'Not Found'}, 404)]]);
    renderPage();
    expect(await screen.findByText('Subiectul nu există sau nu e vizibil.')).toBeInTheDocument();
    expect(screen.queryByText(/Not Found/)).not.toBeInTheDocument();
});

test('autorul vede clar că subiectul său așteaptă aprobarea', async () => {
    setSession({token: jwt('01ION')});
    global.fetch = routedFetch([[(u) => u.endsWith('/civic_subjects/01SUBJ'), () => res(subject({visibility: 'PENDING'}))]]);
    renderPage();
    expect(await screen.findByText('În așteptarea aprobării')).toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'Editează'})).toBeInTheDocument();
});
