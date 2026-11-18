import {areaKey, areaText, placeName, unitLabel} from './area';

test('denumirile SIRUTA devin lizibile', () => {
    expect(placeName('MUNICIPIUL CLUJ-NAPOCA')).toBe('Municipiul Cluj-Napoca');
    expect(placeName('COMUNA ȘIEU-ODORHEI')).toBe('Comuna Șieu-Odorhei');
    expect(unitLabel({name: 'MUNICIPIUL DEJ', county: 'JUDEȚUL CLUJ'})).toBe('Municipiul Dej, Județul Cluj');
});

test('textul ariei și cheia de comparare', () => {
    expect(areaText(null)).toBeNull();
    expect(areaText({scope: 'NATIONAL', units: [], effective: [{code: 'RO', name: 'România'}]})).toBe('Național');
    expect(areaText({scope: 'LOCAL', units: [], effective: [{code: '55008', name: 'MUNICIPIUL DEJ', fromPin: true}]})).toBe('Local — Municipiul Dej');
    expect(areaKey({scope: 'LOCAL', units: [{code: '2'}, {code: '1'}]})).toBe(areaKey({scope: 'LOCAL', units: [{code: '1'}, {code: '2'}]}));
    expect(areaKey(null)).not.toBe(areaKey({scope: 'NATIONAL', units: []}));
});
