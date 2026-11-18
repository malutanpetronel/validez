import {API_ENDPOINT} from '../config';
import {apiFetch} from './http';

// ADR-0014: setările AI și sugestia de subiect din fotografie (doar admini).
const FALLBACK = {
    401: 'Sesiunea a expirat. Intră din nou.',
    403: 'Doar administratorii pot folosi sugestiile AI.',
    413: 'Fotografia depășește 10 MB.',
    502: 'Sugestia AI nu a reușit. Încearcă din nou sau completează manual.',
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
        const err = new Error(body?.message || (res.status !== 413 && body?.detail) || FALLBACK[res.status] || `Eroare ${res.status}`);
        err.status = res.status;
        err.errors = body?.errors ?? null;
        throw err;
    }
    return body;
}

export const fetchAiSettings = () => request(`${API_ENDPOINT}/admin/ai-settings`);

export const updateAiSettings = (changes) => request(`${API_ENDPOINT}/admin/ai-settings`, {
    method: 'PATCH', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(changes),
});

/** {suggestion, provider, model, cached, costUsd}; takenAt = data din EXIF (ISO) sau null. */
export function suggestSubjectFromPhoto({file, nodeId, takenAt}) {
    const form = new FormData();
    form.append('file', file, file.name || 'fotografie.jpg');
    form.append('node', nodeId);
    if (takenAt) form.append('takenAt', takenAt);
    return request(`${API_ENDPOINT}/ai/subject-suggestion`, {method: 'POST', body: form});
}
