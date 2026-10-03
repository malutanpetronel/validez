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
