import {useState} from 'react';
import {Alert, Button, Stack, Typography} from '@mui/material';
import MyLocationIcon from '@mui/icons-material/MyLocation';
import WrongLocationIcon from '@mui/icons-material/WrongLocation';
import {PickerMap} from './SubjectMap';

const GEO_ERRORS = {
    1: 'Permisiunea pentru locație a fost refuzată. Poți pune pinul direct pe hartă sau poți permite locația din setările browserului/telefonului.',
    2: 'Locația dispozitivului nu este disponibilă acum. Pune pinul direct pe hartă.',
    3: 'Locația nu a venit la timp. Încearcă din nou sau pune pinul pe hartă.',
};

/**
 * Pinul subiectului (ADR-0011 p.9): opțional, pus pe hartă sau din locația dispozitivului (doar la cerere).
 * value: {lat, lng, source} sau null. Orice mutare manuală face sursa MANUAL.
 */
export default function LocationPicker({value, onChange, disabled}) {
    const [locating, setLocating] = useState(false);
    const [error, setError] = useState(null);

    const useDevice = () => {
        if (!navigator.geolocation) { setError('Dispozitivul nu oferă locația. Pune pinul direct pe hartă.'); return; }
        setLocating(true);
        setError(null);
        navigator.geolocation.getCurrentPosition(
            (pos) => { setLocating(false); onChange({lat: pos.coords.latitude, lng: pos.coords.longitude, source: 'DEVICE'}); },
            (err) => { setLocating(false); setError(GEO_ERRORS[err.code] ?? 'Locația nu a putut fi obținută.'); },
            {enableHighAccuracy: true, timeout: 20000, maximumAge: 60000},
        );
    };

    return (
        <Stack spacing={1}>
            <Typography variant="subtitle1" sx={{fontWeight: 600}}>Locul pe hartă (opțional)</Typography>
            <Typography variant="body2" color="text.secondary">
                Atinge harta pentru a pune pinul, apoi trage-l dacă trebuie mutat. Locul face parte din subiect: mutarea lui
                după primele voturi cere reconfirmarea lor. Locația dispozitivului se citește doar când apeși butonul.
            </Typography>
            {error && <Alert severity="warning" onClose={() => setError(null)}>{error}</Alert>}
            {!disabled && <PickerMap point={value} onPlace={(p) => onChange({...p, source: 'MANUAL'})}/>}
            <Stack direction="row" spacing={1} useFlexGap sx={{flexWrap: 'wrap'}}>
                <Button size="small" variant="outlined" startIcon={<MyLocationIcon/>} onClick={useDevice} disabled={disabled || locating}>
                    {locating ? 'Se caută locația…' : 'Folosește locația mea'}
                </Button>
                {value && <Button size="small" color="error" startIcon={<WrongLocationIcon/>} onClick={() => onChange(null)} disabled={disabled}>Scoate pinul</Button>}
            </Stack>
            {value && <Typography variant="caption" color="text.secondary">
                {value.lat.toFixed(6)}, {value.lng.toFixed(6)} · {value.source === 'DEVICE' ? 'din locația dispozitivului' : 'pus pe hartă'}
            </Typography>}
        </Stack>
    );
}
