import {act, render, screen, waitFor} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import TreePage from './TreePage';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {adminJwt} from '../testUtils/fakeJwt';

// Drag-ul HTML5 nu se poate simula fiabil in jsdom: inlocuim rc-tree cu un mock care
// expune props-urile, si declansam onDrop exact cum il apeleaza rc-tree 5.13.
let mockTreeProps;
jest.mock('rc-tree', () => (props) => {
    mockTreeProps = props;
    return <div data-testid="tree">{props.treeData.map((n) => n.title).join(',')}</div>;
});

const ok = (body, status = 200) => Promise.resolve({ok: status < 300, status, json: () => Promise.resolve(body)});
const node = (id) => ({id, name: id, hasChildren: false, parentId: null});

let roots;
let moveStatus;

beforeEach(() => {
    window.localStorage.clear();
    roots = [node('A'), node('B')];
    moveStatus = 200;
    global.fetch = jest.fn((url, opts = {}) => {
        if (url.endsWith('/move')) {
            if (moveStatus !== 200) return ok({detail: 'Un nod nu poate fi mutat în el însuși sau într-un descendent al lui.'}, moveStatus);
            roots = [node('B'), node('A')];
            return ok(node('A'));
        }
        return ok({member: roots});
    });
});

const renderAsAdmin = async () => {
    setSession({token: adminJwt()});
    render(<AuthProvider><MemoryRouter><TreePage/></MemoryRouter></AuthProvider>);
    await screen.findByText('A,B');
};

// A tras sub B (pos "0-1", relativ +1 -> dropPosition 2)
const dropAunderB = () => act(() => mockTreeProps.onDrop({
    dragNode: {key: 'A'},
    node: {key: 'B', pos: '0-1', expanded: false},
    dropPosition: 2,
    dropToGap: true,
}));

test('drop: trimite mutarea cu pozitia fara nodul tras si afiseaza ordinea confirmata de server', async () => {
    await renderAsAdmin();
    expect(mockTreeProps.draggable.nodeDraggable({key: 'A'})).toBe(true);
    expect(mockTreeProps.draggable.nodeDraggable({kind: 'subject'})).toBe(false);

    await dropAunderB();

    const moveCall = global.fetch.mock.calls.find(([url]) => url.endsWith('/tree_nodes/A/move'));
    expect(moveCall[1].method).toBe('POST');
    expect(JSON.parse(moveCall[1].body)).toEqual({parent: null, position: 1});
    expect(await screen.findByText('B,A')).toBeInTheDocument();
    expect(screen.getByText('Nod mutat.')).toBeInTheDocument();
});

test('drop respins de server: afiseaza eroarea si reincarca structura confirmata', async () => {
    moveStatus = 422;
    await renderAsAdmin();
    const listariInainte = global.fetch.mock.calls.filter(([url]) => !url.endsWith('/move')).length;

    await dropAunderB();

    expect(await screen.findByText(/nu poate fi mutat/)).toBeInTheDocument();
    await waitFor(() => expect(global.fetch.mock.calls.filter(([url]) => !url.endsWith('/move')).length).toBe(listariInainte + 1));
    expect(screen.getByText('A,B')).toBeInTheDocument();
});

test('drop pe acelasi loc nu ajunge la server', async () => {
    await renderAsAdmin();
    // A deasupra lui B = exact unde e deja
    await act(() => mockTreeProps.onDrop({dragNode: {key: 'A'}, node: {key: 'B', pos: '0-1', expanded: false}, dropPosition: 0, dropToGap: true}));
    expect(global.fetch.mock.calls.some(([url]) => url.endsWith('/move'))).toBe(false);
});

test('vizitatorul nu poate trage noduri', async () => {
    render(<AuthProvider><MemoryRouter><TreePage/></MemoryRouter></AuthProvider>);
    await screen.findByText('A,B');
    expect(mockTreeProps.draggable).toBe(false);
});
