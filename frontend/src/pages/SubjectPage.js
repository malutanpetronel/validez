import {useEffect, useState} from 'react';
import {Link as RouterLink, useLocation, useParams} from 'react-router-dom';
import {Alert, Box, Button, Chip, Paper, Stack, Typography} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import EditIcon from '@mui/icons-material/Edit';
import {useAuth} from '../auth/AuthContext';
import {fetchSubject} from '../api/subjects';
import {canEditSubject, formatDate, STAGE_COLORS, STAGE_LABELS, TYPE_LABELS} from '../subjects/labels';
import SubjectForm from '../components/SubjectForm';
import SubjectEstimate from '../components/SubjectEstimate';
import SubjectPrivateNote from '../components/SubjectPrivateNote';

export default function SubjectPage({subjectId, embedded = false, onUpdated}) {
    const params = useParams();
    const location = useLocation();
    const id = subjectId ?? params.id;
    const {user} = useAuth();
    const [subject, setSubject] = useState(null);
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(false);
    const [noteVersion, setNoteVersion] = useState(0);

    useEffect(() => {
        setSubject(null);
        setError(null);
        let active = true;
        fetchSubject(id).then((s) => { if (active) setSubject(s); }).catch((e) => { if (active) setError(e.message); });
        return () => { active = false; };
    }, [id, user?.id]);

    return (
        <Stack spacing={2}>
            {!embedded && <div><Button component={RouterLink} to="/arbore" state={location.state} startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>}
            {error && <Alert severity="warning">{error}</Alert>}
            {!error && !subject && <Typography color="text.secondary">Se încarcă…</Typography>}
            {subject && (
                <Paper sx={{p: {xs: 2, sm: 3}}}>
                    <Stack spacing={1.5}>
                        <Stack direction="row" spacing={0.75} useFlexGap sx={{flexWrap: 'wrap'}}>
                            <Chip size="small" label={TYPE_LABELS[subject.type] ?? subject.type} variant="outlined"/>
                            <Chip size="small" label={STAGE_LABELS[subject.stage] ?? subject.stage} color={STAGE_COLORS[subject.stage] ?? 'default'}/>
                            {subject.visibility === 'HIDDEN' && <Chip size="small" label="Ascuns" color="error" variant="outlined"/>}
                        </Stack>
                        <Typography variant="h5" component="h1" sx={{wordBreak: 'break-word'}}>{subject.title}</Typography>
                        <Typography variant="body2" color="text.secondary">
                            În <Box component="strong" sx={{color: 'primary.main'}}>{subject.nodeName}</Box>
                            {' · de '}<Box component="span" sx={{color: 'primary.main', fontStyle: 'italic'}}>{subject.authorName}</Box>
                            {' · '}<Box component="span" sx={{color: 'primary.main'}}>{formatDate(subject.createdAt)}</Box>
                            {subject.updatedAt !== subject.createdAt && <> · modificat <Box component="span" sx={{color: 'primary.main'}}>{formatDate(subject.updatedAt)}</Box></>}
                        </Typography>
                        <Typography sx={{whiteSpace: 'pre-wrap', wordBreak: 'break-word'}}>{subject.description}</Typography>
                        <SubjectEstimate subject={subject}/>
                        {subject.type === 'PROPOSAL' && user?.id === subject.authorId && <SubjectPrivateNote key={`${subject.id}:${noteVersion}`} subjectId={subject.id}/>}
                        {canEditSubject(user, subject) && (
                            <div><Button variant="outlined" startIcon={<EditIcon/>} onClick={() => setEditing(true)}>Editează</Button></div>
                        )}
                    </Stack>
                </Paper>
            )}
            {editing && subject && (
                <SubjectForm subject={subject} onClose={() => setEditing(false)} onSaved={(s) => { setNoteVersion((value) => value + 1); setSubject(s); setEditing(false); onUpdated?.(s); }}/>
            )}
        </Stack>
    );
}
