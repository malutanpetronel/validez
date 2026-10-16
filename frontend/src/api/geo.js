import {API_ENDPOINT} from '../config';
import {apiFetch} from './http';

// ADR-0012 p.6 (G.2b): încadrarea administrativă a pinului — alegerea manuală și coada de moderare.
const REASONS = {
    not_resolvable: 'Încadrarea nu mai e neclară (s-a schimbat între timp). Reîncarcă pagina.',
    not_a_candidate: 'Unitatea aleasă nu e printre variante.',
    invalid_body: 'Cerere invalidă.',
    forbidden: 'Doar autorul subiectului sau un administrator poate alege.',
};

async function request(url, options = {}) {
    let res;
    try {
        res = await apiFetch(url, {...options, headers: {Accept: 'application/json', ...(options.headers || {})}});
    } catch {
        throw new Error('Serverul nu răspunde. Verifică conexiunea.');
    }
    const body = await res.json().catch(() => null);
    if (!res.ok) {
        const err = new Error(REASONS[body?.reason] || body?.detail || `Eroare ${res.status}`);
        err.status = res.status;
        throw err;
    }
    return body;
}

/** Alege UAT-ul dintre candidați; întoarce {administrative}. */
export const resolvePinClassification = (subjectId, code) => request(
    `${API_ENDPOINT}/civic_subjects/${encodeURIComponent(subjectId)}/pin-classification`,
    {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({code})},
);

/** {items: [{subjectId, subjectTitle, subjectPath, visibility, location, administrative}]} — doar administratori. */
export const fetchPendingPinClassifications = () => request(`${API_ENDPOINT}/moderation/pin-classifications`);
