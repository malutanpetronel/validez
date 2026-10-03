// Sesiunea in localStorage (merge identic in browser si in WebView-ul Cordova).
// Utilizatorul se citeste din payload-ul JWT: doar pentru afisare; autorizarea o decide serverul.

const TOKEN = 'validez.token';
const REFRESH = 'validez.refreshToken';
export const SESSION_EVENT = 'validez-session-change';

const read = (key) => {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
};

const notify = () => window.dispatchEvent(new Event(SESSION_EVENT));

/** base64url -> JSON, cu UTF-8 corect (diacriticele din displayName). */
export function decodeJwt(token) {
    try {
        const part = token.split('.')[1];
        const b64 = part.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(part.length / 4) * 4, '=');
        const binary = window.atob(b64);
        const utf8 = decodeURIComponent(Array.from(binary, (c) => `%${c.charCodeAt(0).toString(16).padStart(2, '0')}`).join(''));
        return JSON.parse(utf8);
    } catch {
        return null;
    }
}

export const getToken = () => read(TOKEN);
export const getRefreshToken = () => read(REFRESH);

export function setSession({token, refresh_token: refreshToken}) {
    window.localStorage.setItem(TOKEN, token);
    if (refreshToken) window.localStorage.setItem(REFRESH, refreshToken);
    notify();
}

export function clearSession() {
    window.localStorage.removeItem(TOKEN);
    window.localStorage.removeItem(REFRESH);
    notify();
}

/** Utilizatorul din token; ramane "autentificat" si cu tokenul de acces expirat, cat timp refresh-ul il poate reinnoi. */
export function currentUser() {
    const token = getToken();
    const p = token ? decodeJwt(token) : null;
    if (!p) return null;
    return {
        id: p.id ?? null,
        email: p.username,
        displayName: p.displayName || p.username,
        roles: Array.isArray(p.roles) ? p.roles : [],
    };
}

export const isAdmin = (user) => !!user?.roles?.includes('ROLE_ADMIN');
