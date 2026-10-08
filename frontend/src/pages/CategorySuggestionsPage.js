import {useEffect, useState} from 'react';
import {Navigate} from 'react-router-dom';
import {Alert, Button, Chip, MenuItem, Pagination, Paper, Stack, TextField, Typography} from '@mui/material';
import {useAuth} from '../auth/AuthContext';
import {fetchChildren} from '../api/tree';
import {fetchCategorySuggestions, reviewCategory, suggestCategory} from '../api/contributions';

const STATUS = {PENDING: 'În așteptarea aprobării', APPROVED: 'Aprobată', REJECTED: 'Respinsă'};

export default function CategorySuggestionsPage() {
    const {user, isAdmin} = useAuth();
    const [data, setData] = useState({items: [], total: 0});
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
        setError(null);
        fetchCategorySuggestions(page).then((value) => { if (active) setData(value); }).catch((err) => { if (active) setError(err.message); });
        return () => { active = false; };
    }, [page, version, user]);
    useEffect(() => {
        if (!user) return;
        let active = true;
        setChildren([]); setParentLoading(true); setParentError(null);
        fetchChildren(parent?.id ?? null).then((value) => { if (active) setChildren(value); })
            .catch((err) => { if (active) setParentError(err.message); })
            .finally(() => { if (active) setParentLoading(false); });
        return () => { active = false; };
    }, [parent?.id, user]);
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
        <Paper sx={{p: 2}}><Stack spacing={2}>
            <Typography variant="h6">Propune o categorie nouă</Typography>
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
        </Stack></Paper>
        <Typography variant="h6">{isAdmin ? 'Propunerile utilizatorilor' : 'Propunerile tale'}</Typography>
        {!data.items.length && <Typography>Nu există propuneri.</Typography>}
        {data.items.map((item) => <Paper key={item.id} sx={{p: 2}}><Stack spacing={1}>
            <Typography variant="h6">{item.name}</Typography>
            <Chip sx={{alignSelf: 'flex-start'}} label={STATUS[item.status] ?? item.status}/>
            <Typography variant="body2">În: {item.parentName ?? 'Rădăcina arborelui'}{isAdmin ? ` · ${item.authorName}` : ''}</Typography>
            <Typography sx={{whiteSpace: 'pre-wrap', overflowWrap: 'anywhere'}}>{item.reason}</Typography>
            {isAdmin && item.status === 'PENDING' && <Stack direction="row" spacing={1}>
                <Button disabled={busy} onClick={() => act(() => reviewCategory(item.id, 'APPROVED'))}>Aprobă și creează categoria</Button>
                <Button disabled={busy} color="error" onClick={() => act(() => reviewCategory(item.id, 'REJECTED'))}>Respinge</Button>
            </Stack>}
        </Stack></Paper>)}
        {data.total > 20 && <Pagination page={page} count={Math.ceil(data.total / 20)} onChange={(_, value) => setPage(value)}/>}
    </Stack>;
}
