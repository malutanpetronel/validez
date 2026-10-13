import {useEffect, useState} from 'react';
import {Link as RouterLink, useLocation, useNavigate, useParams} from 'react-router-dom';
import {Alert, Box, Button, Chip, Paper, Stack, Typography} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import EditIcon from '@mui/icons-material/Edit';
import {useAuth} from '../auth/AuthContext';
import {fetchSubject} from '../api/subjects';
import {canEditSubject, formatDate, STAGE_COLORS, STAGE_LABELS, TYPE_LABELS} from '../subjects/labels';
import SubjectForm from '../components/SubjectForm';
import SubjectEstimate from '../components/SubjectEstimate';
import AuthorPublishing from '../components/AuthorPublishing';
import SubjectPrivateNote from '../components/SubjectPrivateNote';
import SubjectVotes from '../components/SubjectVotes';
import CategoryBreadcrumbs from '../components/CategoryBreadcrumbs';
import {fetchNodeByPath} from '../api/tree';
import usePageMeta, {excerpt} from '../seo/usePageMeta';
import {categoryPathOf} from '../seo/paths';

/** embedded: în panoul arborelui (fără buton înapoi, breadcrumbs, redirect canonic); meta: titlul/meta paginii vin de aici. */
export default function SubjectPage({subjectId, embedded = false, meta = false, onUpdated}) {
    const params = useParams();
    const location = useLocation();
    const navigate = useNavigate();
    const id = subjectId ?? params.id;
    const {user, isAdmin} = useAuth();
    const [subject, setSubject] = useState(null);
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(false);
    const [noteVersion, setNoteVersion] = useState(0);
    const [crumbs, setCrumbs] = useState([]);

    useEffect(() => {
        setSubject(null);
        setError(null);
        let active = true;
        fetchSubject(id).then((s) => { if (active) setSubject(s); }).catch((e) => { if (active) setError(e.message); });
        return () => { active = false; };
    }, [id, user?.id]);

    // ADR-0008 (doar ca pagină de sine stătătoare): URL-ul canonic — și după un titlu nou salvat aici —,
    // breadcrumbs ale categoriei, titlu/meta; subiectele nepublicate nu se indexează.
    const canonicalPath = embedded ? null : subject?.canonicalPath ?? null;
    useEffect(() => {
        if (canonicalPath && location.pathname !== canonicalPath) navigate(canonicalPath, {replace: true, state: location.state});
    }, [canonicalPath, location.pathname, location.state, navigate]);
    const categoryPath = categoryPathOf(canonicalPath);
    useEffect(() => {
        setCrumbs([]);
        if (!categoryPath) return undefined;
        let active = true;
        fetchNodeByPath(categoryPath).then((n) => { if (active) setCrumbs(n.breadcrumbs ?? []); }).catch(() => {});
        return () => { active = false; };
    }, [categoryPath]);
    usePageMeta(subject
        ? {title: subject.title, description: excerpt(subject.description), path: subject.canonicalPath ?? null, noindex: subject.visibility !== 'PUBLISHED'}
        : {title: error ? 'Subiect negăsit' : null, noindex: Boolean(error)}, !embedded || meta);

    return (
        <Stack spacing={2}>
            {!embedded && <div><Button component={RouterLink} to="/" state={location.state} startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>}
            {!embedded && <CategoryBreadcrumbs items={crumbs}/>}
            {error && <Alert severity="warning">{error}</Alert>}
            {!error && !subject && <Typography color="text.secondary">Se încarcă…</Typography>}
            {subject && (
                <Paper sx={{p: {xs: 2, sm: 3}}}>
                    <Stack spacing={1.5}>
                        <Stack direction="row" spacing={0.75} useFlexGap sx={{flexWrap: 'wrap'}}>
                            <Chip size="small" label={TYPE_LABELS[subject.type] ?? subject.type} variant="outlined"/>
                            <Chip size="small" label={STAGE_LABELS[subject.stage] ?? subject.stage} color={STAGE_COLORS[subject.stage] ?? 'default'}/>
                            {subject.visibility === 'HIDDEN' && <Chip size="small" label="Ascuns" color="error" variant="outlined"/>}
                            {subject.visibility === 'PENDING' && <Chip size="small" label="În așteptarea aprobării" color="warning" variant="outlined"/>}
                        </Stack>
                        <Typography variant="h5" component="h1" sx={{wordBreak: 'break-word'}}>{subject.title}</Typography>
                        <Typography variant="body2" color="text.secondary">
                            În <Box component="strong" sx={{color: 'primary.main'}}>{subject.nodeName}</Box>
                            {' · de '}<Box component="span" sx={{color: 'primary.main', fontStyle: 'italic'}}>{subject.authorName}</Box>
                            {' · '}<Box component="span" sx={{color: 'primary.main'}}>{formatDate(subject.createdAt)}</Box>
                            {subject.updatedAt !== subject.createdAt && <> · modificat <Box component="span" sx={{color: 'primary.main'}}>{formatDate(subject.updatedAt)}</Box></>}
                        </Typography>
                        <SubjectEstimate subject={subject}/>
                        <Typography sx={{whiteSpace: 'pre-wrap', wordBreak: 'break-word'}}>{subject.description}</Typography>
                        {isAdmin && <AuthorPublishing key={subject.authorId} authorId={subject.authorId}/>}
                        {subject.type === 'PROPOSAL' && user?.id === subject.authorId && <SubjectPrivateNote key={`${subject.id}:${noteVersion}`} subjectId={subject.id}/>}
                        {canEditSubject(user, subject) && (
                            <div><Button variant="outlined" startIcon={<EditIcon/>} onClick={() => setEditing(true)}>Editează</Button></div>
                        )}
                        {/* Revizie nouă după editare → reîncărcăm voturile (pot deveni nereconfirmate). */}
                        <SubjectVotes key={`${subject.id}:${subject.revisionNumber ?? 1}:${subject.stage}:${subject.visibility}`} subjectId={subject.id}/>
                    </Stack>
                </Paper>
            )}
            {editing && subject && (
                <SubjectForm subject={subject} onClose={() => setEditing(false)} onSaved={(s) => { setNoteVersion((value) => value + 1); setSubject(s); setEditing(false); onUpdated?.(s); }}/>
            )}
        </Stack>
    );
}
