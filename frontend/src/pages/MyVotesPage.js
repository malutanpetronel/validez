import {useCallback, useEffect, useState} from 'react';
import {Link as RouterLink} from 'react-router-dom';
import {
    Alert, Button, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, Link, List, ListItem,
    ListItemText, Paper, Stack, Typography,
} from '@mui/material';
import {useAuth} from '../auth/AuthContext';
import {exportMyVotes, fetchVoteConsent, withdrawVoteConsent} from '../api/votes';
import {VOTE_TYPE_LABELS, voteValueLabel} from '../subjects/labels';

/**
 * Voturile mele (ADR-0007 §6): starea acordului, retragerea lui, voturile curente și exportul datelor de vot
 * (cu tot ce trebuie pentru verificarea propriilor înregistrări). Răspunsurile serverului sunt private, no-store.
 */
export default function MyVotesPage() {
    const {user} = useAuth();
    const [consent, setConsent] = useState(null);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        const [c, d] = await Promise.all([fetchVoteConsent(), exportMyVotes()]);
        setConsent(c);
        setData(d);
    }, []);

    useEffect(() => {
        if (!user) return;
        load().catch((e) => setError(e.message));
    }, [user, load]);

    if (!user) {
        return <Paper sx={{p: {xs: 2, sm: 3}}}><Alert severity="info">
            <Link component={RouterLink} to="/login">Intră în cont</Link> pentru a-ți vedea voturile.</Alert></Paper>;
    }

    const withdraw = async () => {
        setBusy(true);
        try {
            const r = await withdrawVoteConsent();
            setConfirming(false);
            setNotice(`Acordul a fost retras. ${r.withdrawnVotes} ${r.withdrawnVotes === 1 ? 'vot retras' : 'voturi retrase'}; istoricul tău privat de vot a fost șters.`);
            await load();
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    };

    const download = () => {
        const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `validez-voturile-mele-${new Date().toISOString().slice(0, 10)}.json`;
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <Paper sx={{p: {xs: 2, sm: 3}, maxWidth: 860, mx: 'auto'}}>
            <Stack spacing={2}>
                <Typography component="h1" variant="h5">Voturile mele</Typography>
                {error && <Alert severity="error" onClose={() => setError(null)}>{error}</Alert>}
                {notice && <Alert severity="success" onClose={() => setNotice(null)}>{notice}</Alert>}
                {!consent && !error && <Typography color="text.secondary">Se încarcă…</Typography>}
                {consent && (
                    <Stack spacing={1}>
                        <Typography component="h2" variant="h6">Acordul pentru voturi</Typography>
                        {!consent.votingEnabled && <Alert severity="info">Votarea nu este încă activă.</Alert>}
                        {consent.active ? (
                            <Typography>
                                Ți-ai dat acordul (informarea din {consent.noticeVersion}).
                                {!consent.current && ' Informarea s-a actualizat între timp; la următorul vot ți se va cere din nou acordul. Voturile existente rămân.'}
                            </Typography>
                        ) : <Typography>Nu ai un acord activ. Acordul se cere la primul vot.</Typography>}
                        <Typography variant="body2">
                            <Link component={RouterLink} to="/privacy?section=voturi">Cum prelucrăm voturile</Link>
                        </Typography>
                        {consent.active && <div><Button color="error" variant="outlined" onClick={() => setConfirming(true)}>Retrage acordul</Button></div>}
                    </Stack>
                )}
                {data && (
                    <Stack spacing={1}>
                        <Typography component="h2" variant="h6">Voturile tale ({data.votes.length})</Typography>
                        {data.votes.length === 0 ? <Typography color="text.secondary">Nu ai voturi active.</Typography> : (
                            <List dense disablePadding>
                                {data.votes.map((v) => (
                                    <ListItem key={`${v.subjectId}:${v.voteType}`} disableGutters>
                                        <ListItemText
                                            primary={<Link component={RouterLink} to={`/subiecte/${v.subjectId}`}>Subiectul {v.subjectId.slice(-6)}</Link>}
                                            secondary={`${VOTE_TYPE_LABELS[v.voteType] ?? v.voteType}: ${voteValueLabel(v.voteType, v.value)}${v.current ? '' : ` — nereconfirmat (revizia ${v.revisionNumber})`}`}/>
                                    </ListItem>
                                ))}
                            </List>
                        )}
                        <Typography variant="body2" color="text.secondary">
                            Exportul conține voturile, istoricul tău privat și, pentru fiecare înregistrare, datele necesare verificării ei în jurnalul de integritate.
                        </Typography>
                        <div><Button variant="outlined" onClick={download}>Descarcă datele mele de vot (JSON)</Button></div>
                    </Stack>
                )}
            </Stack>
            {confirming && (
                <Dialog open onClose={busy ? undefined : () => setConfirming(false)} aria-labelledby="withdraw-consent-title">
                    <DialogTitle id="withdraw-consent-title">Retragi acordul pentru voturi?</DialogTitle>
                    <DialogContent>
                        <DialogContentText>
                            Toate voturile tale active vor fi retrase, iar rezultatele publice se actualizează. Istoricul tău privat de vot
                            va fi șters, inclusiv datele care îți permit să-ți verifici înregistrările. Acțiunea nu poate fi anulată.
                            Poți vota din nou mai târziu, cu un acord nou.
                        </DialogContentText>
                    </DialogContent>
                    <DialogActions>
                        <Button onClick={() => setConfirming(false)} disabled={busy}>Renunță</Button>
                        <Button color="error" variant="contained" onClick={withdraw} disabled={busy}>Retrage acordul</Button>
                    </DialogActions>
                </Dialog>
            )}
        </Paper>
    );
}
