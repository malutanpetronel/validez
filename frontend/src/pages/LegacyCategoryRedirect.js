import {useEffect, useRef, useState} from 'react';
import {Link as RouterLink, useLocation, useNavigate, useParams} from 'react-router-dom';
import {Alert, Button, Stack, Typography} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import {fetchNode} from '../api/tree';

/** Vechiul `/categorii/{id}/subiecte` → `/categorie/{cale}` (ADR-0008), păstrând starea de revenire în arbore. */
export default function LegacyCategoryRedirect() {
    const {nodeId} = useParams();
    const location = useLocation();
    const navigate = useNavigate();
    const returnState = useRef(location.state);
    const [error, setError] = useState(null);

    useEffect(() => {
        let active = true;
        fetchNode(nodeId)
            .then((n) => { if (active) navigate(`/categorie/${n.slugPath}`, {replace: true, state: returnState.current}); })
            .catch((e) => { if (active) setError(e.status === 404 ? 'Categoria nu există.' : e.message); });
        return () => { active = false; };
    }, [nodeId, navigate]);

    return <Stack spacing={2}>
        <div><Button component={RouterLink} to="/" state={location.state} startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>
        {error ? <Alert severity="warning">{error}</Alert> : <Typography color="text.secondary">Se încarcă…</Typography>}
    </Stack>;
}
