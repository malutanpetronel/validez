import {useEffect, useState} from 'react';
import {Alert, Paper, Stack, Typography} from '@mui/material';
import {API_ENDPOINT} from '../config';
import {Navigate} from 'react-router-dom';
import {useAuth} from '../auth/AuthContext';

export default function CheckApiPage() {
    const {isAdmin} = useAuth();
    const [api, setApi] = useState({state: 'loading'});

    useEffect(() => {
        if (!isAdmin) return;
        let activ = true;
        fetch(API_ENDPOINT, {headers: {Accept: 'application/ld+json'}})
            .then((res) => activ && setApi({state: res.ok ? 'ok' : 'error', status: res.status}))
            .catch((err) => activ && setApi({state: 'error', message: err.message}));
        return () => { activ = false; };
    }, [isAdmin]);

    if (!isAdmin) return <Navigate to="/" replace/>;

    return (
        <Stack spacing={2}>
            <Typography variant="h4" component="h1" color="primary">
                Check API
            </Typography>
            <Paper sx={{p: 2}}>
                <Typography variant="subtitle2" gutterBottom>API: {API_ENDPOINT}</Typography>
                {api.state === 'loading' && <Alert severity="info">Se verifică conexiunea…</Alert>}
                {api.state === 'ok' && <Alert severity="success">API disponibil ({api.status}).</Alert>}
                {api.state === 'error' && (
                    <Alert severity="warning">
                        API indisponibil{api.status ? ` (${api.status})` : ''}{api.message ? `: ${api.message}` : ''}.
                    </Alert>
                )}
            </Paper>
        </Stack>
    );
}
