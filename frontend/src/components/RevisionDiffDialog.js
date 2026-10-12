import {useEffect, useState} from 'react';
import {
    Alert, AppBar, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, FormControl, IconButton,
    InputLabel, MenuItem, Select, Stack, Toolbar, Typography, useMediaQuery,
} from '@mui/material';
import {useTheme} from '@mui/material/styles';
import CloseIcon from '@mui/icons-material/Close';
import {fetchRevisions} from '../api/votes';
import {formatDate, TYPE_LABELS} from '../subjects/labels';
import {diffWords, onlyWhitespaceChanged} from '../subjects/wordDiff';

const estimateText = (e) => (e ? `${e.costEstimate} ${e.costCurrency} — ${e.costEstimateScope}` : 'fără estimare');

/** Text cu diferența marcată: <del> pentru eliminat, <ins> pentru adăugat. Peste prag: versiunile separat. */
function TextDiff({label, before, after, wide}) {
    if (before === after) return null;
    const segments = diffWords(before, after);
    return (
        <Stack spacing={0.75}>
            <Typography variant="subtitle2">{label}</Typography>
            {segments && !onlyWhitespaceChanged(segments) ? (
                <Typography component="div" sx={{whiteSpace: 'pre-wrap', wordBreak: 'break-word'}}>
                    {segments.map((s, i) => (s.type === 'same' ? <span key={i}>{s.text}</span>
                        : <Box key={i} component={s.type === 'added' ? 'ins' : 'del'} sx={s.type === 'added'
                            ? {bgcolor: 'success.light', color: 'success.contrastText', textDecoration: 'none', borderRadius: 0.5}
                            : {bgcolor: 'error.light', color: 'error.contrastText', borderRadius: 0.5}}>{s.text}</Box>))}
                </Typography>
            ) : (
                // Text prea lung pentru diferența pe cuvinte (sau doar spațiile diferă): versiunile complete,
                // una lângă alta pe ecran lat, una sub alta pe telefon.
                <Stack direction={wide ? 'row' : 'column'} spacing={1.5}>
                    {[['Înainte', before], ['După', after]].map(([title, text]) => (
                        <Box key={title} sx={{flex: 1, minWidth: 0, p: 1, border: 1, borderColor: 'divider', borderRadius: 1}}>
                            <Typography variant="caption" color="text.secondary">{title}</Typography>
                            <Typography sx={{whiteSpace: 'pre-wrap', wordBreak: 'break-word'}}>{text}</Typography>
                        </Box>
                    ))}
                </Stack>
            )}
        </Stack>
    );
}

/**
 * Istoricul reviziilor și diferența dintre două revizii (implicit: revizia votată → cea curentă).
 * Estimarea vine de la server doar pentru propuneri.
 */
export default function RevisionDiffDialog({subjectId, from, to, onClose}) {
    const theme = useTheme();
    const fullScreen = useMediaQuery(theme.breakpoints.down('sm'));
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [pair, setPair] = useState({from, to});

    useEffect(() => {
        let active = true;
        fetchRevisions(subjectId).then((d) => {
            if (!active) return;
            setData(d);
            setPair((p) => ({from: p.from ?? Math.max(1, d.currentRevision - 1), to: p.to ?? d.currentRevision}));
        }).catch((e) => { if (active) setError(e.message); });
        return () => { active = false; };
    }, [subjectId]);

    const byNumber = Object.fromEntries((data?.revisions ?? []).map((r) => [r.number, r]));
    const a = byNumber[pair.from];
    const b = byNumber[pair.to];
    const select = (key, label) => (
        <FormControl size="small" sx={{minWidth: 140}}>
            <InputLabel id={`rev-${key}`}>{label}</InputLabel>
            <Select labelId={`rev-${key}`} label={label} value={pair[key] ?? ''} onChange={(e) => setPair((p) => ({...p, [key]: e.target.value}))}>
                {(data?.revisions ?? []).map((r) => <MenuItem key={r.number} value={r.number}>
                    Revizia {r.number}{r.number === data.currentRevision ? ' (curentă)' : ''}</MenuItem>)}
            </Select>
        </FormControl>
    );
    const title = 'Ce s-a schimbat';

    return (
        <Dialog open onClose={onClose} fullScreen={fullScreen} fullWidth maxWidth="md">
            {fullScreen ? (
                <AppBar position="sticky"><Toolbar>
                    <IconButton edge="start" color="inherit" onClick={onClose} aria-label="Închide"><CloseIcon/></IconButton>
                    <Typography sx={{flex: 1}} variant="h6" component="h2">{title}</Typography>
                </Toolbar></AppBar>
            ) : <DialogTitle>{title}</DialogTitle>}
            <DialogContent sx={{pt: fullScreen ? 2 : undefined}}>
                <Stack spacing={2} sx={{pt: 1}}>
                    {error && <Alert severity="warning">{error}</Alert>}
                    {!error && !data && <Typography color="text.secondary">Se încarcă…</Typography>}
                    {data && (
                        <>
                            <Stack direction="row" spacing={1.5} useFlexGap sx={{flexWrap: 'wrap', alignItems: 'center'}}>
                                {select('from', 'De la')}
                                {select('to', 'La')}
                            </Stack>
                            {a && b && (
                                <Typography variant="body2" color="text.secondary">
                                    Revizia {a.number} ({formatDate(a.createdAt)}) → revizia {b.number} ({formatDate(b.createdAt)})
                                    {b.byAuthor ? ', modificată de autor' : ', modificată de un administrator'}
                                </Typography>
                            )}
                            {a && b && a.number === b.number && <Alert severity="info">Alege două revizii diferite.</Alert>}
                            {a && b && a.number !== b.number && (
                                <Stack spacing={2}>
                                    {a.type !== b.type && <Typography><strong>Tip:</strong> <del>{TYPE_LABELS[a.type] ?? a.type}</del> → <ins>{TYPE_LABELS[b.type] ?? b.type}</ins></Typography>}
                                    <TextDiff label="Titlu" before={a.title} after={b.title} wide={!fullScreen}/>
                                    <TextDiff label="Descriere" before={a.description} after={b.description} wide={!fullScreen}/>
                                    {estimateText(a.estimate) !== estimateText(b.estimate) && (
                                        <Typography><strong>Estimare:</strong> <del>{estimateText(a.estimate)}</del> → <ins>{estimateText(b.estimate)}</ins></Typography>
                                    )}
                                    {a.type === b.type && a.title === b.title && a.description === b.description && estimateText(a.estimate) === estimateText(b.estimate)
                                        && <Alert severity="info">Nicio diferență vizibilă între aceste revizii.</Alert>}
                                </Stack>
                            )}
                        </>
                    )}
                </Stack>
            </DialogContent>
            {!fullScreen && <DialogActions><Button onClick={onClose}>Închide</Button></DialogActions>}
        </Dialog>
    );
}
