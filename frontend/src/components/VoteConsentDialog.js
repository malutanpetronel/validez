import {useState} from 'react';
import {Link as RouterLink} from 'react-router-dom';
import {
    Alert, Button, Checkbox, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, Link, Stack, Typography,
} from '@mui/material';

/**
 * Consimțământul explicit pentru voturi (ADR-0007 §6): bifă separată, nebifată inițial; butonul rămâne
 * inactiv până la bifare. `outdated`: există un acord pe o versiune veche a informării.
 */
export default function VoteConsentDialog({outdated = false, onCancel, onAccept}) {
    const [checked, setChecked] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const accept = async () => {
        setBusy(true);
        setError(null);
        try {
            await onAccept();
        } catch (e) {
            setError(e.message);
            setBusy(false);
        }
    };

    return (
        <Dialog open onClose={busy ? undefined : onCancel} maxWidth="sm" fullWidth aria-labelledby="vote-consent-title">
            <DialogTitle id="vote-consent-title">{outdated ? 'Informarea despre voturi s-a actualizat' : 'Înainte de primul vot'}</DialogTitle>
            <DialogContent>
                <Stack spacing={1.5}>
                    {outdated && <Alert severity="info">Voturile tale existente rămân. Pentru a vota din nou, citește versiunea actualizată și confirmă acordul.</Alert>}
                    <Typography variant="body2">Votul tău poate dezvălui opinii, inclusiv politice. De aceea îl prelucrăm doar cu acordul tău explicit:</Typography>
                    <Typography component="ul" variant="body2" sx={{pl: 2.5, m: 0}}>
                        <li>votul tău individual rămâne privat: nu e afișat altor utilizatori sau administratorilor; public sunt doar rezultatele agregate;</li>
                        <li>votul e legat de versiunea subiectului; dacă subiectul se modifică, îl poți revedea, reconfirma sau retrage;</li>
                        <li>poți retrage oricând un vot sau acordul; retragerea acordului retrage toate voturile și șterge istoricul tău privat de vot.</li>
                    </Typography>
                    <Typography variant="body2">
                        Detalii, inclusiv ce urme de integritate rămân după ștergere:{' '}
                        <Link component={RouterLink} to="/privacy?section=voturi" target="_blank">politica de confidențialitate — Voturi</Link>.
                    </Typography>
                    <FormControlLabel
                        control={<Checkbox checked={checked} onChange={(e) => setChecked(e.target.checked)}/>}
                        label="Sunt de acord cu prelucrarea voturilor mele în condițiile de mai sus."/>
                    {error && <Alert severity="error">{error}</Alert>}
                </Stack>
            </DialogContent>
            <DialogActions>
                <Button onClick={onCancel} disabled={busy}>Renunță</Button>
                <Button variant="contained" onClick={accept} disabled={!checked || busy}>Sunt de acord și votez</Button>
            </DialogActions>
        </Dialog>
    );
}
