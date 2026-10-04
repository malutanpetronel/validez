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
export const VISIBILITY_LABELS = {PUBLISHED: 'Publicat', HIDDEN: 'Ascuns'};

export const allowedTypes = (isAdmin) => (isAdmin ? Object.keys(TYPE_LABELS) : USER_TYPES);

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
