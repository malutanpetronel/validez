import {API_BASE, API_ENDPOINT} from '../config';
import {apiFetch} from './http';

// ADR-0011: fotografiile și linkurile subiectelor (Step 3). Erorile serverului sunt deja în română (detail).
const subjectUrl = (id) => `${API_ENDPOINT}/civic_subjects/${encodeURIComponent(id)}`;
const mediaUrl = (id) => `${API_ENDPOINT}/media/${encodeURIComponent(id)}`;

const FALLBACK = {
    401: 'Sesiunea a expirat. Intră din nou.',
    403: 'Nu ai dreptul să faci această modificare.',
    404: 'Nu există sau nu mai e vizibil.',
    413: 'Fotografia depășește 10 MB.',
};

async function request(url, options = {}) {
    let res;
    try {
        res = await apiFetch(url, {...options, headers: {Accept: 'application/json', ...(options.headers || {})}});
    } catch {
        throw new Error('Serverul nu răspunde. Verifică conexiunea.');
    }
    if (res.status === 204) return null;
    const body = await res.json().catch(() => null);
    if (!res.ok) {
        // 413 poate veni și de la proxy (pagină HTML, fără detail).
        const err = new Error((res.status !== 413 && body?.detail) || FALLBACK[res.status] || body?.detail || `Eroare ${res.status}`);
        err.status = res.status;
        throw err;
    }
    return body;
}

/** {items, max, maxLinks}; media PENDING apar doar pentru autor și administratori. */
export const fetchMedia = (subjectId) => request(`${subjectUrl(subjectId)}/media`);
export const fetchMediaHistory = (subjectId) => request(`${subjectUrl(subjectId)}/media/history`);

export function uploadPhoto(subjectId, file) {
    const form = new FormData();
    form.append('file', file, file.name || 'fotografie.jpg');
    return request(`${subjectUrl(subjectId)}/media`, {method: 'POST', body: form});
}

export const addLink = (subjectId, url, label) => request(`${subjectUrl(subjectId)}/links`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({url, label: label || null}),
});

export const removeMedia = (mediaId) => request(mediaUrl(mediaId), {method: 'DELETE'});

/** Noua ordine a tuturor fotografiilor atașate (și a celor în așteptare); prima publicată e imaginea de share. */
export const reorderPhotos = (subjectId, ids) => request(`${subjectUrl(subjectId)}/media/order`, {
    method: 'PUT',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ids}),
});

export const approveMedia = (mediaId) => request(`${mediaUrl(mediaId)}/approve`, {method: 'POST'});
export const fetchPendingMedia = () => request(`${API_ENDPOINT}/moderation/media`);

/** URL absolut pentru <img>: serverul dă căi relative (/api/media/{id}/thumb). */
export const absoluteMediaUrl = (path) => `${API_BASE}${path}`;

/**
 * <img> nu trimite tokenul JWT: media în așteptare sau ale unui subiect nepublicat se încarcă cu autorizare,
 * ca blob URL (de eliberat cu URL.revokeObjectURL).
 */
export async function fetchProtectedImage(path) {
    const res = await apiFetch(absoluteMediaUrl(path));
    if (!res.ok) throw new Error(FALLBACK[res.status] || `Eroare ${res.status}`);
    return URL.createObjectURL(await res.blob());
}
