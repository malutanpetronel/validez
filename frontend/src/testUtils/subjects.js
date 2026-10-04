// Fixturi și un fetch simulat pe rute pentru testele de subiecte.
export const subject = (over = {}) => ({
    id: '01SUBJ', nodeId: '01NODE', nodeName: 'Calitate', type: 'ISSUE', title: 'Gropi pe DN1',
    description: 'Multe gropi.', visibility: 'PUBLISHED', stage: 'OPEN', authorId: '01ION', authorName: 'Ion',
    createdAt: '2026-10-04T10:00:00+00:00', updatedAt: '2026-10-04T10:00:00+00:00', ...over,
});

export const res = (body, status = 200) => Promise.resolve({ok: status < 300, status, json: () => Promise.resolve(body)});

/** routes: [[predicat(url, opts), raspuns(url, opts)], ...]; implicit: lista goala. */
export const routedFetch = (routes) => jest.fn((url, opts = {}) => {
    for (const [match, reply] of routes) if (match(url, opts)) return reply(url, opts);
    return res({member: [], totalItems: 0});
});
