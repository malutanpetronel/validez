import {clearSession, currentUser, decodeJwt, getRefreshToken, isAdmin, SESSION_EVENT, setSession} from './session';
import {adminJwt, fakeJwt, userJwt} from '../testUtils/fakeJwt';

beforeEach(() => window.localStorage.clear());

test('decodeJwt citeste base64url cu diacritice (UTF-8)', () => {
    expect(decodeJwt(fakeJwt({displayName: 'Ștefan Țăran', x: '??>>'})).displayName).toBe('Ștefan Țăran');
    expect(decodeJwt('nu.e.jwt')).toBeNull();
});

test('currentUser si isAdmin din token; setSession/clearSession anunta schimbarea', () => {
    const events = [];
    const on = () => events.push(1);
    window.addEventListener(SESSION_EVENT, on);

    expect(currentUser()).toBeNull();
    setSession({token: adminJwt(), refresh_token: 'r1'});
    expect(currentUser()).toMatchObject({email: 'admin@validez.test', displayName: 'Admină'});
    expect(isAdmin(currentUser())).toBe(true);
    expect(getRefreshToken()).toBe('r1');

    setSession({token: userJwt()});
    expect(isAdmin(currentUser())).toBe(false);
    expect(getRefreshToken()).toBe('r1');

    clearSession();
    expect(currentUser()).toBeNull();
    expect(events).toHaveLength(3);
    window.removeEventListener(SESSION_EVENT, on);
});
