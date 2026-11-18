// G.3 (ADR-0012 p.4–5): aria declarată a subiectului — etichete și texte de afișare.

export const SCOPE_LABELS = {LOCAL: 'Local', COUNTY: 'Județean', NATIONAL: 'Național'};
export const SCOPE_HELP = {
    LOCAL: 'Privește una sau mai multe localități. Localitatea pinului se adaugă automat.',
    COUNTY: 'Privește județul ca întreg (de exemplu un drum județean sau o decizie a consiliului județean).',
    NATIONAL: 'Privește toată țara (o lege, o regulă națională). Pinul, dacă există, arată doar unde s-a observat.',
};

/** Denumirile SIRUTA vin cu majuscule: „MUNICIPIUL CLUJ-NAPOCA” → „Municipiul Cluj-Napoca”. */
export const placeName = (name) => (name ?? '').toLocaleLowerCase('ro-RO')
    .replace(/(^|[\s\-(])(\p{L})/gu, (m, sep, letter) => sep + letter.toLocaleUpperCase('ro-RO'));

/** „Municipiul Dej, Județul Cluj” pentru opțiuni și chip-uri. */
export const unitLabel = (unit) => [placeName(unit.name), unit.county ? placeName(unit.county) : null].filter(Boolean).join(', ');

/** Linia de pe pagina subiectului: „Local — Municipiul Dej, Municipiul Gherla”; null = arie nespecificată. */
export const areaText = (area) => {
    if (!area) return null;
    if (area.scope === 'NATIONAL') return 'Național';
    const names = (area.effective ?? []).map((u) => placeName(u.name));
    return `${SCOPE_LABELS[area.scope] ?? area.scope}${names.length ? ` — ${names.join(', ')}` : ''}`;
};

/** Pentru compararea reviziilor: aceeași arie = același scope și aceleași coduri declarate. */
export const areaKey = (area) => (area ? `${area.scope}:${(area.units ?? []).map((u) => u.code).sort().join(',')}` : '');
