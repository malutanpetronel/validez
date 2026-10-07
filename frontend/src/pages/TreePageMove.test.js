import {act, fireEvent, render, screen, waitFor} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import TreePage from './TreePage';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {adminJwt} from '../testUtils/fakeJwt';

// rc-tree simulat: arborele principal (are prop-ul draggable) si cel din dialogul „Mută în…".
let mockMain;
let mockPicker;
jest.mock('rc-tree', () => (props) => {
    const main = props.draggable !== undefined;
    if (main) mockMain = props; else mockPicker = props;
    return <div data-testid={main ? 'tree' : 'picker'} tabIndex={0}>{props.treeData.map((n) => n.title).join(',')}</div>;
});

const ok = (body, status = 200) => Promise.resolve({ok: status < 300, status, json: () => Promise.resolve(body)});
const node = (id, parentId = null) => ({id, name: id, hasChildren: false, parentId});

let moveStatus;
const moves = () => global.fetch.mock.calls
    .filter(([url]) => url.endsWith('/move'))
    .map(([url, opts]) => ({id: url.split('/').at(-2), ...JSON.parse(opts.body)}));

beforeEach(() => {
    window.localStorage.clear();
    moveStatus = 200;
    global.fetch = jest.fn((url) => {
        if (url.endsWith('/move')) {
            return moveStatus === 200 ? ok(node('X')) : ok({detail: 'Nodul părinte nu există.'}, moveStatus);
        }
        if (url.includes('?parent=')) return ok({member: []});
        return ok({member: [node('A'), node('B'), node('C')]});
    });
});

const renderAsAdmin = async () => {
    setSession({token: adminJwt()});
    render(<AuthProvider><MemoryRouter><TreePage/></MemoryRouter></AuthProvider>);
    await screen.findByText('A,B,C');
};
const select = (key) => act(() => mockMain.onSelect([key]));
const openActions = () => fireEvent.click(screen.getByRole('button', {name: 'Acțiuni pentru nodul selectat'}));

test('Sus/Jos: pozitia API exclude nodul mutat; butoanele se dezactiveaza la capete', async () => {
    await renderAsAdmin();

    await select('A');
    openActions();
    expect(screen.getByRole('menuitem', {name: 'Mai sus'})).toHaveAttribute('aria-disabled', 'true');
    fireEvent.keyDown(screen.getByRole('menu'), {key: 'Escape'});
    await waitFor(() => expect(screen.queryByRole('menu')).not.toBeInTheDocument());
    await select('C');
    openActions();
    expect(screen.getByRole('menuitem', {name: 'Mai jos'})).toHaveAttribute('aria-disabled', 'true');
    fireEvent.keyDown(screen.getByRole('menu'), {key: 'Escape'});
    await waitFor(() => expect(screen.queryByRole('menu')).not.toBeInTheDocument());

    await select('B');
    openActions();
    fireEvent.click(screen.getByRole('menuitem', {name: 'Mai sus'}));
    await waitFor(() => expect(moves()).toEqual([{id: 'B', parent: null, position: 0}]));
    await waitFor(() => expect(screen.queryByRole('menu')).not.toBeInTheDocument());

    openActions();
    await waitFor(() => expect(screen.getByRole('menuitem', {name: 'Mai jos'})).not.toHaveAttribute('aria-disabled', 'true'));
    fireEvent.click(screen.getByRole('menuitem', {name: 'Mai jos'}));
    await waitFor(() => expect(moves().at(-1)).toEqual({id: 'B', parent: null, position: 2}));
});

test('Alt+↓ pe arbore muta nodul selectat mai jos', async () => {
    await renderAsAdmin();
    await select('A');
    fireEvent.keyDown(screen.getByTestId('tree'), {key: 'ArrowDown', altKey: true});
    await waitFor(() => expect(moves()).toEqual([{id: 'A', parent: null, position: 1}]));
});

test('Mută în…: nodul mutat lipseste din destinatii; alegerea parintelui trimite mutarea', async () => {
    await renderAsAdmin();
    await select('C');
    openActions();
    fireEvent.click(screen.getByRole('menuitem', {name: /Mută în/}));

    // destinatiile vin asincron de la server: asteptam continutul, nu doar elementul
    await waitFor(() => expect(screen.getByTestId('picker')).toHaveTextContent('A,B'));
    expect(screen.getByTestId('picker')).not.toHaveTextContent('C');

    await act(() => mockPicker.onSelect(['A']));
    await screen.findByText('Singurul copil');
    fireEvent.click(screen.getByRole('button', {name: 'Mută'}));

    await waitFor(() => expect(moves()).toEqual([{id: 'C', parent: 'A', position: 0}]));
    expect(await screen.findByText('Nod mutat.')).toBeInTheDocument();
});

test('Mută în… cu locul curent nu trimite nimic si spune de ce', async () => {
    await renderAsAdmin();
    await select('B');
    openActions();
    fireEvent.click(screen.getByRole('menuitem', {name: /Mută în/}));
    await screen.findByText(/După „A”/); // pozitia curenta a lui B, preselectata
    fireEvent.click(screen.getByRole('button', {name: 'Mută'}));

    expect(await screen.findByText('Nodul este deja în acest loc.')).toBeInTheDocument();
    expect(moves()).toEqual([]);
});

test('mutare respinsa: eroarea e afisata si structura se reincarca de pe server', async () => {
    moveStatus = 422;
    await renderAsAdmin();
    const listari = () => global.fetch.mock.calls.filter(([url]) => url.endsWith('/tree_nodes')).length;
    const inainte = listari();

    await select('B');
    openActions();
    await waitFor(() => expect(screen.getByRole('menuitem', {name: 'Mai jos'})).not.toHaveAttribute('aria-disabled', 'true'));
    fireEvent.click(screen.getByRole('menuitem', {name: 'Mai jos'}));

    expect(await screen.findByText('Nodul părinte nu există.')).toBeInTheDocument();
    await waitFor(() => expect(listari()).toBe(inainte + 1));
    expect(screen.getByTestId('tree')).toHaveTextContent('A,B,C');
});

test('pe mobil redenumirea este accesibilă din meniu, iar ajutorul se deschide prin apăsare', async () => {
    const originalMatchMedia = window.matchMedia;
    window.matchMedia = jest.fn((query) => ({matches: query.includes('max-width:599'), media: query,
        addEventListener: jest.fn(), removeEventListener: jest.fn(), addListener: jest.fn(), removeListener: jest.fn()}));
    try {
        await renderAsAdmin();
        await select('B');
        expect(screen.queryByRole('button', {name: 'Redenumește'})).not.toBeInTheDocument();
        openActions();
        fireEvent.click(screen.getByRole('menuitem', {name: 'Redenumește'}));
        expect(await screen.findByRole('dialog', {name: 'Redenumește nodul'})).toBeInTheDocument();
        expect(screen.getByLabelText('Nume')).toHaveValue('B');
        fireEvent.click(screen.getByRole('button', {name: 'Renunță'}));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', {name: 'Ajutor pentru arbore'}));
        expect(await screen.findByRole('dialog', {name: 'Lucrul cu arborele'})).toBeInTheDocument();
    } finally {
        window.matchMedia = originalMatchMedia;
    }
});
