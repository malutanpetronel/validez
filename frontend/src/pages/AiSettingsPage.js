import {useEffect, useState} from 'react';
import {Navigate} from 'react-router-dom';
import {
    Alert, Button, Chip, FormControl, FormControlLabel, InputLabel, MenuItem, Paper, Select, Stack, Switch, TextField, Typography,
} from '@mui/material';
import {useAuth} from '../auth/AuthContext';
import usePageMeta from '../seo/usePageMeta';
import {fetchAiSettings, updateAiSettings} from '../api/ai';

const PROVIDERS = {anthropic: 'Anthropic (Claude)', openai: 'OpenAI (ChatGPT)'};
const usd = (v) => `${Number(v ?? 0).toFixed(Number(v) < 1 ? 4 : 2)} USD`;
const FIELDS = ['enabled', 'provider', 'prompt', 'language', 'dailyLimitPerAdmin', 'dailyRequestsCap', 'dailyUsdCap', 'monthlyUsdCap', 'modelPrices'];
const toForm = (s) => ({...Object.fromEntries(FIELDS.map((k) => [k, s[k]])),
    modelPrices: Object.fromEntries(Object.entries(s.modelPrices).map(([m, p]) => [m, {input: String(p.input), output: String(p.output)}]))});

/** ADR-0014 p.5: setările AI — doar admin; cheile API stau în env și apar doar ca „configurată / lipsă”. */
export default function AiSettingsPage() {
    usePageMeta({title: 'Setări AI', noindex: true});
    const {isAdmin} = useAuth();
    const [settings, setSettings] = useState(null);
    const [form, setForm] = useState(null);
    const [error, setError] = useState(null);
    const [errors, setErrors] = useState({});
    const [saved, setSaved] = useState(false);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!isAdmin) return;
        fetchAiSettings().then((s) => { setSettings(s); setForm(toForm(s)); }).catch((e) => setError(e.message));
    }, [isAdmin]);

    if (!isAdmin) return <Navigate to="/" replace/>;

    const set = (k) => (e) => { setSaved(false); setForm({...form, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value}); };
    const setPrice = (model, part) => (e) => { setSaved(false); setForm({...form, modelPrices: {...form.modelPrices, [model]: {...form.modelPrices[model], [part]: e.target.value.replace(',', '.')}}}); };

    const save = async () => {
        setBusy(true); setError(null); setErrors({}); setSaved(false);
        try {
            const s = await updateAiSettings({
                ...form,
                prompt: form.prompt.trim() === settings.defaultPrompt.trim() ? null : form.prompt,
                dailyLimitPerAdmin: Number(form.dailyLimitPerAdmin),
                dailyRequestsCap: Number(form.dailyRequestsCap),
                modelPrices: Object.fromEntries(Object.entries(form.modelPrices).map(([m, p]) => [m, {input: Number(p.input), output: Number(p.output)}])),
            });
            setSettings(s); setForm(toForm(s)); setSaved(true);
        } catch (e) {
            setError(e.message); setErrors(e.errors ?? {});
        } finally {
            setBusy(false);
        }
    };

    return <Paper sx={{p: {xs: 2, sm: 3}, maxWidth: 860, mx: 'auto', minWidth: 0}}><Stack spacing={2.5}>
        <Typography component="h1" variant="h4">Setări AI</Typography>
        <Typography color="text.secondary">Sugestii de subiect din fotografie, doar pentru administratori (ADR-0014). Cheile API se pun în env-ul serverului, nu aici.</Typography>
        {error && <Alert severity="error">{error}</Alert>}
        {saved && <Alert severity="success">Setările au fost salvate.</Alert>}
        {!form && !error && <Typography>Se încarcă…</Typography>}
        {form && <>
            <FormControlLabel control={<Switch checked={form.enabled} onChange={set('enabled')}/>}
                              label={form.enabled ? 'Sugestiile AI sunt pornite' : 'Sugestiile AI sunt oprite'}/>
            <Stack spacing={1}>
                <FormControl fullWidth>
                    <InputLabel id="ai-provider">Furnizor activ</InputLabel>
                    <Select labelId="ai-provider" label="Furnizor activ" value={form.provider} onChange={set('provider')}>
                        {Object.entries(PROVIDERS).map(([k, l]) => <MenuItem key={k} value={k}>{l}</MenuItem>)}
                    </Select>
                </FormControl>
                <Stack direction="row" spacing={1} useFlexGap sx={{flexWrap: 'wrap'}}>
                    {Object.entries(PROVIDERS).map(([k, l]) => <Chip key={k} size="small" variant="outlined"
                        color={settings.keys[k] ? 'success' : 'warning'}
                        label={`${l} · ${settings.models[k]} · cheie ${settings.keys[k] ? 'configurată ✓' : 'lipsă'}`}
                        sx={{maxWidth: '100%', '& .MuiChip-label': {overflow: 'hidden', textOverflow: 'ellipsis'}}}/>)}
                </Stack>
                <Typography variant="caption" color="text.secondary">Modelele sunt fixe deocamdată; se schimbă doar din cod.</Typography>
            </Stack>

            <Stack spacing={1}>
                <Typography variant="h6" component="h2">Promptul de recunoaștere</Typography>
                <Typography variant="body2" color="text.secondary">
                    Placeholdere: {'{limba}'}, {'{categorie}'} (categoria curentă), {'{categorii}'} (lista cu coduri c1…c80 din subarbore), {'{data}'} (data fotografiei).
                </Typography>
                <TextField multiline minRows={10} value={form.prompt} onChange={set('prompt')} inputProps={{maxLength: 8000}}
                           error={!!errors.prompt} helperText={errors.prompt || `${form.prompt.length} / 8000`} fullWidth/>
                <div><Button size="small" onClick={() => { setSaved(false); setForm({...form, prompt: settings.defaultPrompt}); }}
                             disabled={form.prompt === settings.defaultPrompt}>Resetează la promptul implicit</Button></div>
                <TextField label="Limba textului" value={form.language} onChange={set('language')} inputProps={{maxLength: 10}}
                           error={!!errors.language} helperText={errors.language} sx={{maxWidth: 240}}/>
            </Stack>

            <Stack spacing={1.5}>
                <Typography variant="h6" component="h2">Limite</Typography>
                <Stack direction={{xs: 'column', sm: 'row'}} spacing={2}>
                    <TextField label="Cereri pe zi per admin" type="number" value={form.dailyLimitPerAdmin} onChange={set('dailyLimitPerAdmin')}
                               error={!!errors.dailyLimitPerAdmin} helperText={errors.dailyLimitPerAdmin} fullWidth inputProps={{min: 0}}/>
                    <TextField label="Cereri pe zi (toată platforma)" type="number" value={form.dailyRequestsCap} onChange={set('dailyRequestsCap')}
                               error={!!errors.dailyRequestsCap} helperText={errors.dailyRequestsCap} fullWidth inputProps={{min: 0}}/>
                </Stack>
                <Stack direction={{xs: 'column', sm: 'row'}} spacing={2}>
                    <TextField label="Plafon pe zi (USD)" value={form.dailyUsdCap} onChange={set('dailyUsdCap')} inputProps={{inputMode: 'decimal'}}
                               error={!!errors.dailyUsdCap} helperText={errors.dailyUsdCap} fullWidth/>
                    <TextField label="Plafon pe lună (USD)" value={form.monthlyUsdCap} onChange={set('monthlyUsdCap')} inputProps={{inputMode: 'decimal'}}
                               error={!!errors.monthlyUsdCap} helperText={errors.monthlyUsdCap} fullWidth/>
                </Stack>
                <Typography variant="body2">
                    Azi: {settings.usage.todayRequests} cereri ({usd(settings.usage.todayUsd)}), dintre care ale tale {settings.usage.mineToday} · Luna aceasta: {usd(settings.usage.monthUsd)}
                </Typography>
                <Typography variant="caption" color="text.secondary">Setează și un budget limit în consola fiecărui furnizor, ca barieră în afara aplicației.</Typography>
            </Stack>

            <Stack spacing={1.5}>
                <Typography variant="h6" component="h2">Prețuri (USD / 1M tokeni)</Typography>
                {errors.modelPrices && <Alert severity="error">{errors.modelPrices}</Alert>}
                {Object.entries(form.modelPrices).map(([model, p]) => (
                    <Stack key={model} direction={{xs: 'column', sm: 'row'}} spacing={2} sx={{alignItems: {sm: 'center'}}}>
                        <Typography sx={{minWidth: {sm: 180}, overflowWrap: 'anywhere'}}>{model}</Typography>
                        <TextField label="Intrare" value={p.input} onChange={setPrice(model, 'input')} inputProps={{inputMode: 'decimal'}} fullWidth/>
                        <TextField label="Ieșire" value={p.output} onChange={setPrice(model, 'output')} inputProps={{inputMode: 'decimal'}} fullWidth/>
                    </Stack>
                ))}
            </Stack>

            <div><Button variant="contained" onClick={save} disabled={busy}>{busy ? 'Se salvează…' : 'Salvează'}</Button></div>
        </>}
    </Stack></Paper>;
}
