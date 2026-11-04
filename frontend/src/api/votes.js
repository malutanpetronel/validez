import {API_ENDPOINT} from '../config';
import {apiFetch} from './http';
import {SESSION_EVENT} from '../auth/session';

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

/*
 * Sumarul voturilor e cerut de mai multe componente pentru același subiect (rândul din arbore, rândul de sub
 * titlu, secțiunea Voturi): o singură cerere, partajată cât e în curs și refolosită scurt timp după.
 * Actualizat din evenimentul `subject-votes-updated` (după vot/retragere), golit la login/logout (conține `mine`).
 * `fresh: true` ocolește cache-ul (reîncărcări după erori sau revizie schimbată).
 */
const VOTES_TTL_MS = 15000;
const votesCache = new Map();

export function fetchVotes(id, {fresh = false} = {}) {
    const hit = votesCache.get(id);
    if (!fresh && hit && Date.now() - hit.at < VOTES_TTL_MS) return hit.promise;
    const entry = {at: Date.now(), promise: request(`${subject(id)}/votes`)};
    votesCache.set(id, entry);
    entry.promise.catch(() => { if (votesCache.get(id) === entry) votesCache.delete(id); });
    return entry.promise;
}

/** Fără argument golește tot; altfel doar subiectul dat (ex. după editarea subiectului: revizie nouă). */
export const invalidateVotes = (id) => (id === undefined ? votesCache.clear() : votesCache.delete(id));

if (typeof window !== 'undefined') {
    window.addEventListener(SESSION_EVENT, () => votesCache.clear());
    window.addEventListener('subject-votes-updated', ({detail}) => {
        if (detail?.subjectId && detail.summary) votesCache.set(detail.subjectId, {at: Date.now(), promise: Promise.resolve(detail.summary)});
    });
}

export const castVote = (id, type, value) => request(`${subject(id)}/votes/${encodeURIComponent(type)}`, 'PUT', {value});
/** Răspunsul conține doar {withdrawn}; starea se reîncarcă separat (subiectul poate fi între timp invizibil). */
export const withdrawVote = (id, type) => request(`${subject(id)}/votes/${encodeURIComponent(type)}`, 'DELETE');
export const fetchRevisions = (id) => request(`${subject(id)}/revisions`);
export const fetchVoteConsent = () => request('/me/vote-consent');
export const grantVoteConsent = (noticeVersion) => request('/me/vote-consent', 'PUT', {noticeVersion});
export const withdrawVoteConsent = () => request('/me/vote-consent', 'DELETE');
export const exportMyVotes = () => request('/me/votes');
