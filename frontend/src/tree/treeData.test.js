import {appendChild, findNode, renameInTree, setChildren, toTreeNode} from './treeData';

const api = (id, name, hasChildren = false, parentId = null) => ({id, name, hasChildren, parentId});

test('toTreeNode pastreaza ULID-ul ca string si deriva isLeaf', () => {
    expect(toTreeNode(api('01ABC', 'Drumuri', true))).toEqual({key: '01ABC', title: 'Drumuri', subjectCount: 0, isLeaf: false, parentId: null, slugPath: null});
    expect(toTreeNode({...api('01ABC', 'Drumuri', true), slugPath: 'drumuri'}).slugPath).toBe('drumuri');
    expect(toTreeNode(api('01DEF', 'DN1')).isLeaf).toBe(true);
});

test('setChildren incarca o ramura adanca', () => {
    let t = [toTreeNode(api('R', 'Drumuri', true))];
    t = setChildren(t, 'R', [toTreeNode(api('C', 'Cluj', true, 'R'))]);
    t = setChildren(t, 'C', [toTreeNode(api('Q', 'Calitate', false, 'C'))]);
    expect(findNode(t, 'Q').title).toBe('Calitate');
    expect(findNode(t, 'R').isLeaf).toBe(false);
});

test('appendChild sub ramura incarcata adauga la final; sub ramura neincarcata doar o face expandabila', () => {
    let t = setChildren([toTreeNode(api('R', 'Drumuri', true))], 'R', []);
    t = appendChild(t, 'R', toTreeNode(api('C', 'Cluj', false, 'R')));
    expect(findNode(t, 'R').children.map((n) => n.key)).toEqual(['C']);

    t = appendChild(t, 'C', toTreeNode(api('Q', 'Calitate', false, 'C')));
    expect(findNode(t, 'C').isLeaf).toBe(false);
    expect(findNode(t, 'C').children).toBeUndefined();
});

test('appendChild cu parinte null adauga o radacina', () => {
    expect(appendChild([], null, toTreeNode(api('R', 'Drumuri')))).toHaveLength(1);
});

test('renameInTree schimba doar titlul', () => {
    const t = renameInTree([toTreeNode(api('R', 'Calitate'))], 'R', 'Starea drumurilor');
    expect(t[0]).toMatchObject({key: 'R', title: 'Starea drumurilor'});
});

test('totalul subiectelor ramane separat de nume si de ramurile incarcate', () => {
    const node = toTreeNode({...api('R', 'Drumuri', true), subjectCount: 42});
    expect(node.subjectCount).toBe(42);
    expect(node.children).toBeUndefined();
    expect(renameInTree([node], 'R', 'Transport')[0]).toMatchObject({title: 'Transport', subjectCount: 42});
});
