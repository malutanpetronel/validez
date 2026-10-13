import {useEffect, useState} from 'react';
import {Link as RouterLink, useLocation} from 'react-router-dom';
import {Alert, Button, Stack, Typography} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import {fetchSubjectBySlug} from '../api/subjects';
import SubjectPage from './SubjectPage';
import usePageMeta from '../seo/usePageMeta';

/** Subiect după slug (curent sau din istoric); SubjectPage aduce URL-ul la forma canonică. */
export default function SubjectBySlugPage({slug}) {
    const location = useLocation();
    const [id, setId] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        let active = true;
        setId(null);
        setError(null);
        fetchSubjectBySlug(slug).then((s) => { if (active) setId(s.id); }).catch((e) => { if (active) setError(e.message); });
        return () => { active = false; };
    }, [slug]);

    usePageMeta({title: 'Subiect negăsit', noindex: true}, Boolean(error));

    if (id) return <SubjectPage key={id} subjectId={id}/>;
    return <Stack spacing={2}>
        <div><Button component={RouterLink} to="/" state={location.state} startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>
        {error ? <Alert severity="warning">{error}</Alert> : <Typography color="text.secondary">Se încarcă…</Typography>}
    </Stack>;
}
