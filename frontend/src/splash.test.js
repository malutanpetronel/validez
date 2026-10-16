import {hideSplash, isSubjectPath, waitsForData} from './splash';

test('paginile care își așteaptă datele', () => {
    expect(waitsForData('/')).toBe(true);
    expect(waitsForData('/categorie/drumuri/cluj')).toBe(true);
    expect(waitsForData('/categorie/drumuri/subiect/gropi')).toBe(true);
    expect(waitsForData('/subiecte/01ARZ3NDEKTSV4RRFFQ69G5FAV')).toBe(true);
    expect(waitsForData('/subiecte')).toBe(false);
    expect(waitsForData('/login')).toBe(false);
    expect(waitsForData('/categorii-propuse')).toBe(false);
});

test('adresele de subiect le ascunde SubjectPage', () => {
    expect(isSubjectPath('/categorie/drumuri/subiect/gropi')).toBe(true);
    expect(isSubjectPath('/subiecte/01ARZ3NDEKTSV4RRFFQ69G5FAV')).toBe(true);
    expect(isSubjectPath('/categorie/drumuri')).toBe(false);
    expect(isSubjectPath('/')).toBe(false);
});

test('ascunde și scoate ecranul o singură dată', () => {
    jest.useFakeTimers();
    document.body.innerHTML = '<div id="splash"></div>';
    hideSplash();
    hideSplash();
    expect(document.getElementById('splash').classList.contains('splash-hide')).toBe(true);
    jest.advanceTimersByTime(300);
    expect(document.getElementById('splash')).toBeNull();
    hideSplash();
    jest.useRealTimers();
});
