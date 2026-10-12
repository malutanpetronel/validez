import {API_ENDPOINT} from '../config';
import {apiFetch} from './http';

/** Eroare de vot cu motivul serverului (`reason`), ca interfața să reacționeze diferit (consimțământ, limită etc.). */
export class VoteApiError extends Error {
    constructor(message, status, reason = null, retryAfter = null) {
        super(message);
        this.status = status;
        this.reason = reason;
        this.retryAfter = retryAfter;
    }
}

async function request(path, method = 'GET', body) {
    const res = await apiFetch(`${API_ENDPOINT}${path}`, {
        method,
        headers: {Accept: 'application/json', 'Content-Type': 'application/json'},
        ...(body === undefined ? {} : {body: JSON.stringify(body)}),
    });
    const data = await res.json().catch(() => null);
    if (!res.ok) {
        const message = data?.message
            || (res.status === 404 ? 'Subiectul nu există sau nu e vizibil.' : null)
            || (res.status === 401 ? 'Sesiunea a expirat. Intră din nou.' : null)
            || `Eroare ${res.status}`;
        throw new VoteApiError(message, res.status, data?.reason ?? null, data?.retryAfter ?? null);
    }
    return data;
}

const subject = (id) => `/civic_subjects/${encodeURIComponent(id)}`;

export const fetchVotes = (id) => request(`${subject(id)}/votes`);
export const castVote = (id, type, value) => request(`${subject(id)}/votes/${encodeURIComponent(type)}`, 'PUT', {value});
/** Răspunsul conține doar {withdrawn}; starea se reîncarcă separat (subiectul poate fi între timp invizibil). */
export const withdrawVote = (id, type) => request(`${subject(id)}/votes/${encodeURIComponent(type)}`, 'DELETE');
export const fetchRevisions = (id) => request(`${subject(id)}/revisions`);
export const fetchVoteConsent = () => request('/me/vote-consent');
export const grantVoteConsent = (noticeVersion) => request('/me/vote-consent', 'PUT', {noticeVersion});
export const withdrawVoteConsent = () => request('/me/vote-consent', 'DELETE');
export const exportMyVotes = () => request('/me/votes');
