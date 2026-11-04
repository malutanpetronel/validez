import {useCallback, useEffect, useState} from 'react';
import {Link as RouterLink, useLocation} from 'react-router-dom';
import {
    Alert, Box, Button, Chip, Divider, FormControlLabel, LinearProgress, Radio, RadioGroup, Rating, Stack, ToggleButton, ToggleButtonGroup, Typography,
} from '@mui/material';
import StarRoundedIcon from '@mui/icons-material/StarRounded';
import ThumbUpOutlinedIcon from '@mui/icons-material/ThumbUpOutlined';
import ThumbDownOutlinedIcon from '@mui/icons-material/ThumbDownOutlined';
import {useAuth} from '../auth/AuthContext';
import {castVote, fetchVotes, grantVoteConsent, withdrawVote} from '../api/votes';
import {formatDate, VOTE_TYPE_HINTS, VOTE_TYPE_LABELS, voteOptions, voteValueLabel} from '../subjects/labels';
import VoteConsentDialog from './VoteConsentDialog';
import RevisionDiffDialog from './RevisionDiffDialog';

const percent = (n, total) => (total > 0 ? Math.round((n / total) * 100) : 0);
const participants = (n) => `${n} ${n === 1 ? 'participant' : 'participanți'}`;

/** Rezultatele unui sondaj (ADR-0013 p.6): ascunse până la votul propriu sau închidere; totalul e public. */
function PollResults({type}) {
    const {counts, total, hidden} = type.result;
    if (hidden) {
        return <Typography variant="body2" color="text.secondary">
            Rezultatele se văd după ce votezi sau după închiderea sondajului. Până acum: {participants(total)}.
        </Typography>;
    }
    if (total === 0) return <Typography variant="body2" color="text.secondary">Niciun vot încă.</Typography>;
    return (
        <Stack spacing={1} aria-label="Rezultatele sondajului">
            {voteOptions(type).map(([value, label]) => {
                const n = counts[value] ?? 0;
                const own = type.mine?.value === value;
                return (
                    <Box key={value} sx={{minWidth: 0}}>
                        <Stack direction="row" spacing={1} sx={{justifyContent: 'space-between', alignItems: 'baseline', minWidth: 0}}>
                            <Typography variant="body2" sx={{fontWeight: own ? 700 : 400, overflowWrap: 'anywhere', minWidth: 0}}>
                                {label}{own && ' · votul tău'}
                            </Typography>
                            <Typography variant="body2" sx={{flexShrink: 0}}>{percent(n, total)}% ({n})</Typography>
                        </Stack>
                        <LinearProgress variant="determinate" value={percent(n, total)} aria-hidden
                                        sx={{height: 8, borderRadius: 4, '& .MuiLinearProgress-bar': {borderRadius: 4, bgcolor: own ? 'primary.main' : 'text.secondary'}}}/>
                    </Box>
                );
            })}
            <Typography variant="caption" color="text.secondary">{participants(total)}</Typography>
        </Stack>
    );
}

/**
 * Alegerea unei singure variante (ADR-0013 p.3), listă verticală pe toată lățimea, apoi „Votează”.
 * Părintele o remontează (key) când se schimbă votul propriu; fără efect de sincronizare, care ar putea
 * suprascrie o alegere făcută imediat după montare.
 */
function PollChoice({type, disabled, onVote}) {
    const own = type.mine?.value ?? null;
    const [selected, setSelected] = useState(own);
    return (
        <Box sx={{my: 0.75}}>
            <RadioGroup aria-label="Variantele sondajului" value={selected === null ? '' : String(selected)}
                        onChange={(e) => setSelected(Number(e.target.value))}>
                {voteOptions(type).map(([value, label]) => (
                    <FormControlLabel key={value} value={String(value)} control={<Radio/>} label={label} disabled={disabled}
                                      sx={{mr: 0, minWidth: 0, alignItems: 'flex-start', '& .MuiFormControlLabel-label': {pt: 1.1, overflowWrap: 'anywhere'}}}/>
                ))}
            </RadioGroup>
            <Button variant="contained" sx={{mt: 1}} disabled={disabled || selected === null || selected === own} onClick={() => onVote(selected)}>
                {own === null ? 'Votează' : 'Schimbă votul'}
            </Button>
        </Box>
    );
}

