// Operatii imutabile pe structura rc-tree. Cheia unui nod = ULID-ul lui (string), fara conversie numerica.

/** Nod API -> nod rc-tree. children ramane nedefinit pana la incarcarea ramurii (loadData). */
export const toTreeNode = (n) => ({
    key: n.id,
    title: n.name,
    isLeaf: !n.hasChildren,
    parentId: n.parentId ?? null,
});

export const updateNode = (nodes, key, fn) => nodes.map((n) => {
    if (n.key === key) return fn(n);
    return n.children ? {...n, children: updateNode(n.children, key, fn)} : n;
});

export const findNode = (nodes, key) => {
    for (const n of nodes) {
        if (n.key === key) return n;
        const found = n.children ? findNode(n.children, key) : null;
        if (found) return found;
    }
    return null;
};

export const setChildren = (nodes, key, children) =>
    updateNode(nodes, key, (n) => ({...n, children, isLeaf: children.length === 0}));

/**
 * Adauga un copil nou. Daca ramura parintelui nu e inca incarcata, nu inventam copiii:
 * doar o marcam expandabila; urmatoarea incarcare aduce lista completa de pe server.
 */
export const appendChild = (nodes, parentKey, child) => {
    if (parentKey === null) return [...nodes, child];
    return updateNode(nodes, parentKey, (n) => (
        n.children ? {...n, isLeaf: false, children: [...n.children, child]} : {...n, isLeaf: false}
    ));
};

export const renameInTree = (nodes, key, title) => updateNode(nodes, key, (n) => ({...n, title}));

/** Toate nodurile incarcate, dupa cheie. */
export const indexByKey = (nodes, map = new Map()) => {
    for (const n of nodes) {
        map.set(n.key, n);
        if (n.children) indexByKey(n.children, map);
    }
    return map;
};

const siblingsOf = (tree, parentKey) => (parentKey === null ? tree : findNode(tree, parentKey)?.children ?? []);

/**
 * Tinta unei mutari prin drag and drop, in semantica API-ului (POST /tree_nodes/{id}/move):
 * position = indexul printre fratii de la destinatie, FARA nodul mutat.
 *
 * relative (din rc-tree): -1 = intre randuri, deasupra tintei; 0 = pe tinta; 1 = sub tinta.
 * - pe tinta -> primul copil al tintei (acolo arata indicatorul rc-tree);
 * - sub o tinta expandata, cu copii afisati -> tot primul copil (indicatorul e deasupra copiilor);
 * - altfel -> frate al tintei, inainte sau dupa ea.
 */
export function dropTarget(tree, {dragKey, dropKey, relative, dropExpanded}) {
    const drop = findNode(tree, dropKey);
    if (relative === 0 || (relative === 1 && dropExpanded && drop?.children?.length)) {
        return {parentId: dropKey, position: 0};
    }
    const parentId = drop?.parentId ?? null;
    const siblings = siblingsOf(tree, parentId).map((n) => n.key).filter((k) => k !== dragKey);
    const index = siblings.indexOf(dropKey);
    return {parentId, position: relative === -1 ? index : index + 1};
}

/** Mutare care nu schimba nimic (acelasi parinte, aceeasi pozitie) - nu ajunge la server. */
export function isNoopMove(tree, dragKey, {parentId, position}) {
    const drag = findNode(tree, dragKey);
    if (!drag || (drag.parentId ?? null) !== parentId) return false;
    return siblingsOf(tree, parentId).findIndex((n) => n.key === dragKey) === position;
}

/**
 * Inlocuieste un nivel (radacinile sau copiii lui parentKey) cu lista primita de la server.
 * Nodurile care exista deja isi pastreaza ramurile incarcate: intai din arborele curent,
 * apoi din `previous` (arborele dinaintea mutarii - de acolo vine subarborele nodului mutat).
 */
export function replaceLevel(tree, parentKey, apiNodes, previous = new Map()) {
    const current = indexByKey(tree);
    const fresh = apiNodes.map((api) => {
        const node = toTreeNode(api);
        const known = current.get(node.key) ?? previous.get(node.key);
        return known?.children !== undefined && !node.isLeaf ? {...node, children: known.children} : node;
    });
    if (parentKey === null) return fresh;
    return updateNode(tree, parentKey, (n) => ({...n, children: fresh, isLeaf: fresh.length === 0}));
}
