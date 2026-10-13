import {Navigate, useParams} from 'react-router-dom';
import CategoryPage from './CategoryPage';
import SubjectBySlugPage from './SubjectBySlugPage';

/**
 * ADR-0008: `/categorie/{cale}` = categorie; `/categorie/{cale}/subiect/{slug}` = subiect.
 * `subiect` e slug rezervat pentru categorii, deci prima apariție separă calea de slug.
 */
export default function CategoryRoute() {
    const segments = (useParams()['*'] ?? '').split('/').filter(Boolean);
    const i = segments.indexOf('subiect');
    if (i === 0 || segments.length === 0) return <Navigate to="/" replace/>;
    if (i > 0) {
        if (segments.length !== i + 2) return <Navigate to={`/categorie/${segments.slice(0, i).join('/')}`} replace/>;
        return <SubjectBySlugPage slug={segments[i + 1]}/>;
    }
    return <CategoryPage path={segments.join('/')}/>;
}
