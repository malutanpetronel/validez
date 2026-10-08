import {useEffect, useState} from 'react';
import {Link as RouterLink, Navigate} from 'react-router-dom';
import {Alert, Button, Pagination, Paper, Stack, Typography} from '@mui/material';
import {useAuth} from '../auth/AuthContext';
import {fetchSubjects, updateSubject} from '../api/subjects';

export default function ModerationPage() {
    const {isAdmin} = useAuth();
    const [data, setData] = useState({items: [], total: 0});
    const [page, setPage] = useState(1);
    const [version, setVersion] = useState(0);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        if (!isAdmin) return;
        let active = true;
        setError(null); setLoading(true);
        fetchSubjects({visibility: 'PENDING', page}).then((value) => { if (active) setData(value); })
            .catch((err) => { if (active) setError(err.message); })
            .finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [isAdmin, page, version]);
    if (!isAdmin) return <Navigate to="/" replace/>;
    const review = async (id, visibility) => {
        setBusy(true); setError(null);
        try { await updateSubject(id, {visibility}); setPage(1); setVersion((value) => value + 1); }
        catch (err) { setError(err.message); }
        finally { setBusy(false); }
    };
    return <Stack spacing={2}>
        <Typography variant="h4" component="h1">Moderare subiecte</Typography>
        {error && <Alert severity="error">{error}</Alert>}
        {loading && <Typography>Se încarcă…</Typography>}
        {!loading && !data.items.length && <Typography>Nu există subiecte în așteptarea aprobării.</Typography>}
        {data.items.map((subject) => <Paper key={subject.id} sx={{p: 2}}><Stack spacing={1}>
            <Typography variant="h6">{subject.title}</Typography>
            <Typography variant="body2">{subject.authorName} · {subject.nodeName}</Typography>
            <Typography sx={{whiteSpace: 'pre-wrap', overflowWrap: 'anywhere'}}>{subject.description}</Typography>
            <Stack direction="row" spacing={1} useFlexGap sx={{flexWrap: 'wrap'}}>
                <Button component={RouterLink} to={`/subiecte/${subject.id}`}>Detalii și drepturi autor</Button>
                <Button disabled={busy || loading} variant="contained" onClick={() => review(subject.id, 'PUBLISHED')}>Aprobă și publică</Button>
                <Button disabled={busy || loading} color="error" onClick={() => review(subject.id, 'HIDDEN')}>Respinge</Button>
            </Stack>
        </Stack></Paper>)}
        {data.total > 20 && <Pagination page={page} count={Math.ceil(data.total / 20)} onChange={(_, value) => setPage(value)}/>}
    </Stack>;
}
