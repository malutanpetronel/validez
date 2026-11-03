import {
    administrativeText, allowedTypes, canEditSubject, fromLocalInput, pollOptionsError, toLocalInput, USER_TYPES, voteOptions, voteValueLabel,
} from './labels';

test('tipuri: utilizatorul doar ISSUE/PROPOSAL/PETITION, adminul toate 8 (inclusiv Sondaj)', () => {
    expect(allowedTypes(false)).toEqual(USER_TYPES);
    expect(allowedTypes(true)).toHaveLength(8);
    expect(allowedTypes(true)).toContain('POLL');
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

test('sondaj: variantele vin de la server, eticheta votului propriu', () => {
    const type = {code: 'CHOICE', minValue: 0, maxValue: 2, labels: ['A', 'B', 'C']};
    expect(voteOptions(type)).toEqual([[0, 'A'], [1, 'B'], [2, 'C']]);
    expect(voteValueLabel('CHOICE', 1, type.labels)).toBe('B');
    expect(voteValueLabel('CHOICE', 1)).toBe('Varianta 2');
    expect(voteValueLabel('VALIDITY', 1)).toBe('Validez');
});

test('sondaj: validarea variantelor', () => {
    expect(pollOptionsError(['Da', 'Nu'])).toBe('');
    expect(pollOptionsError(['Da'])).toMatch(/cel puțin 2/);
    expect(pollOptionsError(['Da', '  '])).toMatch(/goale/);
    expect(pollOptionsError(['Da', ' da '])).toMatch(/de două ori/);
    expect(pollOptionsError(['Da', 'x'.repeat(121)])).toMatch(/120/);
    expect(pollOptionsError(Array.from({length: 21}, (_, i) => `V${i}`))).toMatch(/cel mult 20/);
});

test('sondaj: data de închidere trece din ora locală în ISO și înapoi', () => {
    const iso = fromLocalInput('2030-05-01T18:30');
    expect(iso).toMatch(/Z$/);
    expect(toLocalInput(iso)).toBe('2030-05-01T18:30');
    expect(fromLocalInput('')).toBeNull();
    expect(toLocalInput(null)).toBe('');
});
