import {useEffect, useRef, useState} from 'react';
import {
    Alert, AppBar, Button, Dialog, DialogActions, DialogContent, DialogTitle, FormControl, IconButton,
    InputLabel, MenuItem, Select, Stack, TextField, Toolbar, Typography, useMediaQuery,
} from '@mui/material';
import {useTheme} from '@mui/material/styles';
import CloseIcon from '@mui/icons-material/Close';
import {useAuth} from '../auth/AuthContext';
import {createSubject, fetchSubjectNote, saveSubjectNote, updateSubject} from '../api/subjects';
import {allowedTypes, STAGE_LABELS, TYPE_LABELS, VISIBILITY_LABELS} from '../subjects/labels';

/**
 * Creare (node = {id, name}) sau editare (subject). Pe telefon: pe tot ecranul, Renunță/Salvează sus
 * (ca la „Mută în…"). Tipurile și stadiul/vizibilitatea afișate după rol; serverul verifică oricum.
 */
export default function SubjectForm({node, subject, onClose, onSaved}) {
    const theme = useTheme();
    const fullScreen = useMediaQuery(theme.breakpoints.down('sm'));
    const {isAdmin, user} = useAuth();
    const editing = !!subject;
    const types = allowedTypes(isAdmin);
    // Un autor care editează un subiect de tip doar-admin (creat de admin? nu) - păstrăm tipul curent în listă.
    const typeOptions = editing && !types.includes(subject.type) ? [...types, subject.type] : types;

    const [form, setForm] = useState({
        title: subject?.title ?? '',
        description: subject?.description ?? '',
        type: subject?.type ?? types[0],
        stage: subject?.stage ?? 'OPEN',
        visibility: subject?.visibility ?? 'PUBLISHED',
        costEstimate: subject?.costEstimate ?? '',
        costCurrency: subject?.costCurrency ?? '',
        costEstimateScope: subject?.costEstimateScope ?? '',
    });
    const savedSubject = useRef(subject ?? null);
    const ownNote = !editing || user?.id === subject.authorId;
    const [technicalNotes, setTechnicalNotes] = useState('');
    const originalNote = useRef('');
    const [noteLoading, setNoteLoading] = useState(ownNote && subject?.type === 'PROPOSAL');
    const [noteError, setNoteError] = useState(null);
    const [noteRetry, setNoteRetry] = useState(0);
    const [estimateDirty, setEstimateDirty] = useState(false);
    useEffect(() => {
        if (!ownNote || subject?.type !== 'PROPOSAL') return;
        let active = true;
        setNoteLoading(true);
        setNoteError(null);
        fetchSubjectNote(subject.id).then((note) => {
            if (active) { setTechnicalNotes(note.technicalNotes); originalNote.current = note.technicalNotes; }
        }).catch((err) => { if (active) setNoteError(err.message); })
            .finally(() => { if (active) setNoteLoading(false); });
        return () => { active = false; };
    }, [ownNote, subject?.id, subject?.type, noteRetry]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const set = (k) => (e) => setForm({...form, [k]: e.target.value});
    const proposal = form.type === 'PROPOSAL';
    const hasAmount = form.costEstimate !== '';
    const amountError = proposal && estimateDirty && hasAmount && !/^\d{1,10}(?:\.\d{1,2})?$/.test(form.costEstimate)
        ? 'Introdu doar suma, fără monedă: de exemplu 940 sau 940,50. Maximum 10 cifre întregi și două zecimale.' : '';
    const scopeError = proposal && estimateDirty && hasAmount
        ? !form.costEstimateScope.trim() ? 'Precizează ce acoperă suma, de exemplu „Pentru un loc de parcare”.'
            : form.costEstimateScope.trim().split(/\r?\n/)[0].length > 200
                ? 'Primul rând depășește 200 de caractere. Păstrează aici unitatea estimării și mută detaliile pe rândurile următoare.' : ''
        : '';
    const validationMessage = form.title.trim().length < 3 ? 'Titlul trebuie să aibă minimum 3 caractere.'
        : !form.description.trim() ? 'Completează descrierea subiectului.'
            : amountError || scopeError || (proposal && estimateDirty && hasAmount && !form.costCurrency ? 'Alege moneda estimării.' : '')
                || (proposal && noteLoading ? 'Se încarcă nota personală…' : '')
                || (proposal && noteError ? 'Nota personală nu a putut fi încărcată. Apasă „Reîncearcă” în secțiunea notelor.' : '');
    const valid = !validationMessage;
    const setAmount = (event) => {
        const amount = event.target.value.replace(',', '.');
        setEstimateDirty(true);
        setForm({...form, costEstimate: amount, costCurrency: amount === '' ? '' : form.costCurrency || 'RON',
            costEstimateScope: amount === '' ? '' : form.costEstimateScope});
    };
    const setEstimate = (key) => (event) => { setEstimateDirty(true); setForm({...form, [key]: event.target.value}); };

    const save = async () => {
        if (!valid || saving) return;
        setSaving(true);
        setError(null);
        try {
            let saved;
            const baseline = savedSubject.current;
            const estimate = proposal && estimateDirty ? {
                costEstimate: hasAmount ? form.costEstimate : null,
                costCurrency: hasAmount ? form.costCurrency : null,
                costEstimateScope: hasAmount ? form.costEstimateScope.trim() : null,
            } : {};
            if (baseline) {
                const changes = {...estimate};
                for (const k of ['title', 'description', 'type', ...(isAdmin ? ['stage', 'visibility'] : [])]) {
                    if (form[k] !== baseline[k]) changes[k] = form[k];
                }
                saved = Object.keys(changes).length ? await updateSubject(baseline.id, changes) : baseline;
            } else {
                saved = await createSubject({node: node.id, type: form.type, title: form.title, description: form.description, ...estimate});
            }
            // Keep the saved ID if the separate note request fails: retries must not create duplicates.
            savedSubject.current = saved;
            setEstimateDirty(false);
            if (proposal && ownNote && technicalNotes !== originalNote.current) {
                try {
                    const note = await saveSubjectNote(saved.id, technicalNotes);
                    originalNote.current = note.technicalNotes;
                } catch (err) {
                    throw new Error(`Subiectul a fost salvat, dar nota personală nu: ${err.message} Reîncearcă salvarea.`);
                }
            }
            onSaved(saved);
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    };

    const title = editing ? 'Editează subiectul' : 'Subiect nou';

    return (
        <Dialog open onClose={() => !saving && onClose()} fullScreen={fullScreen} fullWidth maxWidth="sm">
            {fullScreen ? (
                <AppBar position="sticky" elevation={1}>
                    <Toolbar sx={{gap: 1, px: 1}}>
                        <IconButton edge="start" color="inherit" onClick={onClose} aria-label="Renunță" disabled={saving}><CloseIcon/></IconButton>
                        <Typography variant="subtitle1" component="h2" noWrap sx={{flex: 1, minWidth: 0}}>{title}</Typography>
                        <Button color="inherit" variant="outlined" onClick={save} disabled={!valid || saving}
                                sx={{flexShrink: 0, '&.Mui-disabled': {color: 'rgba(255,255,255,0.5)', borderColor: 'rgba(255,255,255,0.3)'}}}>
                            Salvează
                        </Button>
                    </Toolbar>
                </AppBar>
            ) : (
                <DialogTitle>{title}</DialogTitle>
            )}
            <DialogContent sx={{pt: fullScreen ? 2 : undefined}}>
                <Stack spacing={2} sx={{pt: 1}}>
                    {error && <Alert severity="error">{error}</Alert>}
                    {!isAdmin && <Alert severity="info">Primele trei contribuții necesită aprobarea unui administrator. După trei aprobări poți publica direct, dacă acest drept nu a fost retras. Modificările subiectelor publicate pot necesita din nou aprobare.</Alert>}
                    {!editing && <Typography variant="body2" color="text.secondary">În: <strong>{node.name}</strong></Typography>}
                    <FormControl fullWidth>
                        <InputLabel id="subject-type">Tip</InputLabel>
                        <Select labelId="subject-type" label="Tip" value={form.type} onChange={set('type')}>
                            {typeOptions.map((t) => <MenuItem key={t} value={t} disabled={!types.includes(t)}>{TYPE_LABELS[t]}</MenuItem>)}
                        </Select>
                    </FormControl>
                    <TextField label="Titlu" required value={form.title} onChange={set('title')} inputProps={{maxLength: 200}}
                               helperText="Minim 3 caractere"/>
                    <TextField label="Descriere" required multiline minRows={4} value={form.description} onChange={set('description')}
                               inputProps={{maxLength: 10000}}/>
                    {proposal && <>
                        <Typography variant="subtitle1" sx={{fontWeight: 600}}>Estimare publică (opțional)</Typography>
                        <Typography variant="body2" color="text.secondary">Suma și explicația sunt vizibile tuturor. Primul rând trebuie să precizeze pentru ce este suma: de exemplu, „Pentru un loc de parcare”.</Typography>
                        {subject?.type !== 'PROPOSAL' && editing && <Alert severity="info">Dacă această propunere avea o estimare, revenirea la acest tip o va face din nou publică. Datele păstrate se restaurează la salvare.</Alert>}
                        <Stack direction={{xs: 'column', sm: 'row'}} spacing={2}>
                            <TextField fullWidth label="Cost estimativ" value={form.costEstimate} onChange={setAmount}
                                error={Boolean(amountError)} inputProps={{inputMode: 'decimal', maxLength: 13}}
                                helperText={amountError || 'Maximum două zecimale. Golirea sumei elimină întreaga estimare.'}/>
                            <FormControl fullWidth disabled={!hasAmount}>
                                <InputLabel id="cost-currency">Monedă</InputLabel>
                                <Select labelId="cost-currency" label="Monedă" value={form.costCurrency} onChange={setEstimate('costCurrency')}>
                                    {['RON', 'EUR', 'USD', 'GBP'].map((currency) => <MenuItem key={currency} value={currency}>{currency}</MenuItem>)}
                                </Select>
                            </FormControl>
                        </Stack>
                        <TextField label="Ce acoperă estimarea" multiline minRows={2} disabled={!hasAmount}
                            value={form.costEstimateScope} onChange={setEstimate('costEstimateScope')} inputProps={{maxLength: 2000}}
                            error={Boolean(scopeError)} helperText={scopeError || 'Primul rând: maximum 200 de caractere. Apoi poți preciza ce include sau exclude suma.'}/>
                        {ownNote && <>
                            <Typography variant="subtitle1" sx={{fontWeight: 600}}>Note personale — nu sunt publice</Typography>
                            <Typography variant="body2" color="text.secondary">Doar tu le poți accesa în aplicație. Operatorul platformei poate avea acces la baza de date și la backup-uri.</Typography>
                            {noteError && <Alert severity="error" action={<Button onClick={() => setNoteRetry((value) => value + 1)}>Reîncearcă</Button>}>{noteError}</Alert>}
                            <TextField label="Note tehnice personale" multiline minRows={4} value={technicalNotes}
                                disabled={noteLoading || Boolean(noteError)} onChange={(event) => setTechnicalNotes(event.target.value)}
                                inputProps={{maxLength: 10000}} helperText={noteLoading ? 'Se încarcă nota…' : 'Memo pentru implementare; nu apare în descrierea publică.'}/>
                        </>}
                    </>}
                    {!proposal && subject?.type === 'PROPOSAL' && <Alert severity="info">Nota și estimarea sunt păstrate, dar devin indisponibile pentru acest tip de subiect.</Alert>}
                    {isAdmin && editing && (
                        <Stack direction={{xs: 'column', sm: 'row'}} spacing={2}>
                            <FormControl fullWidth>
                                <InputLabel id="subject-stage">Stadiu</InputLabel>
                                <Select labelId="subject-stage" label="Stadiu" value={form.stage} onChange={set('stage')}>
                                    {Object.entries(STAGE_LABELS).map(([k, l]) => <MenuItem key={k} value={k}>{l}</MenuItem>)}
                                </Select>
                            </FormControl>
                            <FormControl fullWidth>
                                <InputLabel id="subject-visibility">Vizibilitate</InputLabel>
                                <Select labelId="subject-visibility" label="Vizibilitate" value={form.visibility} onChange={set('visibility')}>
                                    {Object.entries(VISIBILITY_LABELS).map(([k, l]) => <MenuItem key={k} value={k}>{l}</MenuItem>)}
                                </Select>
                            </FormControl>
                        </Stack>
                    )}
                </Stack>
            </DialogContent>
            {validationMessage && <Typography role="status" variant="body2" color="text.secondary"
                sx={{px: 3, py: 1, flexShrink: 0, borderTop: 1, borderColor: 'divider'}}>
                {validationMessage}
            </Typography>}
            {!fullScreen && (
                <DialogActions>
                    <Button onClick={onClose} disabled={saving}>Renunță</Button>
                    <Button variant="contained" onClick={save} disabled={!valid || saving}>Salvează</Button>
                </DialogActions>
            )}
        </Dialog>
    );
}
