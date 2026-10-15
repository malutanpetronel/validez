import {useEffect, useState} from 'react';
import {Link as RouterLink, Navigate} from 'react-router-dom';
import {Alert, Box, Button, Link, Pagination, Paper, Stack, Typography} from '@mui/material';
import {useAuth} from '../auth/AuthContext';
import usePageMeta from '../seo/usePageMeta';
import {fetchSubjects, updateSubject} from '../api/subjects';
import {approveMedia, fetchPendingMedia, removeMedia} from '../api/media';
import MediaImage from '../components/MediaImage';
import {formatDate} from '../subjects/labels';

/** ADR-0011 p.12: fotografiile și linkurile autorilor sub moderare; subiectul lor rămâne public. */
function PendingMedia() {
    const [items, setItems] = useState(null);
    const [version, setVersion] = useState(0);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        let active = true;
        fetchPendingMedia().then((d) => { if (active) setItems(d.items); }).catch((e) => { if (active) setError(e.message); });
        return () => { active = false; };
    }, [version]);
    const review = async (task) => {
        setBusy(true); setError(null);
        try { await task(); setVersion((v) => v + 1); } catch (e) { setError(e.message); } finally { setBusy(false); }
    };
    return <Stack spacing={2}>
        <Typography variant="h5" component="h2">Fotografii și linkuri în așteptare</Typography>
        {error && <Alert severity="error">{error}</Alert>}
        {!items && !error && <Typography>Se încarcă…</Typography>}
        {items && !items.length && <Typography>Nu există fotografii sau linkuri în așteptare.</Typography>}
        {items?.map((m) => <Paper key={m.id} sx={{p: 2}}><Stack direction={{xs: 'column', sm: 'row'}} spacing={2}>
            {m.kind === 'PHOTO' && <MediaImage item={m} variant="display" isPublic={false} alt="Fotografie în așteptare"
                                               sx={{width: {xs: '100%', sm: 220}, maxHeight: 260, objectFit: 'contain', borderRadius: 1}}/>}
            <Stack spacing={1} sx={{flex: 1, minWidth: 0}}>
                {m.kind === 'LINK' && <Link href={m.url} target="_blank" rel="nofollow ugc noopener noreferrer" sx={{overflowWrap: 'anywhere'}}>{m.label ? `${m.label} — ${m.url}` : m.url}</Link>}
                <Typography variant="body2">La <RouterLink to={m.subjectPath ?? `/subiecte/${m.subjectId}`}>{m.subjectTitle}</RouterLink></Typography>
                <Typography variant="body2" color="text.secondary">{m.uploadedByName ?? 'cont șters'} · {formatDate(m.createdAt)}</Typography>
                <Box><Stack direction="row" spacing={1} useFlexGap sx={{flexWrap: 'wrap'}}>
                    <Button disabled={busy} variant="contained" onClick={() => review(() => approveMedia(m.id))}>Aprobă</Button>
                    <Button disabled={busy} color="error" onClick={() => review(() => removeMedia(m.id))}>Respinge (scoate)</Button>
                </Stack></Box>
            </Stack>
        </Stack></Paper>)}
    </Stack>;
}

export default function ModerationPage() {
    usePageMeta({title: 'Moderare subiecte', noindex: true});
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
                <Button component={RouterLink} to={subject.canonicalPath ?? `/subiecte/${subject.id}`}>Detalii și drepturi autor</Button>
                <Button disabled={busy || loading} variant="contained" onClick={() => review(subject.id, 'PUBLISHED')}>Aprobă și publică</Button>
                <Button disabled={busy || loading} color="error" onClick={() => review(subject.id, 'HIDDEN')}>Respinge</Button>
            </Stack>
        </Stack></Paper>)}
        {data.total > 20 && <Pagination page={page} count={Math.ceil(data.total / 20)} onChange={(_, value) => setPage(value)}/>}
        <PendingMedia/>
    </Stack>;
}
