import {useState} from 'react';
import {Link as RouterLink, useSearchParams} from 'react-router-dom';
import {Alert, Box, Button, Link, Paper, Stack, Typography} from '@mui/material';
import usePageMeta from '../seo/usePageMeta';
import {useAuth} from '../auth/AuthContext';
import {clearSession} from '../auth/session';
import {confirmAccountDeletion, requestAccountDeletion} from '../api/account';

const ERASED = [
    'numele afișat, adresa de email și parola; sesiunile se închid pe toate dispozitivele;',
    'acordul pentru vot, toate voturile tale și istoricul tău privat de vot (rezultatele subiectelor se actualizează imediat);',
    'notele personale ale propunerilor și propunerile de categorii încă neanalizate.',
];

function Frame({title, children}) {
    return <Paper sx={{p: {xs: 2, sm: 3}, maxWidth: 720, mx: 'auto', minWidth: 0}}><Stack spacing={2}>
        <Typography component="h1" variant="h4" sx={{overflowWrap: 'anywhere'}}>{title}</Typography>
        {children}
    </Stack></Paper>;
}

/** L.5c: „Șterge-mi contul” — explicația și trimiterea linkului de confirmare (valabil o oră). */
export default function DeleteAccountPage() {
    usePageMeta({title: 'Șterge-mi contul', noindex: true});
    const {user, isAdmin} = useAuth();
    const [sent, setSent] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const send = async () => {
        setBusy(true); setError(null);
        try { setSent(await requestAccountDeletion()); }
        catch (e) { setError(e.message); }
        finally { setBusy(false); }
    };

    if (!user) {
        return <Frame title="Șterge-mi contul">
            <Typography>Intră în cont ca să ceri ștergerea lui. Dacă nu mai poți intra, scrie-ne de pe adresa contului la{' '}
                <Link href="mailto:contact@webnou.ro?subject=%C8%98tergere%20cont%20Validez">contact@webnou.ro</Link>.</Typography>
            <div><Button variant="contained" component={RouterLink} to="/login" state={{from: '/sterge-cont'}}>Intră în cont</Button></div>
        </Frame>;
    }
    if (isAdmin) {
        return <Frame title="Șterge-mi contul">
            <Alert severity="info">Conturile de administrator se șterg doar la cerere scrisă, ca platforma să nu rămână fără administrare din greșeală. Scrie la contact@webnou.ro.</Alert>
        </Frame>;
    }
    return <Frame title="Șterge-mi contul">
        <Typography sx={{overflowWrap: 'anywhere'}}>Ștergerea contului <strong>{user.email}</strong> nu se poate anula. Se șterg:</Typography>
        <Box component="ul" sx={{m: 0, pl: 3}}>{ERASED.map((t) => <Typography component="li" key={t} sx={{overflowWrap: 'anywhere'}}>{t}</Typography>)}</Box>
        <Typography>Subiectele, fotografiile și linkurile publicate rămân pe platformă, pentru că au voturi și contribuții ale altor persoane, dar nu mai sunt legate de tine: autorul apare ca „Cont șters”. Dacă vrei să scoți o anumită contribuție, scoate-o sau scrie-ne înainte de ștergere.</Typography>
        <Typography>Îți trimitem pe email un link valabil o oră. Contul se șterge abia când deschizi linkul și confirmi. Detalii în <Link component={RouterLink} to="/privacy?section=stergere-cont">Confidențialitate</Link>.</Typography>
        {error && <Alert severity="error">{error}</Alert>}
        {sent ? <Alert severity="success" sx={{overflowWrap: 'anywhere'}}>Ți-am trimis linkul pe {user.email}. Este valabil {sent.expiresInMinutes ?? 60} de minute; dacă nu-l găsești, verifică și dosarul Spam.</Alert>
            : <div><Button variant="contained" color="error" onClick={send} disabled={busy}>{busy ? 'Se trimite…' : 'Trimite-mi linkul de ștergere'}</Button></div>}
    </Frame>;
}

/** L.5b: pagina deschisă din email — ștergerea se face doar la apăsarea butonului (nu la simpla deschidere a linkului). */
export function DeleteAccountConfirmPage() {
    usePageMeta({title: 'Confirmă ștergerea contului', noindex: true});
    const [params] = useSearchParams();
    const token = params.get('token') ?? '';
    const [done, setDone] = useState(false);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const confirm = async () => {
        setBusy(true); setError(null);
        try {
            await confirmAccountDeletion(token);
            clearSession();
            setDone(true);
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    };

    if (done) {
        return <Frame title="Contul a fost șters">
            <Alert severity="success">Contul tău Validez a fost șters. Ți-am trimis un ultim email de confirmare. Contribuțiile publicate rămân cu autorul „Cont șters”.</Alert>
            <div><Button component={RouterLink} to="/">Înapoi la Validez</Button></div>
        </Frame>;
    }
    return <Frame title="Confirmă ștergerea contului">
        {!token && <Alert severity="error">Linkul nu este complet. Deschide-l direct din email sau cere unul nou din „Șterge-mi contul”.</Alert>}
        <Typography>După confirmare se șterg datele contului, voturile și notele personale, iar contribuțiile publicate rămân cu autorul „Cont șters”. Operația nu se poate anula.</Typography>
        {error && <Alert severity="error" action={<Button color="inherit" size="small" component={RouterLink} to="/sterge-cont">Cere un link nou</Button>}>{error}</Alert>}
        <Stack direction={{xs: 'column', sm: 'row'}} spacing={1}>
            <Button variant="contained" color="error" onClick={confirm} disabled={busy || !token}>{busy ? 'Se șterge…' : 'Șterge definitiv contul'}</Button>
            <Button component={RouterLink} to="/" disabled={busy}>Renunță, păstrez contul</Button>
        </Stack>
    </Frame>;
}
