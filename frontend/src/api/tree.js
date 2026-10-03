import {API_ENDPOINT} from '../config';
import {apiFetch} from './http';

const LD = 'application/ld+json';
const URL = `${API_ENDPOINT}/tree_nodes`;

/** Mesaj lizibil din erorile API Platform (violations / detail). */
const errorMessage = (body, status) =>
    body?.violations?.map((v) => v.message).join(' ')
    || body?.detail
    || body?.description
    || ({401: 'Sesiunea a expirat. Intră din nou.', 403: 'Doar administratorii pot modifica arborele.'}[status])
    || `Eroare ${status}`;

async function request(url, options = {}) {
    const res = await apiFetch(url, {...options, headers: {Accept: LD, ...(options.headers || {})}});
    const body = res.status === 204 ? null : await res.json().catch(() => null);
    if (!res.ok) {
        const err = new Error(errorMessage(body, res.status));
        err.status = res.status;
        throw err;
    }
    return body;
}

/** Un singur nivel: radacinile (parentId null) sau copiii nodului. ULID-urile raman string. */
export async function fetchChildren(parentId = null) {
    const url = parentId ? `${URL}?parent=${encodeURIComponent(parentId)}` : URL;
    const body = await request(url);
    return body?.member ?? body?.['hydra:member'] ?? [];
}

export const createNode = (name, parentId = null) => request(URL, {
    method: 'POST',
    headers: {'Content-Type': LD},
    body: JSON.stringify({name, parent: parentId}),
});

export const renameNode = (id, name) => request(`${URL}/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    headers: {'Content-Type': 'application/merge-patch+json'},
    body: JSON.stringify({name}),
});
