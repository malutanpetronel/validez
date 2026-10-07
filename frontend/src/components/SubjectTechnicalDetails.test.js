import {fireEvent, render, screen, waitFor, within} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import SubjectForm from './SubjectForm';
import SubjectList from './SubjectList';
import SubjectPage from '../pages/SubjectPage';
import SubjectEstimate from './SubjectEstimate';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {fakeJwt} from '../testUtils/fakeJwt';
import {res, routedFetch, subject} from '../testUtils/subjects';

const proposal = (extra = {}) => subject({type: 'PROPOSAL', ...extra});
const login = (id = '01ION', roles = ['ROLE_USER']) => setSession({token: fakeJwt({username: `${id}@test.ro`, displayName: id, id, roles})});
const wrapper = (children) => render(<AuthProvider><MemoryRouter>{children}</MemoryRouter></AuthProvider>);
const methodCalls = (method) => global.fetch.mock.calls.filter(([, opts]) => opts?.method === method);
const fillProposal = () => {
    fireEvent.mouseDown(screen.getByLabelText('Tip'));
    fireEvent.click(within(screen.getByRole('listbox')).getByRole('option', {name: 'Propunere'}));
    fireEvent.change(screen.getByLabelText(/Titlu/), {target: {value: 'Parcare inteligentă'}});
    fireEvent.change(screen.getByLabelText(/Descriere/), {target: {value: 'Descriere publică'}});
};
beforeEach(() => {
    window.localStorage.clear();
    global.fetch = routedFetch([
        [(url, opts) => opts.method === 'PUT', (url, opts) => res(JSON.parse(opts.body))],
        [(url) => url.endsWith('/note'), () => res({technicalNotes: ''})],
        [(url, opts) => opts.method === 'POST' || opts.method === 'PATCH', (url, opts) => res(proposal(JSON.parse(opts.body)))],
    ]);
});

test('creare: moneda există doar cu suma; zero se salvează ca string, cu explicație', async () => {
    login();
    const saved = jest.fn();
    wrapper(<SubjectForm node={{id: '01NODE', name: 'Drumuri'}} onClose={() => {}} onSaved={saved}/>);
    fillProposal();
    expect(screen.getByLabelText('Monedă')).not.toHaveTextContent(/RON|EUR|USD|GBP/);
    expect(screen.getByLabelText('Monedă')).toHaveAttribute('aria-disabled', 'true');
    fireEvent.change(screen.getByLabelText('Cost estimativ'), {target: {value: '0'}});
    expect(screen.getByLabelText('Monedă')).toHaveTextContent('RON');
    expect(screen.getByRole('button', {name: 'Salvează'})).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Ce acoperă estimarea'), {target: {value: 'Pentru un loc'}});
    fireEvent.click(screen.getByRole('button', {name: 'Salvează'}));
    await waitFor(() => expect(saved).toHaveBeenCalled());
    expect(JSON.parse(methodCalls('POST')[0][1].body)).toMatchObject({costEstimate: '0', costCurrency: 'RON', costEstimateScope: 'Pentru un loc'});
    expect(methodCalls('PUT')).toHaveLength(0);
});

test('golirea sumei elimină toate câmpurile publice, fără să schimbe nota', async () => {
    login();
    const saved = jest.fn();
    wrapper(<SubjectForm subject={proposal({costEstimate: '940.00', costCurrency: 'EUR', costEstimateScope: 'Pentru un loc'})} onClose={() => {}} onSaved={saved}/>);
    await waitFor(() => expect(screen.getByLabelText('Note tehnice personale')).toBeEnabled());
    fireEvent.change(screen.getByLabelText('Cost estimativ'), {target: {value: ''}});
    expect(screen.getByLabelText('Ce acoperă estimarea')).toHaveValue('');
    fireEvent.click(screen.getByRole('button', {name: 'Salvează'}));
    await waitFor(() => expect(saved).toHaveBeenCalled());
    expect(JSON.parse(methodCalls('PATCH')[0][1].body)).toEqual({costEstimate: null, costCurrency: null, costEstimateScope: null});
    expect(methodCalls('PUT')).toHaveLength(0);
});

test('nota se salvează separat; reîncercarea după eșec nu creează un duplicat', async () => {
    login();
    let attempts = 0;
    global.fetch = routedFetch([
        [(url, opts) => opts.method === 'POST', (url, opts) => res(proposal(JSON.parse(opts.body)))],
        [(url, opts) => opts.method === 'PUT', (url, opts) => ++attempts === 1 ? res({detail: 'Eroare temporară'}, 503) : res(JSON.parse(opts.body))],
    ]);
    const saved = jest.fn();
    wrapper(<SubjectForm node={{id: '01NODE', name: 'Drumuri'}} onClose={() => {}} onSaved={saved}/>);
    fillProposal();
    fireEvent.change(screen.getByLabelText('Note tehnice personale'), {target: {value: 'Senzori și alimentare'}});
    fireEvent.click(screen.getByRole('button', {name: 'Salvează'}));
    expect(await screen.findByText(/Subiectul a fost salvat, dar nota personală nu/)).toBeInTheDocument();
    expect(saved).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', {name: 'Salvează'}));
    await waitFor(() => expect(saved).toHaveBeenCalled());
    expect(methodCalls('POST')).toHaveLength(1);
    expect(JSON.parse(methodCalls('POST')[0][1].body)).not.toHaveProperty('technicalNotes');
    expect(methodCalls('PUT')).toHaveLength(2);
    expect(methodCalls('PUT')[1][0]).toMatch(/\/01SUBJ\/note$/);
});

