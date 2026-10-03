// JWT nesemnat pentru teste: frontend-ul doar citeste payload-ul (afisare), nu verifica semnatura.
export function fakeJwt(payload) {
    const b64url = (obj) => window.btoa(unescape(encodeURIComponent(JSON.stringify(obj))))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    return `${b64url({alg: 'none', typ: 'JWT'})}.${b64url(payload)}.semnatura`;
}

export const adminJwt = () => fakeJwt({username: 'admin@validez.test', displayName: 'Admină', roles: ['ROLE_ADMIN', 'ROLE_USER'], id: '01ADMIN'});
export const userJwt = () => fakeJwt({username: 'ion@validez.test', displayName: 'Ion', roles: ['ROLE_USER'], id: '01ION'});
