// Etichete și reguli de afișare pentru CivicSubject. Regulile reale sunt pe server; aici doar ce arătăm.

export const TYPE_LABELS = {
    ISSUE: 'Problemă',
    PROPOSAL: 'Propunere',
    PETITION: 'Petiție',
    PROJECT: 'Proiect',
    PROMISE: 'Promisiune',
    ELECTORAL_EVALUATION: 'Evaluare aleși',
    TOPIC_EVALUATION: 'Evaluare temă',
};

// Decizie Step 2: utilizatorii creează doar aceste tipuri; restul doar adminii.
export const USER_TYPES = ['ISSUE', 'PROPOSAL', 'PETITION'];

export const STAGE_LABELS = {OPEN: 'Deschis', IN_PROGRESS: 'În lucru', RESOLVED: 'Rezolvat', CLOSED: 'Închis'};
export const STAGE_COLORS = {OPEN: 'info', IN_PROGRESS: 'warning', RESOLVED: 'success', CLOSED: 'default'};
export const VISIBILITY_LABELS = {PUBLISHED: 'Publicat', HIDDEN: 'Ascuns', PENDING: 'În așteptarea aprobării'};

export const allowedTypes = (isAdmin) => (isAdmin ? Object.keys(TYPE_LABELS) : USER_TYPES);

// Voturi (ADR-0007). Valorile sunt cele de pe server (VALIDITY/YES_NO 0–1, IMPORTANCE 1–5).
export const VOTE_TYPE_LABELS = {VALIDITY: 'Validare', YES_NO: 'Susținere', IMPORTANCE: 'Importanță'};
export const VOTE_TYPE_HINTS = {
    VALIDITY: 'Ce se descrie aici este real și corect?',
    YES_NO: 'Susții această inițiativă?',
    IMPORTANCE: '1 = puțin important, 5 = foarte important',
};
const BINARY_OPTIONS = {VALIDITY: [[1, 'Validez'], [0, 'Invalidez']], YES_NO: [[1, 'Da'], [0, 'Nu']]};

/** Opțiunile unui tip de vot, ca [valoare, etichetă]; pentru tipuri noi, intervalul primit de la server. */
export const voteOptions = (type) => BINARY_OPTIONS[type.code]
    ?? Array.from({length: type.maxValue - type.minValue + 1}, (_, i) => [type.minValue + i, String(type.minValue + i)]);

export const voteValueLabel = (code, value) => (BINARY_OPTIONS[code]?.find(([v]) => v === value)?.[1]) ?? String(value);

/** Butonul de editare: adminul sau autorul (serverul verifică oricum). */
export const canEditSubject = (user, subject) =>
    !!user && !!subject && (user.roles?.includes('ROLE_ADMIN') || user.id === subject.authorId);

export const formatDate = (iso) => {
    try {
        return new Date(iso).toLocaleString('ro-RO', {dateStyle: 'medium', timeStyle: 'short'});
    } catch {
        return iso;
    }
};
