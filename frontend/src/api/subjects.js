import {API_ENDPOINT} from '../config';
import {apiFetch} from './http';

const LD = 'application/ld+json';
const URL = `${API_ENDPOINT}/civic_subjects`;

// 404: mesajul nostru (API Platform trimite „Not Found"); 403/422: mesajele serverului sunt deja în română.
const errorMessage = (body, status) =>
    body?.violations?.map((v) => v.message).join(' ')
    || (status === 404 ? 'Subiectul nu există sau nu e vizibil.' : null)
    || body?.detail
    || ({401: 'Sesiunea a expirat. Intră din nou.', 403: 'Nu ai dreptul să faci această modificare.', 404: 'Subiectul nu există sau nu e vizibil.'}[status])
    || `Eroare ${status}`;

async function request(url, options = {}) {
    const res = await apiFetch(url, {...options, headers: {Accept: LD, ...(options.headers || {})}});
    const body = await res.json().catch(() => null);
    if (!res.ok) {
        const err = new Error(errorMessage(body, res.status));
        err.status = res.status;
        throw err;
    }
    return body;
}

/** Listă paginată: node = subarborele; scope=direct = doar categoria; fără node = cele mai noi. */
export async function fetchSubjects({node = null, type = '', stage = '', page = 1, scope = ''} = {}) {
    const q = new URLSearchParams();
    if (node) q.set('node', node);
    if (scope) q.set('scope', scope);
    if (type) q.set('type', type);
    if (stage) q.set('stage', stage);
    if (page > 1) q.set('page', String(page));
    const body = await request(q.toString() ? `${URL}?${q}` : URL);
    return {items: body?.member ?? body?.['hydra:member'] ?? [], total: body?.totalItems ?? body?.['hydra:totalItems'] ?? 0};
}

export const fetchSubject = (id) => request(`${URL}/${encodeURIComponent(id)}`);

export const createSubject = ({node, type, title, description}) => request(URL, {
    method: 'POST',
    headers: {'Content-Type': LD},
    body: JSON.stringify({node, type, title, description}),
});

/** Merge-patch: doar câmpurile trimise se schimbă. */
export const updateSubject = (id, changes) => request(`${URL}/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    headers: {'Content-Type': 'application/merge-patch+json'},
    body: JSON.stringify(changes),
});

export const PER_PAGE = 20;
