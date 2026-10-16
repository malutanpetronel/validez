import {useState} from 'react';
import {Alert, Button, Stack, Typography} from '@mui/material';
import {administrativeText} from '../subjects/labels';
import {resolvePinClassification} from '../api/geo';

/**
 * ADR-0012 p.6 (G.2a/b): încadrarea administrativă a pinului, lângă confirmarea locului. La o încadrare neclară,
 * cine poate edita subiectul (autorul, adminii) alege una dintre variante; alegerea nu creează revizie,
 * iar mutarea pinului o anulează.
 */
export default function PinAdministrative({subjectId, location, canResolve, onResolved}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const info = administrativeText(location);
    if (!info) return null;
    const admin = location.administrative;
    const resolvable = canResolve && (admin.state === 'AMBIGUOUS' || admin.state === 'UNMATCHED') && admin.candidates?.length > 0;
    const choose = async (code) => {
        setBusy(true); setError(null);
        try {
            const r = await resolvePinClassification(subjectId, code);
            onResolved?.(r.administrative);
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    };
    return (
        <Stack spacing={0.75} data-testid="subject-administrative">
            <Typography variant="body2" color={info.determined ? 'text.primary' : 'warning.main'}
                        title={admin.provisional ? 'Limite administrative provizorii: lângă o limită, încadrarea poate fi neclară.' : undefined}>
                {info.text}{admin.state === 'RESOLVED_MANUALLY' ? ' (ales manual)' : ''}
            </Typography>
            {resolvable && (
                <Stack direction="row" spacing={1} useFlexGap sx={{flexWrap: 'wrap', alignItems: 'center'}}>
                    <Typography variant="body2" color="text.secondary">Locul e în:</Typography>
                    {admin.candidates.map((c) => (
                        <Button key={c.code} size="small" variant="outlined" disabled={busy} onClick={() => choose(c.code)}>
                            {c.name}
                        </Button>
                    ))}
                </Stack>
            )}
            {error && <Alert severity="error">{error}</Alert>}
        </Stack>
    );
}
