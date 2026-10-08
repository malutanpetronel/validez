import {API_ENDPOINT} from '../config';

async function post(path, data) {
    let response;
    try {
        response = await fetch(`${API_ENDPOINT}/password-reset/${path}`, {
            method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body: JSON.stringify(data),
        });
    } catch {
        throw new Error('Serverul nu răspunde. Verifică conexiunea.');
    }
    const body = await response.json().catch(() => null);
    if (!response.ok) {
        const error = new Error(body?.detail || body?.message || 'Cererea nu a putut fi finalizată.');
        error.retryAfter = Number(response.headers?.get('Retry-After')) || 0;
        throw error;
    }
    return body;
}

export const requestPasswordReset = (email, altcha) => post('request', {email, altcha});
export const confirmPasswordReset = (email, code, password) => post('confirm', {email, code, password});
