import {API_ENDPOINT} from '../config';
import {apiFetch} from './http';

async function request(path, method = 'GET', body) {
    const response = await apiFetch(`${API_ENDPOINT}${path}`, {
        method, headers: {Accept: 'application/json', 'Content-Type': 'application/json'},
        ...(body === undefined ? {} : {body: JSON.stringify(body)}),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.detail || data.message || `Eroare ${response.status}`);
    return data;
}
export const publishingStatus = (userId) => request(userId ? `/users/${encodeURIComponent(userId)}/publishing` : '/me/publishing');
export const setPublishingRevoked = (userId, revoked) => request(`/users/${encodeURIComponent(userId)}/publishing`, 'PUT', {revoked});
export const fetchCategorySuggestions = (page = 1) => request(`/category-suggestions?page=${page}`);
export const suggestCategory = (body) => request('/category-suggestions', 'POST', body);
export const reviewCategory = (id, status) => request(`/category-suggestions/${encodeURIComponent(id)}`, 'PATCH', {status});
