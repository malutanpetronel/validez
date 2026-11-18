import {useEffect, useState} from 'react';
import {Alert, Autocomplete, Chip, FormControl, FormControlLabel, FormHelperText, FormLabel, Radio, RadioGroup, Stack, TextField, Typography} from '@mui/material';
import {searchAdministrativeUnits} from '../api/geo';
import {placeName, SCOPE_HELP, SCOPE_LABELS, unitLabel} from '../subjects/area';

/**
 * G.3 (ADR-0012 p.5): „Aria subiectului” — Local (localități) / Județean (județe) / Național (fără unități).
 * value = {scope: 'LOCAL'|'COUNTY'|'NATIONAL'|null, units: [{code, name, level, county}]}.
 * pinUnit = UAT-ul în care e încadrat pinul salvat (doar informativ: intră automat în aria locală).
 */
export default function AreaPicker({value, onChange, pinUnit, disabled, legacy}) {
    const level = value.scope === 'COUNTY' ? 'COUNTY' : 'UAT';
    const [input, setInput] = useState('');
    const [options, setOptions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const needsUnits = value.scope === 'LOCAL' || value.scope === 'COUNTY';

    useEffect(() => {
        if (!needsUnits) return undefined;
        let active = true;
        const q = input.trim();
        if (q.length < 2 && level === 'UAT') { setOptions([]); return undefined; }
        const timer = setTimeout(() => {
            setLoading(true);
            searchAdministrativeUnits(q, level)
                .then((items) => { if (active) { setOptions(items); setError(null); } })
                .catch((e) => { if (active) setError(e.message); })
                .finally(() => { if (active) setLoading(false); });
        }, 250);
        return () => { active = false; clearTimeout(timer); };
    }, [input, level, needsUnits]);

    const setScope = (scope) => {
        // Unitățile de alt nivel nu au sens în noua arie (serverul le-ar refuza).
        const keep = scope === value.scope ? value.units : [];
        setInput('');
        onChange({scope, units: keep});
    };

    return (
        <FormControl component="fieldset" disabled={disabled} sx={{minWidth: 0}}>
            <FormLabel component="legend" sx={{fontWeight: 600, color: 'text.primary'}}>Aria subiectului</FormLabel>
            {legacy && !value.scope && (
                <Alert severity="info" sx={{mt: 1}}>Arie nespecificată. Completeaz-o ca subiectul să apară la filtrarea pe localități și județe.</Alert>
            )}
            <RadioGroup row value={value.scope ?? ''} onChange={(e) => setScope(e.target.value)} sx={{columnGap: 1}}>
                {Object.entries(SCOPE_LABELS).map(([k, l]) => <FormControlLabel key={k} value={k} control={<Radio/>} label={l}/>)}
            </RadioGroup>
            {value.scope && <FormHelperText sx={{mx: 0}}>{SCOPE_HELP[value.scope]}</FormHelperText>}
            {needsUnits && (
                <Stack spacing={1} sx={{mt: 1.5}}>
                    <Autocomplete multiple fullWidth disabled={disabled} options={options} loading={loading}
                        value={value.units} filterOptions={(x) => x}
                        isOptionEqualToValue={(o, v) => o.code === v.code}
                        getOptionLabel={unitLabel}
                        onChange={(e, units) => onChange({scope: value.scope, units: units.slice(0, 20)})}
                        inputValue={input} onInputChange={(e, v, reason) => { if (reason !== 'reset') setInput(v); }}
                        noOptionsText={level === 'UAT' && input.trim().length < 2 ? 'Scrie cel puțin 2 litere' : 'Nu am găsit'}
                        loadingText="Se caută…"
                        renderTags={(units, getTagProps) => units.map((u, i) => {
                            const {key, ...props} = getTagProps({index: i});
                            return <Chip key={key} {...props} size="small" label={placeName(u.name)} sx={{maxWidth: '100%'}}/>;
                        })}
                        renderInput={(params) => <TextField {...params}
                            label={value.scope === 'COUNTY' ? 'Județe' : 'Localități (municipii, orașe, comune, sectoare)'}
                            placeholder={value.units.length ? '' : (value.scope === 'COUNTY' ? 'Caută județul' : 'Caută localitatea, de exemplu Dej')}/>}/>
                    {error && <Alert severity="warning">{error}</Alert>}
                    {value.scope === 'LOCAL' && pinUnit && (
                        <Typography variant="body2" color="text.secondary" sx={{overflowWrap: 'anywhere'}}>
                            Din pin: <strong>{placeName(pinUnit.name)}</strong> (inclusă automat, nu trebuie adăugată)
                        </Typography>
                    )}
                </Stack>
            )}
        </FormControl>
    );
}
