import {API_ENDPOINT} from '../config';
import {setSession} from '../auth/session';

async function post(path, data) {
    let response;
    try {
        response = await fetch(`${API_ENDPOINT}${path}`, {
            method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body: JSON.stringify(data),
        });
    } catch {
        throw new Error('Serverul nu răspunde. Verifică conexiunea.');
    }
    const body = await response.json().catch(() => null);
    if (!response.ok) {
        const error = new Error(body?.detail || body?.error || body?.message || 'Cererea nu a putut fi finalizată.');
        error.retryAfter = Number(response.headers?.get('Retry-After')) || 0;
        throw error;
    }
    return body;
}

export const register = (data) => post('/register', data);
export const resendRegistrationCode = (email, altcha) => post('/register/resend', {email, altcha});
export async function confirmRegistration(email, code) {
    const session = await post('/register/confirm', {email, code});
    setSession(session);
    return session;
}
