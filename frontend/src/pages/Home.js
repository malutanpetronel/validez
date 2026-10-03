import {useEffect, useState} from 'react';
import {Alert, Paper, Stack, Typography} from '@mui/material';
import {API_ENDPOINT} from '../config';

// Step 1.1: verificarea conexiunii frontend–API. Se inlocuieste cu ecranul arborelui (Step 1.5).
export default function Home() {
    const [api, setApi] = useState({state: 'loading'});

    useEffect(() => {
        let activ = true;
        fetch(API_ENDPOINT, {headers: {Accept: 'application/ld+json'}})
            .then((res) => activ && setApi({state: res.ok ? 'ok' : 'error', status: res.status}))
            .catch((err) => activ && setApi({state: 'error', message: err.message}));
        return () => { activ = false; };
    }, []);

    return (
        <Stack spacing={2}>
            <Typography variant="h4" component="h1" color="primary">
                Vezi. Verifică. Validează.
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