function Results({type}) {
    const {counts, total, staleTotal} = type.result;
    const options = voteOptions(type);
    const binary = type.maxValue - type.minValue === 1;
    const sum = Object.entries(counts).reduce((acc, [value, n]) => acc + Number(value) * n, 0);
    return (
        <Stack spacing={0.25}>
            {total === 0 ? <Typography variant="body2" color="text.secondary">Niciun vot pe versiunea curentă.</Typography>
                : binary ? (
                    <Typography variant="body2">
                        {options.map(([value, label]) => `${label}: ${counts[value] ?? 0} (${percent(counts[value] ?? 0, total)}%)`).join(' · ')}
                        {` · ${total} ${total === 1 ? 'vot' : 'voturi'}`}
                    </Typography>
                ) : (
                    <Box>
                        <Rating readOnly value={sum / total} max={type.maxValue} precision={0.1} icon={<StarRoundedIcon fontSize="inherit"/>} emptyIcon={<StarRoundedIcon fontSize="inherit"/>} sx={{color: '#c58b12', '& .MuiRating-iconEmpty': {color: 'text.disabled'}}}/>
                        <Typography variant="body2">
                            Media {(sum / total).toLocaleString('ro-RO', {maximumFractionDigits: 1})} din {type.maxValue} · {total} {total === 1 ? 'vot' : 'voturi'}
                        </Typography>
                    </Box>
                )}
            {staleTotal > 0 && <Typography variant="caption" color="text.secondary">
                + {staleTotal} {staleTotal === 1 ? 'vot dat' : 'voturi date'} pe versiuni anterioare, nereconfirmate</Typography>}
        </Stack>
    );
}

/**
 * Votul pe un subiect (Step 4 — frontend, ADR-0007). Starea vine din API, inclusiv `votingEnabled`
 * (comutatorul e doar pe server). Vizitatorul vede rezultatele agregate.
 */
