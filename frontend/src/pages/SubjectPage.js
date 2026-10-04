import {useEffect, useState} from 'react';
import {Link as RouterLink, useParams} from 'react-router-dom';
import {Alert, Button, Chip, Paper, Stack, Typography} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import EditIcon from '@mui/icons-material/Edit';
import {useAuth} from '../auth/AuthContext';
import {fetchSubject} from '../api/subjects';
import {canEditSubject, formatDate, STAGE_COLORS, STAGE_LABELS, TYPE_LABELS} from '../subjects/labels';
import SubjectForm from '../components/SubjectForm';

export default function SubjectPage() {
    const {id} = useParams();
    const {user} = useAuth();
    const [subject, setSubject] = useState(null);
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(false);

    useEffect(() => {
        setSubject(null);
        setError(null);
        fetchSubject(id).then(setSubject).catch((e) => setError(e.message));
    }, [id, user?.id]);

    return (
        <Stack spacing={2}>
            <div><Button component={RouterLink} to="/arbore" startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>
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
                            În <strong>{subject.nodeName}</strong> · de {subject.authorName} · {formatDate(subject.createdAt)}
                            {subject.updatedAt !== subject.createdAt && <> · modificat {formatDate(subject.updatedAt)}</>}
                        </Typography>
                        <Typography sx={{whiteSpace: 'pre-wrap', wordBreak: 'break-word'}}>{subject.description}</Typography>
                        {canEditSubject(user, subject) && (
                            <div><Button variant="outlined" startIcon={<EditIcon/>} onClick={() => setEditing(true)}>Editează</Button></div>
                        )}
                    </Stack>
                </Paper>
            )}
            {editing && subject && (
                <SubjectForm subject={subject} onClose={() => setEditing(false)} onSaved={(s) => { setSubject(s); setEditing(false); }}/>
            )}
        </Stack>
    );
}
