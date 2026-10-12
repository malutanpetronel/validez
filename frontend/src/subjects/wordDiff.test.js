import {diffWords, MAX_TOKENS} from './wordDiff';

const join = (segments, keep) => segments.filter((s) => keep.includes(s.type)).map((s) => s.text).join('');

test('diferența pe cuvinte reface exact ambele texte', () => {
    const before = 'Groapă mare pe DN1, la km 12.';
    const after = 'Groapă foarte mare pe DN1, la km 14.';
    const d = diffWords(before, after);
    expect(join(d, ['same', 'removed'])).toBe(before);
    expect(join(d, ['same', 'added'])).toBe(after);
    expect(d.filter((s) => s.type === 'added').map((s) => s.text.trim())).toEqual(['foarte', '14.']);
    expect(d.filter((s) => s.type === 'removed').map((s) => s.text.trim())).toEqual(['12.']);
});

test('texte identice sau goale', () => {
    expect(diffWords('a b', 'a b')).toEqual([{type: 'same', text: 'a b'}]);
    expect(diffWords('', 'nou')).toEqual([{type: 'added', text: 'nou'}]);
    expect(diffWords(null, '')).toEqual([]);
});

test('peste prag nu calculează (nu blochează browserul)', () => {
    const long = Array.from({length: MAX_TOKENS}, (_, i) => `w${i}`).join(' ');
    const started = Date.now();
    expect(diffWords(long, `${long} final`)).toBeNull();
    expect(Date.now() - started).toBeLessThan(500);
});
