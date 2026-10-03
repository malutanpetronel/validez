import {dropTarget, indexByKey, isNoopMove, replaceLevel, setChildren, toTreeNode, findNode} from './treeData';

const api = (id, parentId = null, hasChildren = false) => ({id, name: id, parentId, hasChildren});

// Drumuri > [Cluj > [Calitate > [DN1]], Bistrita, Alba] ; Sanatate
const build = () => {
    let t = [toTreeNode(api('Drumuri', null, true)), toTreeNode(api('Sanatate'))];
    t = setChildren(t, 'Drumuri', [api('Cluj', 'Drumuri', true), api('Bistrita', 'Drumuri'), api('Alba', 'Drumuri')].map(toTreeNode));
    t = setChildren(t, 'Cluj', [toTreeNode(api('Calitate', 'Cluj', true))]);
    t = setChildren(t, 'Calitate', [toTreeNode(api('DN1', 'Calitate'))]);
    return t;
};
const keys = (nodes) => nodes.map((n) => n.key);

describe('dropTarget', () => {
    test('pe un nod -> primul lui copil', () => {
        expect(dropTarget(build(), {dragKey: 'Alba', dropKey: 'Sanatate', relative: 0})).toEqual({parentId: 'Sanatate', position: 0});
    });

    test('intre randuri: inainte / dupa tinta, cu pozitia calculata fara nodul tras', () => {
        const t = build();
        // Alba deasupra lui Cluj -> pozitia 0 printre [Cluj, Bistrita]
        expect(dropTarget(t, {dragKey: 'Alba', dropKey: 'Cluj', relative: -1})).toEqual({parentId: 'Drumuri', position: 0});
        // Cluj sub Bistrita -> fara Cluj fratii sunt [Bistrita, Alba]; dupa Bistrita = 1
        expect(dropTarget(t, {dragKey: 'Cluj', dropKey: 'Bistrita', relative: 1})).toEqual({parentId: 'Drumuri', position: 1});
        // DN1 sub Sanatate, la radacina
        expect(dropTarget(t, {dragKey: 'DN1', dropKey: 'Sanatate', relative: 1})).toEqual({parentId: null, position: 2});
    });

    test('sub un nod expandat cu copii afisati -> primul lui copil', () => {
        expect(dropTarget(build(), {dragKey: 'Alba', dropKey: 'Cluj', relative: 1, dropExpanded: true})).toEqual({parentId: 'Cluj', position: 0});
    });
});

test('isNoopMove: acelasi loc nu ajunge la server', () => {
    const t = build();
    expect(isNoopMove(t, 'Bistrita', {parentId: 'Drumuri', position: 1})).toBe(true);
    expect(isNoopMove(t, 'Bistrita', {parentId: 'Drumuri', position: 0})).toBe(false);
    expect(isNoopMove(t, 'Bistrita', {parentId: 'Cluj', position: 0})).toBe(false);
});

describe('replaceLevel dupa mutare (nivelurile reincarcate de pe server)', () => {
    test('reordonare intre frati', () => {
        const t = replaceLevel(build(), 'Drumuri', [api('Alba', 'Drumuri'), api('Cluj', 'Drumuri', true), api('Bistrita', 'Drumuri')]);
        expect(keys(findNode(t, 'Drumuri').children)).toEqual(['Alba', 'Cluj', 'Bistrita']);
        expect(keys(findNode(t, 'Calitate').children)).toEqual(['DN1']);
    });

    test('mutare in jos: subarborele mutat isi pastreaza ramurile incarcate', () => {
        const before = build();
        const prev = indexByKey(before);
        let t = replaceLevel(before, 'Drumuri', [api('Bistrita', 'Drumuri', true), api('Alba', 'Drumuri')], prev);
        t = replaceLevel(t, 'Bistrita', [api('Cluj', 'Bistrita', true)], prev);

        expect(keys(findNode(t, 'Drumuri').children)).toEqual(['Bistrita', 'Alba']);
        expect(keys(findNode(t, 'Bistrita').children)).toEqual(['Cluj']);
        expect(findNode(t, 'Cluj').parentId).toBe('Bistrita');
        expect(keys(findNode(t, 'Calitate').children)).toEqual(['DN1']);
    });

    test('mutare in sus: parintele vechi ramas fara copii devine frunza, nodul nu ramane dublat', () => {
        const before = build();
        const prev = indexByKey(before);
        let t = replaceLevel(before, 'Calitate', [], prev);
        t = replaceLevel(t, 'Drumuri', [api('Cluj', 'Drumuri', true), api('DN1', 'Drumuri'), api('Bistrita', 'Drumuri'), api('Alba', 'Drumuri')], prev);

        expect(findNode(t, 'Calitate').isLeaf).toBe(true);
        expect(findNode(t, 'Calitate').children).toEqual([]);
        expect(keys(findNode(t, 'Drumuri').children)).toEqual(['Cluj', 'DN1', 'Bistrita', 'Alba']);
        expect([...indexByKey(t).keys()].filter((k) => k === 'DN1')).toHaveLength(1);
    });

    test('mutare la radacina', () => {
        const before = build();
        const prev = indexByKey(before);
        let t = replaceLevel(before, 'Cluj', [], prev);
        t = replaceLevel(t, null, [api('Drumuri', null, true), api('Calitate', null, true), api('Sanatate')], prev);
        expect(keys(t)).toEqual(['Drumuri', 'Calitate', 'Sanatate']);
        expect(keys(findNode(t, 'Calitate').children)).toEqual(['DN1']);
    });
});
