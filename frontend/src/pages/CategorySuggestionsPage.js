import {useEffect, useState} from 'react';
import {Navigate} from 'react-router-dom';
import {Alert, Box, Button, Chip, List, MenuItem, Pagination, Paper, Skeleton, Stack, TextField, Typography} from '@mui/material';
import {formatDate} from '../subjects/labels';
import {useAuth} from '../auth/AuthContext';
import {fetchChildren} from '../api/tree';
import {fetchCategorySuggestions, reviewCategory, suggestCategory} from '../api/contributions';

const STATUS_COLOR = {PENDING: 'warning', APPROVED: 'success', REJECTED: 'error'};
const STATUS = {PENDING: 'În așteptarea aprobării', APPROVED: 'Aprobată', REJECTED: 'Respinsă'};

export default function CategorySuggestionsPage() {
    const {user, isAdmin} = useAuth();
    const [data, setData] = useState({items: [], total: 0});
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [version, setVersion] = useState(0);
    const [path, setPath] = useState([]);
    const parent = path[path.length - 1];
    const [children, setChildren] = useState([]);
    const [parentLoading, setParentLoading] = useState(true);
    const [parentError, setParentError] = useState(null);
    const [name, setName] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);
    useEffect(() => {
        if (!user) return;
        let active = true;
        setError(null); setLoading(true);
        fetchCategorySuggestions(page).then((value) => { if (active) setData(value); })
            .catch((err) => { if (active) setError(err.message); })
            .finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [page, version, user]);
    useEffect(() => {
        if (!user || isAdmin) return;
        let active = true;
        setChildren([]); setParentLoading(true); setParentError(null);
        fetchChildren(parent?.id ?? null).then((value) => { if (active) setChildren(value); })
            .catch((err) => { if (active) setParentError(err.message); })
            .finally(() => { if (active) setParentLoading(false); });
        return () => { active = false; };
    }, [parent?.id, user, isAdmin]);
    if (!user) return <Navigate to="/login" replace/>;
    const act = async (action) => {
        setBusy(true); setError(null); setSuccess(null);
        try { await action(); setVersion((value) => value + 1); }
        catch (err) { setError(err.message); }
        finally { setBusy(false); }
    };
    const submit = () => act(async () => {
        await suggestCategory({name, reason, parent: parent?.id ?? null});
        setName(''); setReason(''); setPage(1); setSuccess('Propunerea a fost trimisă administratorilor.');
    });
    return <Stack spacing={2}>
        <Typography variant="h4" component="h1">Propuneri de categorii</Typography>
        {error && <Alert severity="error">{error}</Alert>}
        {success && <Alert severity="success">{success}</Alert>}
        <Paper component="section" aria-labelledby="suggestions-heading" sx={{p: {xs: 2, sm: 2.5}, minWidth: 0}}>
            <Stack spacing={1.5} sx={{mb: 2, pb: 2, borderBottom: 1, borderColor: 'divider'}}>
                <Box sx={{minWidth: 0}}>
                    <Typography variant="overline" color="text.secondary">Categorii propuse</Typography>
                    <Typography id="suggestions-heading" variant="h6" component="h2" sx={{fontWeight: 700, lineHeight: 1.35, overflowWrap: 'anywhere'}}>
                        {isAdmin ? 'Propunerile utilizatorilor' : 'Propunerile tale'}
                    </Typography>
                </Box>
                <Typography variant="body2" color="text.secondary" aria-live="polite">
                    {loading ? 'Se încarcă…' : `${data.total} ${data.total === 1 ? 'propunere' : 'propuneri'}`}
                </Typography>
            </Stack>
            {!loading && !error && !data.items.length && <Typography color="text.secondary" sx={{py: 2}}>Nu există propuneri.</Typography>}
            {loading && !data.items.length && <Stack spacing={1} aria-label="Se încarcă propunerile">
                {[0, 1, 2].map((key) => <Skeleton key={key} variant="rounded" height={112}/>)}
            </Stack>}
            <List disablePadding aria-label="Lista propunerilor" aria-busy={loading}
                sx={{display: 'grid', gap: 1.25, opacity: loading ? 0.6 : 1}}>
                {data.items.map((item) => <Box component="li" key={item.id} sx={{listStyle: 'none', minWidth: 0}}>
                    <Box component="article" sx={{p: {xs: 1.5, sm: 2}, border: 1, borderColor: 'divider', borderRadius: 2}}>
                        <Stack spacing={1} sx={{minWidth: 0}}>
                            <Typography variant="subtitle1" component="h3" sx={{fontWeight: 600, lineHeight: 1.4, overflowWrap: 'anywhere'}}>{item.name}</Typography>
                            <Stack direction="row" spacing={0.75} useFlexGap sx={{flexWrap: 'wrap', alignItems: 'center'}}>
                                <Chip size="small" label="Categorie" variant="outlined" sx={{fontSize: '0.75rem'}}/>
                                <Chip size="small" color={STATUS_COLOR[item.status] ?? 'default'} label={STATUS[item.status] ?? item.status} sx={{fontSize: '0.75rem'}}/>
                            </Stack>
                            <Typography variant="body2" sx={{whiteSpace: 'pre-wrap', overflowWrap: 'anywhere'}}>{item.reason}</Typography>
                            <Typography variant="caption" color="text.secondary" sx={{overflowWrap: 'anywhere'}}>
                                În {item.parentName ?? 'Rădăcina arborelui'}
                                {isAdmin ? ` · ${item.authorName}` : ''}
                                {item.created_at ? ` · ${formatDate(item.created_at)}` : ''}
                            </Typography>
                            {isAdmin && item.status === 'PENDING' && <Stack direction={{xs: 'column', sm: 'row'}} spacing={1} sx={{pt: 0.5}}>
                                <Button variant="outlined" size="small" sx={{minHeight: 40}} disabled={busy || loading} onClick={() => act(() => reviewCategory(item.id, 'APPROVED'))}>Aprobă și creează categoria</Button>
                                <Button variant="outlined" size="small" sx={{minHeight: 40}} disabled={busy || loading} color="error" onClick={() => act(() => reviewCategory(item.id, 'REJECTED'))}>Respinge</Button>
                            </Stack>}
                        </Stack>
                    </Box>
                </Box>)}
            </List>
            {data.total > 20 && <Stack sx={{alignItems: 'center', mt: 1.5}}>
                <Pagination size="small" siblingCount={0} page={page} count={Math.ceil(data.total / 20)} onChange={(_, value) => setPage(value)}/>
            </Stack>}
        </Paper>
        {!isAdmin && <Paper sx={{p: {xs: 2, sm: 2.5}}}><Stack spacing={2}>
            <Typography variant="h6" component="h2">Propune o categorie nouă</Typography>
            <Typography>Verifică mai întâi arborele. Administratorul aprobă și creează categoria sau folosește una existentă.</Typography>
            <Typography>Părinte: {path.length ? path.map((node) => node.name).join(' › ') : 'Fără părinte — rădăcină nouă'}</Typography>
            {path.length > 0 && <div><Button disabled={busy} onClick={() => setPath((value) => value.slice(0, -1))}>Un nivel mai sus</Button></div>}
            {parentError && <Alert severity="error">{parentError}</Alert>}
            <TextField select label="Caută categoria părinte" value="" disabled={busy || parentLoading || Boolean(parentError)} onChange={(event) => {
                const node = children.find((item) => item.id === event.target.value);
                if (node) setPath((value) => [...value, node]);
            }} helperText={parentLoading ? 'Se încarcă…' : 'Alege o ramură pentru a coborî în arbore. Părintele afișat mai sus va primi noua categorie.'}>
                <MenuItem value="">{children.length ? 'Alege o ramură' : 'Nu există subcategorii'}</MenuItem>
                {children.map((node) => <MenuItem key={node.id} value={node.id}>{node.name}</MenuItem>)}
            </TextField>
            <TextField label="Numele categoriei" value={name} onChange={(event) => setName(event.target.value)} inputProps={{maxLength: 120}} disabled={busy}/>
            <TextField label="De ce este necesară" multiline minRows={3} value={reason} onChange={(event) => setReason(event.target.value)} inputProps={{maxLength: 2000}} disabled={busy}/>
            <div><Button variant="contained" disabled={busy || parentLoading || Boolean(parentError) || name.trim().length < 3 || !reason.trim()} onClick={submit}>Trimite propunerea</Button></div>
        </Stack></Paper>}
    </Stack>;
}
