import {useEffect, useState} from 'react';
import {Autocomplete, TextField} from '@mui/material';
import {fetchAdministrativeUnit, searchAdministrativeUnits} from '../api/geo';
import {shortPlace, unitLabel} from '../subjects/area';

export const AREA_ALL = {code: '', name: 'Toată țara', level: 'ALL'};
export const AREA_NATIONAL = {code: 'RO', name: 'Național', level: 'COUNTRY'};
const FIXED = [AREA_ALL, AREA_NATIONAL];

export const areaOptionLabel = (o) => (!o ? '' : o.level === 'COUNTY' ? `Județul ${shortPlace(o.name)}`
    : o.level === 'UAT' ? unitLabel(o) : o.name);

/**
 * Filtrul „Zonă” din pagina principală (decizie 20.11.2026, strict în jos): Toată țara / Național / un județ (cu
 * localitățile lui) / o localitate. value = codul ('' / 'RO' / cod SIRUTA); onChange(option) la alegerea utilizatorului.
 * Un cod venit din adresă își ia eticheta de la server (onResolve).
 */
export default function AreaFilter({value, onChange, onResolve, sx}) {
    const [selected, setSelected] = useState(() => FIXED.find((o) => o.code === (value || '')) ?? null);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const fixed = FIXED.find((o) => o.code === (value || ''));
        if (fixed) { setSelected(fixed); return undefined; }
        if (selected?.code === value) return undefined;
        let active = true;
        fetchAdministrativeUnit(value)
            .then((unit) => { if (!active) return; setSelected(unit ?? AREA_ALL); onResolve?.(unit ?? AREA_ALL); })
            .catch(() => {});
        return () => { active = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    useEffect(() => {
        const q = query.trim();
        if (q.length < 2) { setResults([]); return undefined; }
        let active = true;
        const timer = setTimeout(() => {
            setLoading(true);
            Promise.all([searchAdministrativeUnits(q, 'COUNTY'), searchAdministrativeUnits(q, 'UAT')])
                .then(([counties, uats]) => { if (active) setResults([...counties.slice(0, 5), ...uats]); })
                .catch(() => { if (active) setResults([]); })
                .finally(() => { if (active) setLoading(false); });
        }, 250);
        return () => { active = false; clearTimeout(timer); };
    }, [query]);

    return (
        <Autocomplete size="small" disableClearable sx={sx}
            options={[...FIXED, ...results]} value={selected ?? AREA_ALL} loading={loading}
            filterOptions={(x) => x}
            isOptionEqualToValue={(o, v) => o.code === v.code}
            getOptionLabel={areaOptionLabel}
            groupBy={(o) => (o.level === 'COUNTY' ? 'Județe (cu localitățile lor)' : o.level === 'UAT' ? 'Localități' : '')}
            onInputChange={(e, v, reason) => setQuery(reason === 'input' ? v : '')}
            onChange={(e, option) => { setSelected(option); onChange(option); }}
            noOptionsText="Nu am găsit" loadingText="Se caută…"
            renderInput={(params) => <TextField {...params} label="Zonă" placeholder="Caută județul sau localitatea"/>}/>
    );
}
