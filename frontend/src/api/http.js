import {API_BASE} from '../config';
import {clearSession, getRefreshToken, getToken, setSession} from '../auth/session';

const JSON_HEADERS = {'Content-Type': 'application/json', Accept: 'application/json'};

// Un singur refresh in zbor: refresh token-ul e single_use (rotatie), deci doua refresh-uri
// paralele cu acelasi token ar invalida sesiunea. Cererile concurente asteapta aceeasi promisiune.
let refreshing = null;

export function refreshSession() {
    if (!refreshing) {
        refreshing = (async () => {
            const refreshToken = getRefreshToken();
            if (!refreshToken) {
                clearSession();
                return false;
            }
            try {
                const res = await fetch(`${API_BASE}/api/token/refresh`, {
                    method: 'POST',
                    headers: JSON_HEADERS,
                    body: JSON.stringify({refresh_token: refreshToken}),
                });
                if (!res.ok) {
                    clearSession();
                    return false;
                }
                setSession(await res.json());
                return true;
            } catch {
                // Eroare de retea: pastram sesiunea, poate merge la urmatoarea cerere.
                return false;
            }
        })().finally(() => {
            refreshing = null;
        });
    }
    return refreshing;
}

/**
 * fetch cu Authorization. La 401 cu token: un refresh, apoi cererea se repeta o data.
 * Daca refresh-ul esueaza, sesiunea e stearsa si cererea se repeta fara token -
 * citirile publice functioneaza in continuare.
 */
export async function apiFetch(url, options = {}, retry = true) {
    const token = getToken();
    const headers = {...(options.headers || {})};
    if (token) headers.Authorization = `Bearer ${token}`;

    const res = await fetch(url, {...options, headers});
    if (res.status === 401 && token && retry) {
        await refreshSession();
        return apiFetch(url, options, false);
    }
    return res;
}

export async function login(email, password) {
    let res;
    try {
        res = await fetch(`${API_BASE}/api/auth`, {
            method: 'POST',
            headers: JSON_HEADERS,
            body: JSON.stringify({email, password}),
        });
    } catch {
        throw new Error('Serverul nu răspunde. Verifică conexiunea.');
    }
    const body = await res.json().catch(() => null);
    if (!res.ok) {
        if (/too many/i.test(body?.message || '')) {
            throw new Error('Prea multe încercări greșite. Încearcă din nou peste un minut.');
        }
        throw new Error(res.status === 401 ? 'Email sau parolă incorecte.' : `Eroare ${res.status}`);
    }
    setSession(body);
}

export const logout = () => clearSession();
