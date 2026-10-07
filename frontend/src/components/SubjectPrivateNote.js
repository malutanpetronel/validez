import {useEffect, useState} from 'react';
import {Alert, Button, Paper, Stack, Typography} from '@mui/material';
import {fetchSubjectNote} from '../api/subjects';

/** Mounted only for the subject author. No public-resource note data. */
export default function SubjectPrivateNote({subjectId}) {
    const [text, setText] = useState(null);
    const [error, setError] = useState(null);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        let active = true;
        setText(null);
        setError(null);
        fetchSubjectNote(subjectId).then((note) => { if (active) setText(note.technicalNotes); })
            .catch((err) => { if (active) setError(err.message); });
        return () => { active = false; };
    }, [subjectId, retry]);
    return <Paper variant="outlined" sx={{p: 2}}><Stack spacing={1}>
        <Typography variant="subtitle1" sx={{fontWeight: 600}}>Note personale — nu sunt publice</Typography>
        {error ? <Alert severity="error" action={<Button onClick={() => setRetry((value) => value + 1)}>Reîncearcă</Button>}>{error}</Alert>
            : <Typography color={text ? 'text.primary' : 'text.secondary'} sx={{whiteSpace: 'pre-wrap', overflowWrap: 'anywhere'}}>
                {text === null ? 'Se încarcă…' : text || 'Poți adăuga un memo tehnic din „Editează”.'}
            </Typography>}
    </Stack></Paper>;
}