export default function SubjectVotes({subjectId}) {
    const {user} = useAuth();
    const location = useLocation();
    const [summary, setSummary] = useState(null);
    const [message, setMessage] = useState(null);
    const [busy, setBusy] = useState(false);
    const [consentIntent, setConsentIntent] = useState(null);
    const [hoveredStar, setHoveredStar] = useState(null);
    const [diff, setDiff] = useState(null);

    useEffect(() => {
        if (summary) window.dispatchEvent(new CustomEvent('subject-votes-updated', {detail: {subjectId, summary}}));
    }, [subjectId, summary]);

    const reload = useCallback(() => fetchVotes(subjectId, {fresh: true}).then((s) => { setSummary(s); return s; }), [subjectId]);

    useEffect(() => {
        let active = true;
        setSummary(null);
        setMessage(null);
        fetchVotes(subjectId).then((s) => { if (active) setSummary(s); }).catch(() => { if (active) setSummary(null); });
        return () => { active = false; };
    }, [subjectId, user?.id]);

    const fail = async (e, intent) => {
        if (e.reason === 'consent_required' && intent) {
            setConsentIntent(intent);
            return;
        }
        if (e.reason === 'rate_limited') {
            setMessage({severity: 'warning', text: `Prea multe operații de vot. Reîncearcă în ${e.retryAfter ?? 60} de secunde.`});
            return;
        }
        setMessage({severity: e.status === 409 ? 'info' : 'error', text: e.message});
        if (e.status === 409) await reload().catch(() => {});
    };

    /** Trimite intenția de vot. `retried`: după consimțământ, o singură dată, fără altă buclă de consimțământ. */
    const send = async (intent, retried = false) => {
        setBusy(true);
        setMessage(null);
        try {
            setSummary(await castVote(subjectId, intent.code, intent.value));
        } catch (e) {
            await fail(e, retried ? null : intent);
        } finally {
            setBusy(false);
        }
    };

    const vote = (code, value) => {
        const intent = {code, value, revision: summary.revisionNumber};
        if (summary.consent && !summary.consent.current) setConsentIntent(intent);
        else send(intent);
    };

    const acceptConsent = async () => {
        const intent = consentIntent;
        await grantVoteConsent(summary.consent?.currentNoticeVersion ?? summary.consent?.noticeVersion);
        setConsentIntent(null);
        const fresh = await reload();
        // Subiectul s-a schimbat cât timp utilizatorul citea informarea: nu trimitem votul pe altă versiune.
        if (fresh.revisionNumber !== intent.revision) {
            setMessage({severity: 'info', text: 'Subiectul s-a modificat între timp. Revezi-l, apoi votează din nou.'});
            return;
        }
        await send(intent, true);
    };

    const withdraw = async (code) => {
        setBusy(true);
        setMessage(null);
        try {
            await withdrawVote(subjectId, code);
            await reload();
        } catch (e) {
            await fail(e, null);
        } finally {
            setBusy(false);
        }
    };

    if (!summary || !Array.isArray(summary.types) || summary.types.length === 0) return null;

    const canExpress = (type) => !!user && summary.votingOpen && type.active;
    const stale = summary.types.filter((t) => t.mine && !t.mine.current);
    // Revenire exact aici după înregistrare/confirmare/login: pagina subiectului sau panoul din arbore.
    const from = `${location.pathname}${location.search}`;

    return (
        <Stack spacing={1.5} component="section" aria-labelledby={`votes-${subjectId}`} id={`subject-votes-${subjectId}`}
               tabIndex={-1} sx={{scrollMarginTop: 80, outline: 'none'}}>
            <Divider/>
            <Typography id={`votes-${subjectId}`} variant="h6" component="h2">Voturi</Typography>
            {summary.pollClosesAt && <Typography variant="body2" color="text.secondary">
                {summary.frozen ? 'Sondaj închis' : `Sondajul se închide pe ${formatDate(summary.pollClosesAt)}`}
            </Typography>}
            {!summary.votingEnabled && <Alert severity="info">Votarea nu este încă activă. Poți vedea rezultatele și îți poți retrage oricând voturile existente.</Alert>}
            {summary.votingEnabled && summary.frozen && <Alert severity="info">
                {summary.pollClosesAt ? 'Sondajul s-a închis: rezultatele sunt publice. Îți poți retrage votul.' : 'Subiectul este rezolvat sau închis: votarea s-a încheiat. Îți poți retrage votul.'}
            </Alert>}
            {summary.votingEnabled && !summary.frozen && !summary.votingOpen && <Alert severity="info">Votarea începe după publicarea subiectului.</Alert>}
            {!user && summary.votingOpen && (
                <Alert severity="info" icon={false} action={(
                    <Stack direction="row" spacing={1}>
                        <Button variant="contained" size="small" component={RouterLink} to="/inregistrare" state={{from}}>Înregistrează-te</Button>
                        <Button size="small" component={RouterLink} to="/login" state={{from}}>Intră</Button>
                    </Stack>
                )}>
                    Înregistrează-te pentru a vota și tu.
                </Alert>
            )}
            {stale.length > 0 && (
                <Alert severity="warning" action={<Button color="inherit" size="small"
                    onClick={() => setDiff({from: Math.min(...stale.map((t) => t.mine.revisionNumber)), to: summary.revisionNumber})}>Vezi ce s-a schimbat</Button>}>
                    Subiectul s-a modificat de la votul tău. {summary.votingOpen ? 'Reconfirmă sau schimbă votul pentru versiunea curentă.' : ''}
                </Alert>
            )}
            {message && <Alert severity={message.severity} onClose={() => setMessage(null)}>{message.text}</Alert>}
            {/* LOCATION e inactiv cât subiectul nu are pin (ADR-0011 p.9): nu-l arătăm, dacă nu ai un vot de retras. */}
            {summary.types.filter((type) => type.code !== 'LOCATION' || type.active || type.mine).map((type) => (type.code === 'CHOICE' ? (
                <Box key={type.code} sx={{p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 3, bgcolor: 'background.paper', minWidth: 0}}>
                    <Typography variant="subtitle2" component="h3">{VOTE_TYPE_LABELS.CHOICE}</Typography>
                    {user && summary.votingEnabled && canExpress(type) && <PollChoice key={type.mine?.value ?? 'fara-vot'} type={type} disabled={busy}
                                                                                      onVote={(value) => vote(type.code, value)}/>}
                    <PollResults type={type}/>
                    {type.mine && (
                        <Typography variant="body2" sx={{mt: 0.5, overflowWrap: 'anywhere'}}>
                            Votul tău: <strong>{voteValueLabel(type.code, type.mine.value, type.labels)}</strong>
                            {' · '}
                            <Button sx={{textTransform: 'none'}} size="small" disabled={busy} onClick={() => withdraw(type.code)}>Retrage votul</Button>
                        </Typography>
                    )}
                </Box>
            ) : (
                <Box key={type.code} sx={{p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 3, bgcolor: 'background.paper'}}>
                    <Stack direction="row" spacing={1} useFlexGap sx={{alignItems: 'center', flexWrap: 'wrap'}}>
                        <Typography variant="subtitle2" component="h3">{VOTE_TYPE_LABELS[type.code] ?? type.code}</Typography>
                        {!type.active && <Chip size="small" label="Tip de vot dezactivat" variant="outlined"/>}
                        {type.mine && !type.mine.current && <Chip size="small" color="warning" label="Votul tău: nereconfirmat"/>}
                    </Stack>
                    {VOTE_TYPE_HINTS[type.code] && <Typography variant="caption" color="text.secondary">{VOTE_TYPE_HINTS[type.code]}</Typography>}
                    {user && summary.votingEnabled && (
                        <Box sx={{my: 0.75}}>
                            <ToggleButtonGroup onMouseLeave={() => setHoveredStar(null)} exclusive size="small" sx={{gap: 1, flexWrap: 'wrap', '& .MuiToggleButtonGroup-grouped': {m: 0, border: '1px solid', borderColor: 'divider', borderRadius: '10px !important'}, '& .MuiToggleButton-root': {minHeight: 44, px: type.code === 'IMPORTANCE' ? 0.5 : 1.75, textTransform: 'none', fontWeight: 600, gap: 1}, '& .MuiToggleButton-root.Mui-selected': {bgcolor: 'action.selected', color: 'primary.main', borderColor: 'primary.main'}}} aria-label={VOTE_TYPE_LABELS[type.code] ?? type.code}
                                               value={type.mine?.current ? type.mine.value : null}
                                               disabled={busy || !canExpress(type)}>
                                {voteOptions(type).map(([value, label]) => (
                                    <ToggleButton onMouseEnter={() => { if (type.code === 'IMPORTANCE' && !busy && canExpress(type)) setHoveredStar(value); }} key={value} value={value} onClick={() => vote(type.code, value)}
                                                  aria-label={label} sx={type.code === 'IMPORTANCE' ? {minWidth: 40, border: '0 !important', '&:hover': {bgcolor: 'action.hover'}, '&.Mui-selected': {bgcolor: 'transparent'}} : {}}>
                                        {type.code === 'IMPORTANCE' ? <StarRoundedIcon sx={{fontSize: 36, color: value <= (hoveredStar ?? (type.mine?.current ? type.mine.value : 0)) ? '#c58b12' : 'text.disabled'}}/>
                                            : <>{value === 1 ? <ThumbUpOutlinedIcon fontSize="small"/> : <ThumbDownOutlinedIcon fontSize="small"/>}{label}</>}
                                    </ToggleButton>
                                ))}
                            </ToggleButtonGroup>
                            {type.mine && !type.mine.current && canExpress(type) && (
                                <Button size="small" sx={{ml: 1}} disabled={busy} onClick={() => vote(type.code, type.mine.value)}>
                                    Reconfirmă „{voteValueLabel(type.code, type.mine.value)}”
                                </Button>
                            )}
                        </Box>
                    )}
                    <Results type={type}/>
                    {type.mine && (
                        <Typography variant="body2" sx={{mt: 0.5}}>
                            Votul tău: <strong>{voteValueLabel(type.code, type.mine.value)}</strong>
                            {!type.mine.current && ` (pe revizia ${type.mine.revisionNumber})`}
                            {' · '}
                            <Button sx={{textTransform: 'none'}} size="small" disabled={busy} onClick={() => withdraw(type.code)}>Retrage votul</Button>
                        </Typography>
                    )}
                </Box>
            )))}
            {summary.revisionNumber > 1 && (
                <div><Button size="small" onClick={() => setDiff({from: summary.revisionNumber - 1, to: summary.revisionNumber})}>
                    Istoricul modificărilor ({summary.revisionNumber} revizii)</Button></div>
            )}
            {consentIntent && <VoteConsentDialog outdated={!!summary.consent?.active && !summary.consent?.current}
                                                 onCancel={() => setConsentIntent(null)} onAccept={acceptConsent}/>}
            {diff && <RevisionDiffDialog subjectId={subjectId} from={diff.from} to={diff.to} onClose={() => setDiff(null)}/>}
        </Stack>
    );
}
