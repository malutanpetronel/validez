import {apiFetch, login} from './http';
import {getRefreshToken, getToken, setSession} from '../auth/session';

const res = (status, body = {}) => ({ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body)});
const authOf = (call) => call[1]?.headers?.Authorization;

beforeEach(() => window.localStorage.clear());

test('fara sesiune: cerere fara Authorization, fara refresh', async () => {
    global.fetch = jest.fn(() => Promise.resolve(res(200)));
    await apiFetch('/api/tree_nodes');
    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(authOf(global.fetch.mock.calls[0])).toBeUndefined();
});

test('401 -> un singur refresh partajat de cererile concurente -> cererile se repeta cu tokenul nou', async () => {
    setSession({token: 'vechi', refresh_token: 'r1'});
    global.fetch = jest.fn((url, opts) => {
        if (url.endsWith('/api/token/refresh')) return Promise.resolve(res(200, {token: 'nou', refresh_token: 'r2'}));
        return Promise.resolve(res(opts.headers.Authorization === 'Bearer nou' ? 200 : 401));
    });

    const [a, b] = await Promise.all([apiFetch('/api/a'), apiFetch('/api/b')]);

    expect([a.status, b.status]).toEqual([200, 200]);
    expect(global.fetch.mock.calls.filter((c) => c[0].endsWith('/api/token/refresh'))).toHaveLength(1);
    expect(getToken()).toBe('nou');
    expect(getRefreshToken()).toBe('r2');
});

test('refresh esuat -> sesiunea se sterge si cererea se repeta fara token (citire publica)', async () => {
    setSession({token: 'expirat', refresh_token: 'r-invalid'});
    global.fetch = jest.fn((url, opts) => {
        if (url.endsWith('/api/token/refresh')) return Promise.resolve(res(401));
        return Promise.resolve(res(opts.headers.Authorization ? 401 : 200));
    });

    const r = await apiFetch('/api/tree_nodes');

    expect(r.status).toBe(200);
    expect(getToken()).toBeNull();
    expect(authOf(global.fetch.mock.calls.at(-1))).toBeUndefined();
});

test('login: mesaje in romana pentru credentiale gresite si blocare temporara', async () => {
    global.fetch = jest.fn(() => Promise.resolve(res(401, {code: 401, message: 'Invalid credentials.'})));
    await expect(login('a@b.ro', 'x')).rejects.toThrow('Email sau parolă incorecte.');

    global.fetch = jest.fn(() => Promise.resolve(res(401, {message: 'Too many failed login attempts, please try again in 1 minute.'})));
    await expect(login('a@b.ro', 'x')).rejects.toThrow(/Prea multe încercări/);

    global.fetch = jest.fn(() => Promise.resolve(res(200, {token: 't', refresh_token: 'r'})));
    await login('a@b.ro', 'parola');
    expect(getToken()).toBe('t');
});
