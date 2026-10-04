import {act, fireEvent, render, screen, waitFor} from '@testing-library/react';
import {MemoryRouter, Route, Routes} from 'react-router-dom';
import TreePage from './TreePage';
import SubjectsPage from './SubjectsPage';
import SubjectPage from './SubjectPage';
import {AuthProvider} from '../auth/AuthContext';

let mockTree;
jest.mock('rc-tree', () => (props) => {
    mockTree = props;
    return <div>{props.treeData.map((node) => node.title).join(',')}</div>;
});
const ok = (body) => Promise.resolve({ok: true, status: 200, json: () => Promise.resolve(body)});
const category = {id: 'category', name: 'Drumuri', hasChildren: false};
const subjects = Array.from({length: 20}, (_, i) => ({id: `s${i}`, title: `Subiect ${i}`, nodeId: 'category', nodeName: 'Drumuri', type: 'ISSUE', stage: 'OPEN', description: 'Descriere completă', createdAt: '2026-10-04', updatedAt: '2026-10-04'}));
beforeEach(() => {
    window.localStorage.clear();
    global.fetch = jest.fn((url) => {
        if (url.includes('/civic_subjects/s')) return ok(subjects[0]);
        if (url.includes('/civic_subjects')) return ok({member: subjects, totalItems: 128});
        return ok({member: url.includes('?parent=') ? [] : [category]});
    });
});
const renderPage = () => render(<AuthProvider><MemoryRouter initialEntries={['/arbore']}><Routes>
    <Route path="/arbore" element={<TreePage/>}/>
    <Route path="/arbore/:nodeId/subiecte" element={<SubjectsPage/>}/>
    <Route path="/subiecte/:id" element={<SubjectPage/>}/>
</Routes></MemoryRouter></AuthProvider>);
const openBranch = async () => {
    await screen.findByText('Drumuri');
    await act(async () => { await mockTree.loadData({key: category.id}); });
    return mockTree.treeData[0].children;
};
test('categoria fără subcategorii încarcă cinci frunze și totalul; selectarea arată detaliile', async () => {
    renderPage();
    const children = await openBranch();
    expect(children.filter((n) => n.kind === 'subject')).toHaveLength(5);
    expect(children.at(-1).title).toBe('Vezi tot (128 subiecte)');
    expect(global.fetch.mock.calls.some(([url]) => url.includes('node=category&scope=direct'))).toBe(true);
    act(() => mockTree.onSelect([children[0].key], {node: children[0]}));
    expect(await screen.findByText('Descriere completă')).toBeInTheDocument();
});
test('Vezi tot deschide lista completă cu filtre și paginare', async () => {
    renderPage();
    const children = await openBranch();
    act(() => mockTree.onSelect([children.at(-1).key], {node: children.at(-1)}));
    expect(await screen.findByText('Subiect 19')).toBeInTheDocument();
    expect(screen.getByLabelText('Tip')).toBeInTheDocument();
    expect(screen.getByLabelText('Stadiu')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('button', {name: /page 2/i})).toBeInTheDocument());
});

test('întoarcerea din listă și detaliu păstrează categoria imbricată și ramurile deschise', async () => {
    const child = {id: 'child', name: 'Cluj', parentId: category.id, hasChildren: false};
    global.fetch = jest.fn((url) => {
        if (url.includes('/civic_subjects/s')) return ok({...subjects[0], nodeId: child.id, nodeName: child.name});
        if (url.includes('/civic_subjects')) return ok({member: subjects, totalItems: 128});
        if (url.includes('?parent=category')) return ok({member: [child]});
        if (url.includes('?parent=child')) return ok({member: []});
        return ok({member: [{...category, hasChildren: true}]});
    });
    renderPage();
    await openBranch();
    await act(async () => { await mockTree.loadData({key: child.id}); });
    act(() => mockTree.onExpand([category.id, child.id]));
    act(() => mockTree.onSelect([child.id], {node: mockTree.treeData[0].children[0]}));
    const all = mockTree.treeData[0].children[0].children.at(-1);
    act(() => mockTree.onSelect([all.key], {node: all}));
    fireEvent.click(await screen.findByRole('link', {name: /^Subiect 0/}));
    expect(await screen.findByText('Descriere completă')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('link', {name: 'Înapoi la arbore'}));
    await screen.findByText('Drumuri');
    await waitFor(() => expect(mockTree.selectedKeys).toEqual([child.id]));
    expect(mockTree.expandedKeys).toEqual([category.id, child.id]);
    expect(mockTree.treeData[0].children[0].children[0].kind).toBe('subject');
    // A doua revenire, direct din lista categoriei.
    act(() => mockTree.onSelect([all.key], {node: all}));
    fireEvent.click(await screen.findByRole('link', {name: 'Înapoi la arbore'}));
    await waitFor(() => expect(mockTree.selectedKeys).toEqual([child.id]));
    expect(mockTree.expandedKeys).toEqual([category.id, child.id]);
});