test('adminul care nu este autor nu încarcă nota nici în formular', async () => {
    login('01ADMIN', ['ROLE_ADMIN']);
    wrapper(<SubjectForm subject={proposal()} onClose={() => {}} onSaved={() => {}}/>);
    expect(screen.queryByLabelText('Note tehnice personale')).not.toBeInTheDocument();
    expect(global.fetch.mock.calls.filter(([url]) => url.endsWith('/note'))).toHaveLength(0);
});

test('schimbarea tipului păstrează estimarea: trimite numai tipul', async () => {
    login();
    const saved = jest.fn();
    wrapper(<SubjectForm subject={proposal({costEstimate: '940.00', costCurrency: 'EUR', costEstimateScope: 'Pentru un loc'})} onClose={() => {}} onSaved={saved}/>);
    await waitFor(() => expect(screen.getByLabelText('Note tehnice personale')).toBeEnabled());
    fireEvent.mouseDown(screen.getByLabelText('Tip'));
    fireEvent.click(within(screen.getByRole('listbox')).getByRole('option', {name: 'Problemă'}));
    expect(screen.queryByLabelText('Cost estimativ')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', {name: 'Salvează'}));
    await waitFor(() => expect(saved).toHaveBeenCalled());
    expect(JSON.parse(methodCalls('PATCH')[0][1].body)).toEqual({type: 'ISSUE'});
    expect(methodCalls('PUT')).toHaveLength(0);
});

test('lista publică afișează suma și primul rând complet, fără cereri pentru note', async () => {
    const scope = 'Pentru un loc de parcare inteligentă, inclusiv echipamentele și montajul';
    global.fetch = routedFetch([[() => true, () => res({member: [proposal({costEstimate: '940.10', costCurrency: 'EUR', costEstimateScope: `${scope}\nFără mentenanță`})], totalItems: 1})]]);
    wrapper(<SubjectList node={{id: '01NODE', name: 'Drumuri'}} scope="direct"/>);
    expect(await screen.findByText('Estimarea autorului: 940,10 EUR')).toBeInTheDocument();
    expect(screen.getByText(scope)).toBeInTheDocument();
    expect(screen.queryByText('Fără mentenanță')).not.toBeInTheDocument();
    expect(global.fetch.mock.calls.filter(([url]) => url.endsWith('/note'))).toHaveLength(0);
});

test('nota în detaliu este cerută doar pentru autor, nu pentru admin sau public', async () => {
    global.fetch = routedFetch([
        [(url) => url.endsWith('/note'), () => res({technicalNotes: 'Memo personal'})],
        [() => true, () => res(proposal())],
    ]);
    for (const identity of ['public', 'admin', 'author']) {
        window.localStorage.clear();
        if (identity !== 'public') login(identity === 'author' ? '01ION' : '01ADMIN', identity === 'admin' ? ['ROLE_ADMIN'] : ['ROLE_USER']);
        global.fetch.mockClear();
        const {unmount} = wrapper(<SubjectPage subjectId="01SUBJ" embedded/>);
        await screen.findByText('Multe gropi.');
        if (identity === 'author') await screen.findByText('Memo personal');
        expect(global.fetch.mock.calls.filter(([url]) => url.endsWith('/note'))).toHaveLength(identity === 'author' ? 1 : 0);
        expect(Boolean(screen.queryByText('Memo personal'))).toBe(identity === 'author');
        unmount();
    }
});

test('estimarea nu apare pentru alte tipuri sau fără explicație; zero este vizibil', () => {
    const data = {costEstimate: '0.00', costCurrency: 'RON', costEstimateScope: 'Cost total'};
    const {rerender} = render(<SubjectEstimate subject={subject(data)}/>);
    expect(screen.queryByText(/Estimarea autorului/)).not.toBeInTheDocument();
    rerender(<SubjectEstimate subject={proposal({...data, costEstimateScope: ''})}/>);
    expect(screen.queryByText(/Estimarea autorului/)).not.toBeInTheDocument();
    rerender(<SubjectEstimate subject={proposal(data)}/>);
    expect(screen.getByText('Estimarea autorului: 0 RON')).toBeInTheDocument();
});

test('salvarea dezactivată explică limita primului rând; mutarea detaliilor pe rând nou permite salvarea', async () => {
    login();
    wrapper(<SubjectForm subject={proposal()} onClose={() => {}} onSaved={() => {}}/>);
    await waitFor(() => expect(screen.getByLabelText('Note tehnice personale')).toBeEnabled());
    fireEvent.change(screen.getByLabelText('Cost estimativ'), {target: {value: '940'}});
    fireEvent.change(screen.getByLabelText('Ce acoperă estimarea'), {target: {value: 'x'.repeat(201)}});
    expect(screen.getByRole('button', {name: 'Salvează'})).toBeDisabled();
    expect(screen.getByRole('status')).toHaveTextContent('Primul rând depășește 200 de caractere');
    fireEvent.change(screen.getByLabelText('Ce acoperă estimarea'), {target: {value: `Pentru un loc\n${'x'.repeat(201)}`}});
    expect(screen.getByRole('button', {name: 'Salvează'})).toBeEnabled();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
});
