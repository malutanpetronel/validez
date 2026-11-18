import {API_ENDPOINT} from '../config';
import {apiFetch} from './http';

// L.5b/L.5c: „Șterge-mi contul” — cererea linkului (cont logat) și confirmarea din pagina deschisă de link (fără cont).
async function parse(response) {
    const body = await response.json().catch(() => null);
    if (!response.ok) {
        const error = new Error(body?.detail || body?.message || (response.status === 401 ? 'Sesiunea a expirat. Intră din nou.' : 'Cererea nu a putut fi finalizată.'));
        error.status = response.status;
        throw error;
    }
    return body;
}

export async function requestAccountDeletion() {
    let response;
    try {
        response = await apiFetch(`${API_ENDPOINT}/account/deletion-request`, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body: '{}'});
    } catch {
        throw new Error('Serverul nu răspunde. Verifică conexiunea.');
    }
    return parse(response);
}

export async function confirmAccountDeletion(token) {
    let response;
    try {
        response = await fetch(`${API_ENDPOINT}/account/deletion-confirm`, {
            method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body: JSON.stringify({token}),
        });
    } catch {
        throw new Error('Serverul nu răspunde. Verifică conexiunea.');
    }
    return parse(response);
}
