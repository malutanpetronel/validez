import {allowedTypes, canEditSubject, USER_TYPES} from './labels';

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
