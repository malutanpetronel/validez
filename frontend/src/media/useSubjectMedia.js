import {useCallback, useEffect, useState} from 'react';
import {fetchMedia} from '../api/media';

/**
 * Fotografiile și linkurile unui subiect, încărcate o singură dată pentru toată pagina (copertă + galerie).
 * `viewerKey` (de ex. id-ul utilizatorului) reîncarcă lista la autentificare: media PENDING apar doar pentru
 * cel care le-a încărcat și pentru administratori.
 */
export default function useSubjectMedia(subjectId, viewerKey) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [version, setVersion] = useState(0);
    useEffect(() => {
        let active = true;
        setError(null);
        fetchMedia(subjectId).then((d) => { if (active) setData(d); }).catch((e) => { if (active) setError(e.message); });
        return () => { active = false; };
    }, [subjectId, viewerKey, version]);
    const reload = useCallback(() => setVersion((v) => v + 1), []);
    // Un răspuns neașteptat (fără items) e tratat ca listă goală, nu oprește pagina subiectului.
    const items = Array.isArray(data?.items) ? data.items : [];

    return {
        data, error, version, reload, items,
        photos: items.filter((m) => m.kind === 'PHOTO'),
        links: items.filter((m) => m.kind === 'LINK'),
    };
}

/** Imaginea se poate încărca printr-un <img> simplu (cu cache), fără token. */
export const isPublicPhoto = (subject, m) => subject.visibility === 'PUBLISHED' && m.status === 'PUBLISHED';
