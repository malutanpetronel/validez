import {movePhoto} from './photoOrder';

test('mută o fotografie și păstrează ordinea celorlalte', () => {
    expect(movePhoto(['a', 'b', 'c', 'd'], 2, 0)).toEqual(['c', 'a', 'b', 'd']);
    expect(movePhoto(['a', 'b', 'c'], 0, 1)).toEqual(['b', 'a', 'c']);
    expect(movePhoto(['a', 'b', 'c'], 2, 1)).toEqual(['a', 'c', 'b']);
});

test('fără mutare nu întoarce o listă de trimis', () => {
    expect(movePhoto(['a', 'b'], 1, 1)).toBeNull();
    expect(movePhoto(['a', 'b'], 0, -1)).toBeNull();
    expect(movePhoto(['a', 'b'], 1, 2)).toBeNull();
});
