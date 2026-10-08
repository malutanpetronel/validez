import {useState} from 'react';
import {Alert, Button, Stack, Typography} from '@mui/material';
import {publishingStatus, setPublishingRevoked} from '../api/contributions';

export default function AuthorPublishing({authorId}) {
    const [status, setStatus] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = async (revoked) => {
        setBusy(true); setError(null);
        try { setStatus(revoked === undefined ? await publishingStatus(authorId) : await setPublishingRevoked(authorId, revoked)); }
        catch (err) { setError(err.message); }
        finally { setBusy(false); }
    };
    return <Stack spacing={1}>
        {error && <Alert severity="error">{error}</Alert>}
        {!status && <div><Button disabled={busy} onClick={() => load()}>Drepturi de publicare ale autorului</Button></div>}
        {status && <>
            <Typography variant="body2">Contribuții aprobate: {status.approvedCount}. {status.canPublishDirectly ? 'Poate publica direct.' : 'Publicarea necesită aprobare.'}</Typography>
            {!status.isAdmin && <div><Button disabled={busy} color={status.revoked ? 'primary' : 'error'} onClick={() => load(!status.revoked)}>
                {status.revoked ? 'Ridică restricția de publicare' : 'Retrage dreptul de publicare directă'}
            </Button></div>}
        </>}
    </Stack>;
}
