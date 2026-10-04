import {useState} from 'react';
import {
    Alert, AppBar, Button, Dialog, DialogActions, DialogContent, DialogTitle, FormControl, IconButton,
    InputLabel, MenuItem, Select, Stack, TextField, Toolbar, Typography, useMediaQuery,
} from '@mui/material';
import {useTheme} from '@mui/material/styles';
import CloseIcon from '@mui/icons-material/Close';
import {useAuth} from '../auth/AuthContext';
import {createSubject, updateSubject} from '../api/subjects';
import {allowedTypes, STAGE_LABELS, TYPE_LABELS, VISIBILITY_LABELS} from '../subjects/labels';

/**
 * Creare (node = {id, name}) sau editare (subject). Pe telefon: pe tot ecranul, Renunță/Salvează sus
 * (ca la „Mută în…"). Tipurile și stadiul/vizibilitatea afișate după rol; serverul verifică oricum.
 */
export default function SubjectForm({node, subject, onClose, onSaved}) {
    const theme = useTheme();
    const fullScreen = useMediaQuery(theme.breakpoints.down('sm'));
    const {isAdmin} = useAuth();
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
    });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const set = (k) => (e) => setForm({...form, [k]: e.target.value});
    const valid = form.title.trim().length >= 3 && form.description.trim().length > 0;

    const save = async () => {
        if (!valid || saving) return;
        setSaving(true);
        setError(null);
        try {
            let saved;
            if (editing) {
                const changes = {};
                for (const k of ['title', 'description', 'type', ...(isAdmin ? ['stage', 'visibility'] : [])]) {
                    if (form[k] !== subject[k]) changes[k] = form[k];
                }
                saved = Object.keys(changes).length ? await updateSubject(subject.id, changes) : subject;
            } else {
                saved = await createSubject({node: node.id, type: form.type, title: form.title, description: form.description});
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
            {!fullScreen && (
                <DialogActions>
                    <Button onClick={onClose} disabled={saving}>Renunță</Button>
                    <Button variant="contained" onClick={save} disabled={!valid || saving}>Salvează</Button>
                </DialogActions>
            )}
        </Dialog>
    );
}
