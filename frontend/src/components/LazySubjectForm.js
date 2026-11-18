import {lazy, Suspense} from 'react';

// Viteză (audit 18.11.2026): formularul (hartă, arie, AI) se descarcă la „Subiect nou” / „Editează”, nu la deschiderea site-ului.
const SubjectForm = lazy(() => import('./SubjectForm'));

export default function LazySubjectForm(props) {
    return <Suspense fallback={null}><SubjectForm {...props}/></Suspense>;
}
