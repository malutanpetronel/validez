// ADR-0008: forma URL-urilor publice — /categorie/{cale} și /categorie/{cale}/subiect/{slug}.
// `subiect` e slug rezervat pentru categorii, deci prima apariție separă calea categoriei de slug.

/** `/categorie/a/b/subiect/x` → `a/b` */
export const categoryPathOf = (canonicalPath) => canonicalPath?.match(/^\/categorie\/(.+)\/subiect\/[^/]+$/)?.[1] ?? null;

export const categoryUrl = (slugPath) => (slugPath ? `/categorie/${slugPath}` : null);

/**
 * null = nu e un URL de categorie; {categoryPath} / {categoryPath, subjectSlug};
 * {invalid, categoryPath} = cale incompletă (`/categorie`, `/categorie/a/subiect`), de dus la categorie sau acasă.
 */
export const parseTarget = (pathname) => {
    const m = pathname.match(/^\/categorie(?:\/(.*))?$/);
    if (!m) return null;
    const segs = (m[1] ?? '').split('/').filter(Boolean);
    const i = segs.indexOf('subiect');
    if (segs.length === 0 || i === 0) return {invalid: true, categoryPath: null};
    if (i === -1) return {categoryPath: segs.join('/')};
    if (segs.length === i + 2) return {categoryPath: segs.slice(0, i).join('/'), subjectSlug: segs[i + 1]};
    return {invalid: true, categoryPath: segs.slice(0, i).join('/')};
};
