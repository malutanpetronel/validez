import {administrativeText, allowedTypes, canEditSubject, USER_TYPES} from './labels';

test('tipuri: utilizatorul doar ISSUE/PROPOSAL/PETITION, adminul toate 7', () => {
    expect(allowedTypes(false)).toEqual(USER_TYPES);
    expect(allowedTypes(true)).toHaveLength(7);
    expect(allowedTypes(true)).toEqual(expect.arrayContaining(['PROMISE', 'ELECTORAL_EVALUATION', 'PROJECT', 'TOPIC_EVALUATION']));
});

test('editare: autorul si adminul; altcineva sau vizitatorul nu', () => {
    const s = {authorId: '01ION'};
    expect(canEditSubject({id: '01ION', roles: ['ROLE_USER']}, s)).toBe(true);
    expect(canEditSubject({id: '01ADMIN', roles: ['ROLE_ADMIN']}, s)).toBe(true);
    expect(canEditSubject({id: '01MARIA', roles: ['ROLE_USER']}, s)).toBe(false);
    expect(canEditSubject(null, s)).toBe(false);
});

test('încadrarea administrativă a pinului (ADR-0012 p.6)', () => {
    const loc = (administrative) => ({lat: 47.1, lng: 23.9, administrative});
    expect(administrativeText(loc(null))).toBeNull();
    expect(administrativeText(null)).toBeNull();
    expect(administrativeText(loc({state: 'RESOLVED', unit: {name: 'MUNICIPIUL DEJ'}, county: {name: 'JUDEȚUL CLUJ'}, candidates: []})))
        .toEqual({determined: true, text: 'MUNICIPIUL DEJ, JUDEȚUL CLUJ'});
    const candidates = [{name: 'COMUNA A'}, {name: 'COMUNA B'}];
    expect(administrativeText(loc({state: 'AMBIGUOUS', unit: null, county: null, candidates})))
        .toEqual({determined: false, text: 'Încadrare neclară, aproape de o limită administrativă: COMUNA A sau COMUNA B'});
    expect(administrativeText(loc({state: 'UNMATCHED', candidates})).text).toBe('Încadrare neclară (între limite): COMUNA A sau COMUNA B');
    expect(administrativeText(loc({state: 'OUTSIDE', candidates: []})).determined).toBe(false);
});
